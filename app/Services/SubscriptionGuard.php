<?php

namespace App\Services;

use App\Services\Plugin\HookManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * 订阅滥用防护：共享 / 泄露订阅检测。
 *
 * 订阅 token 本身是 128-bit CSPRNG（{@see \App\Utils\Helper::guid()}），搜索空间 2^128，
 * 不可爆破；Cloudflare 日志里也没有枚举迹象。真正的风险是订阅 URL 被用户自己转发、贴到
 * 群里 / 聚合站、或被恶意客户端 / 配置聚合器收集后，**一个账号被大量不同网络长期使用**。
 *
 * 数据来源是节点上报的在线 IP（{@see DeviceStateService::syncNodeDevices()}），也就是真实
 * 连上节点的客户端地址。**不用订阅拉取 IP**：客户端常走代理更新订阅，拉取 IP 是我们自己
 * 节点的出口（多国家轮换），一个正常用户一天就能凑出十几个国家，按拉取 IP 计数会大量误报。
 *
 * 一个账号在滚动窗口内从异常多的不同网络连过节点时打告警日志（并抛一个插件钩子，便于接
 * 管理员通知），便于及时重置该用户的 token。
 *
 * 只做观测 + 告警，**不拦截任何连接**：不影响设备计数，也不碰下单 / 续费 / 付款。
 * Redis 抖动绝不冒泡到节点上报。
 */
class SubscriptionGuard
{
    /** sub_shared_net:{userId} -> ZSET<network, 最近一次出现的时间戳>。 */
    private const ZSET_PREFIX = 'sub_shared_net:';

    /** sub_shared_alerted:{userId}，去重键：每用户每窗口只告警一次。 */
    private const ALERT_PREFIX = 'sub_shared_alerted:';

    /** 单用户最多跟踪的网络数，给内存封顶（超出时丢掉最久没出现的）。 */
    private const MAX_TRACKED = 256;

    /** 告警里附带的最近网络个数，便于人工判断是「一个人到处跑」还是「到处都有人在用」。 */
    private const ALERT_SAMPLE = 20;

    /** 默认滚动窗口 24h。 */
    public const DEFAULT_WINDOW = 86400;

    /**
     * 默认阈值：12 个不同网络。2026-10-13 按国内直连的订阅拉取统计，约 97% 的订阅一天
     * 不超过 3 个网络，12 个以上的约千分之一。
     */
    public const DEFAULT_THRESHOLD = 12;

    /**
     * 把 IP 收敛到「网络」粒度，避免单个用户的动态 IP / 移动漫游造成误报：
     * IPv4 → /24，IPv6 → /48。一个真实用户通常落在同一 ISP 的少数网络里，
     * 被共享 / 转卖的订阅才会横跨几十个互不相关的网络。
     */
    public static function networkKey(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return 'unknown';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            return 'v4:' . $parts[0] . '.' . $parts[1] . '.' . $parts[2];
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin = inet_pton($ip);
            if ($bin === false) {
                return 'v6:' . strtolower($ip);
            }
            // 32 hex = 完整 8 组，取前 12 位十六进制 = 前 48 位（/48）
            return 'v6:' . substr(bin2hex($bin), 0, 12);
        }

        return 'other:' . $ip;
    }

    /**
     * 一个用户本次上报的 IP → 去重后的网络列表。不是合法 IP 的串直接忽略，不计数。
     *
     * @return string[]
     */
    public static function networksOf(array $ips): array
    {
        $networks = [];
        foreach ($ips as $ip) {
            if (!is_string($ip) || filter_var(trim($ip), FILTER_VALIDATE_IP) === false) {
                continue;
            }
            $networks[self::networkKey($ip)] = true;
        }

        return array_keys($networks);
    }

    /**
     * 记录一个节点的在线快照，必要时告警。
     *
     * @param array<int, string[]> $devices userId => 已去掉端口的在线 IP
     */
    public function recordNodeSnapshot(array $devices): void
    {
        try {
            $this->record($devices);
        } catch (\Throwable $e) {
            // 配置读取 / Redis 抖动都不能影响节点上报。
            Log::debug('[subscribe] shared guard skipped', ['error' => $e->getMessage()]);
        }
    }

    private function record(array $devices): void
    {
        $threshold = (int) admin_setting('subscribe_shared_threshold', self::DEFAULT_THRESHOLD);
        if ($threshold <= 0) {
            return; // 阈值 0 = 关闭检测
        }

        $window = (int) admin_setting('subscribe_shared_window', self::DEFAULT_WINDOW);
        if ($window <= 0) {
            $window = self::DEFAULT_WINDOW;
        }

        $batch = [];
        foreach ($devices as $userId => $ips) {
            if ((int) $userId <= 0 || !is_array($ips)) {
                continue;
            }
            $networks = self::networksOf($ips);
            if (!empty($networks)) {
                $batch[(int) $userId] = $networks;
            }
        }
        if (empty($batch)) {
            return;
        }

        $now = time();

        // 第一轮：刷新每个网络「最近一次出现」的时间。ZADD 返回新增成员数，绝大多数
        // 上报都是老网络（返回 0），只有冒出新网络的用户才需要再数一次。
        $replies = Redis::pipeline(function ($pipe) use ($batch, $now, $window) {
            foreach ($batch as $userId => $networks) {
                $args = [];
                foreach ($networks as $network) {
                    $args[] = $now;
                    $args[] = $network;
                }
                $pipe->zadd(self::ZSET_PREFIX . $userId, ...$args);
                $pipe->expire(self::ZSET_PREFIX . $userId, $window);
            }
        });

        $grown = [];
        foreach (array_keys($batch) as $i => $userId) {
            if ((int) ($replies[$i * 2] ?? 0) > 0) {
                $grown[] = $userId;
            }
        }
        if (empty($grown)) {
            return;
        }

        // 第二轮：只对冒出新网络的用户，剔除窗口外没再出现的网络、封顶，再数。
        $cutoff = $now - $window;
        $replies = Redis::pipeline(function ($pipe) use ($grown, $cutoff) {
            foreach ($grown as $userId) {
                $key = self::ZSET_PREFIX . $userId;
                $pipe->zremrangebyscore($key, '-inf', (string) $cutoff);
                $pipe->zremrangebyrank($key, 0, -(self::MAX_TRACKED + 1));
                $pipe->zcard($key);
            }
        });

        foreach ($grown as $i => $userId) {
            $count = (int) ($replies[$i * 3 + 2] ?? 0);
            if ($count >= $threshold) {
                $this->maybeAlert($userId, $count, $window);
            }
        }
    }

    private function maybeAlert(int $userId, int $count, int $window): void
    {
        $alertKey = self::ALERT_PREFIX . $userId;

        // setnx + ttl：每用户每窗口只告警一次，避免刷屏。
        if ((int) Redis::setnx($alertKey, 1) !== 1) {
            return;
        }
        Redis::expire($alertKey, $window);

        $recent = Redis::zrevrange(self::ZSET_PREFIX . $userId, 0, self::ALERT_SAMPLE - 1);

        Log::warning('[subscribe] shared-subscription suspected', [
            'user_id' => $userId,
            'distinct_networks' => $count,
            'window_seconds' => $window,
            'recent_networks' => is_array($recent) ? array_values($recent) : [],
            'hint' => 'account used from many unrelated networks; subscription URL likely leaked/shared, consider resetting the user token',
        ]);

        HookManager::call('subscribe.shared_suspected', [
            'user_id' => $userId,
            'distinct_networks' => $count,
            'window_seconds' => $window,
        ]);
    }
}
