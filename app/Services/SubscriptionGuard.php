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
 * 聚合站、或被恶意客户端 / 配置聚合器收集后，**一条链接被大量不同网络长期拉取**。
 *
 * 这里在订阅成功鉴权后记录来源网络，当一条订阅在滚动窗口内被异常多的不同网络拉取时打
 * 告警日志（并抛一个插件钩子，便于接管理员通知），便于及时重置该用户的 token。
 *
 * 只做观测 + 告警，**不拦截订阅本身**：不影响任何正常用户，也不碰下单 / 续费 / 付款。
 * Redis 抖动绝不冒泡到订阅响应。
 */
class SubscriptionGuard
{
    /** sub_src:{userId} -> SET<network>，一个用户近期拉取订阅的来源网络集合。 */
    private const SET_PREFIX = 'sub_src:';

    /** sub_src_alerted:{userId}，去重键：每用户每窗口只告警一次。 */
    private const ALERT_PREFIX = 'sub_src_alerted:';

    /** 单用户最多跟踪的网络数，给内存封顶（跨阈值时已告警，之后不必再累积）。 */
    private const MAX_TRACKED = 1024;

    /** 默认滚动窗口 24h。 */
    public const DEFAULT_WINDOW = 86400;

    /** 默认阈值：12 个不同网络。正常用户「家庭 + 移动 + 偶尔异地」也到不了。 */
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
     * 记录一次订阅拉取的来源网络，必要时告警。
     *
     * @param int    $userId 订阅所属用户 id
     * @param string $ip     真实客户端 IP（依赖 TrustProxies 正确识别，CDN 场景务必配置）
     */
    public function record(int $userId, string $ip): void
    {
        $threshold = (int) admin_setting('subscribe_shared_threshold', self::DEFAULT_THRESHOLD);
        if ($threshold <= 0 || $userId <= 0) {
            return; // 阈值 0 = 关闭检测
        }

        $window = (int) admin_setting('subscribe_shared_window', self::DEFAULT_WINDOW);
        if ($window <= 0) {
            $window = self::DEFAULT_WINDOW;
        }

        try {
            $setKey = self::SET_PREFIX . $userId;
            $count = (int) Redis::scard($setKey);

            // 已跟踪到上限则不再写入（跨阈值时的告警早已触发过）。
            if ($count >= self::MAX_TRACKED) {
                return;
            }

            $added = (int) Redis::sadd($setKey, self::networkKey($ip));
            if ($added <= 0) {
                return; // 该网络本窗口已记录过，不是新增
            }

            Redis::expire($setKey, $window);
            $count += 1;

            if ($count >= $threshold) {
                $this->maybeAlert($userId, $count, $window);
            }
        } catch (\Throwable $e) {
            // Redis 抖动不能影响订阅可用性。
            Log::debug('[subscribe] guard skipped', ['error' => $e->getMessage()]);
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

        Log::warning('[subscribe] shared-subscription suspected', [
            'user_id' => $userId,
            'distinct_networks' => $count,
            'window_seconds' => $window,
            'hint' => 'subscription URL likely leaked/shared; consider resetting the user token',
        ]);

        HookManager::call('subscribe.shared_suspected', [
            'user_id' => $userId,
            'distinct_networks' => $count,
            'window_seconds' => $window,
        ]);
    }
}
