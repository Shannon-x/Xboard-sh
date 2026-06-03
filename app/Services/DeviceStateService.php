<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;

class DeviceStateService
{
    private const PREFIX = 'user_devices:';
    private const NODE_INDEX_PREFIX = 'user_devices:node:'; // 反向索引：{nodeId} -> SET<userId>
    private const TTL = 300;                     // device state ttl
    private const DB_THROTTLE = 10;             // update db throttle

    /**
     * 批量设置设备
     * 用于 HTTP /alive 和 WebSocket report.devices
     */
    public function setDevices(int $userId, int $nodeId, array $ips): void
    {
        $key = self::PREFIX . $userId;
        $timestamp = time();

        $this->removeNodeDevices($nodeId, $userId);

        // Normalize: strip port suffix and deduplicate
        $ips = array_values(array_unique(array_map([self::class, 'normalizeIP'], $ips)));

        if (!empty($ips)) {
            $fields = [];
            foreach ($ips as $ip) {
                $fields["{$nodeId}:{$ip}"] = $timestamp;
            }
            Redis::hMset($key, $fields);
            Redis::expire($key, self::TTL);
            // 反向索引：记录"此节点持有此 user 的设备记录"，getNodeDevices 用它替代 KEYS
            Redis::sadd(self::NODE_INDEX_PREFIX . $nodeId, $userId);
            // 反向索引也要有 TTL，避免节点离线后残留；用 TTL+60 给写路径一点容差
            Redis::expire(self::NODE_INDEX_PREFIX . $nodeId, self::TTL + 60);
        }

        $this->notifyUpdate($userId);
    }

    /**
     * 获取某节点的所有设备数据
     * 返回: {userId: [ip1, ip2, ...], ...}
     *
     * 历史实现用 Redis::keys('user_devices:*') 是 O(全 keyspace) 阻塞命令，
     * Redis 与 Sanctum/Cache/队列共享时会拖垮其它请求。改为反向索引集合：
     *   SMEMBERS user_devices:node:{nodeId} → 拿到此节点关联的 user id 列表
     *   再 pipeline HGETALL 每个 user 的 hash
     */
    public function getNodeDevices(int $nodeId): array
    {
        $uids = Redis::smembers(self::NODE_INDEX_PREFIX . $nodeId);
        if (empty($uids)) {
            return [];
        }

        $prefix = "{$nodeId}:";
        $result = [];
        // pipeline 批量 HGETALL，避免 N 次 RTT
        $hashes = Redis::pipeline(function ($pipe) use ($uids) {
            foreach ($uids as $uid) {
                $pipe->hgetall(self::PREFIX . (int) $uid);
            }
        });

        foreach ($uids as $i => $uid) {
            $data = $hashes[$i] ?? [];
            if (!is_array($data) || empty($data)) {
                // hash 已过期，从反向索引里清掉
                Redis::srem(self::NODE_INDEX_PREFIX . $nodeId, $uid);
                continue;
            }
            $uidInt = (int) $uid;
            foreach ($data as $field => $timestamp) {
                if (str_starts_with($field, $prefix)) {
                    $ip = substr($field, strlen($prefix));
                    $result[$uidInt][] = $ip;
                }
            }
        }
        return $result;
    }

    /**
     * 删除某节点某用户的设备
     */
    public function removeNodeDevices(int $nodeId, int $userId): void
    {
        $key = self::PREFIX . $userId;
        $prefix = "{$nodeId}:";

        $fieldsToDel = [];
        foreach (Redis::hkeys($key) as $field) {
            if (str_starts_with($field, $prefix)) {
                $fieldsToDel[] = $field;
            }
        }
        if (!empty($fieldsToDel)) {
            // 一次 HDEL 多 field，避免 N 次 RTT
            Redis::hdel($key, ...$fieldsToDel);
        }
        // 反向索引同步：该用户在此节点已无设备
        Redis::srem(self::NODE_INDEX_PREFIX . $nodeId, $userId);
    }

    /**
     * 清除节点所有设备数据（用于节点断开连接）
     */
    public function clearAllNodeDevices(int $nodeId): array
    {
        $oldDevices = $this->getNodeDevices($nodeId);
        $prefix = "{$nodeId}:";

        foreach ($oldDevices as $userId => $ips) {
            $key = self::PREFIX . $userId;
            $fieldsToDel = [];
            foreach (Redis::hkeys($key) as $field) {
                if (str_starts_with($field, $prefix)) {
                    $fieldsToDel[] = $field;
                }
            }
            if (!empty($fieldsToDel)) {
                Redis::hdel($key, ...$fieldsToDel);
            }
            $this->notifyUpdate($userId);
        }
        // 整体清掉反向索引集合
        Redis::del(self::NODE_INDEX_PREFIX . $nodeId);

        return array_keys($oldDevices);
    }

    /**
     * Backward-compatible wrapper for NodeWebSocketServer.php
     * clearNodeDevices($nodeId) → clearAllNodeDevices($nodeId)
     * clearNodeDevices($nodeId, $userId) → removeNodeDevices($nodeId, $userId)
     */
    public function clearNodeDevices(int $nodeId, ?int $userId = null): void
    {
        if ($userId !== null) {
            $this->removeNodeDevices($nodeId, $userId);
        } else {
            $this->clearAllNodeDevices($nodeId);
        }
    }

    /**
     * get user device count (filter expired data)
     *
     * Respects admin_setting('device_limit_mode'):
     *   0 = strict:  count connections per-node, then sum (same IP on different nodes = 2)
     *   1 = loose:   deduplicate by IP across all nodes (same IP on different nodes = 1)
     */
    public function getDeviceCount(int $userId): int
    {
        $data = Redis::hgetall(self::PREFIX . $userId);
        $now = time();
        $mode = (int) admin_setting('device_limit_mode', 0);

        if ($mode === 1 || $mode === 2) {
            // Loose mode (1): deduplicate by exact IP across all nodes
            // Subnet mode (2): deduplicate by IP subnet (/24 or /64) across all nodes
            $ips = [];
            foreach ($data as $field => $timestamp) {
                if ($now - $timestamp <= self::TTL) {
                    $ip = substr($field, strpos($field, ':') + 1);
                    if ($mode === 2) {
                        $ip = self::getSubnet($ip);
                    }
                    $ips[] = $ip;
                }
            }
            return count(array_unique($ips));
        }

        // Strict mode (default): count all active entries (each node×IP pair counts separately)
        $count = 0;
        foreach ($data as $field => $timestamp) {
            if ($now - $timestamp <= self::TTL) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * get user device count (for alivelist interface)
     */
    public function getAliveList(Collection $users): array
    {
        if ($users->isEmpty()) {
            return [];
        }

        $result = [];
        foreach ($users as $user) {
            $count = $this->getDeviceCount($user->id);
            if ($count > 0) {
                $result[$user->id] = $count;
            }
        }

        return $result;
    }

    /**
     * get devices of multiple users (for sync.devices, filter expired data)
     */
    public function getUsersDevices(array $userIds): array
    {
        $result = [];
        $now = time();
        foreach ($userIds as $userId) {
            $data = Redis::hgetall(self::PREFIX . $userId);
            if (!empty($data)) {
                $ips = [];
                foreach ($data as $field => $timestamp) {
                    if ($now - $timestamp <= self::TTL) {
                        $ips[] = substr($field, strpos($field, ':') + 1);
                    }
                }
                if (!empty($ips)) {
                    $result[$userId] = array_unique($ips);
                }
            }
        }

        return $result;
    }

    /**
     * Strip port from IP address: "1.2.3.4:12345" → "1.2.3.4", "[::1]:443" → "::1"
     */
    private static function normalizeIP(string $ip): string
    {
        // [IPv6]:port
        if (preg_match('/^\[(.+)\]:\d+$/', $ip, $m)) {
            return $m[1];
        }
        // IPv4:port
        if (preg_match('/^(\d+\.\d+\.\d+\.\d+):\d+$/', $ip, $m)) {
            return $m[1];
        }
        return $ip;
    }

    /**
     * Get /24 subnet for IPv4 or /64 subnet for IPv6
     */
    public static function getSubnet(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            if (count($parts) === 4) {
                return "{$parts[0]}.{$parts[1]}.{$parts[2]}.0/24";
            }
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            if ($packed !== false) {
                $hex = bin2hex(substr($packed, 0, 8));
                return sprintf('%x:%x:%x:%x::/64',
                    hexdec(substr($hex, 0, 4)),
                    hexdec(substr($hex, 4, 4)),
                    hexdec(substr($hex, 8, 4)),
                    hexdec(substr($hex, 12, 4))
                );
            }
        }
        return $ip;
    }

    /**
     * notify update (throttle control)
     *
     * 历史实现把 setnx 节流块整段注释掉了，每次 alive/WebSocket report 都同步 UPDATE v2_user。
     * 100 节点 × 5k 用户 × 60s 心跳 ≈ 500k UPDATE/min 全部命中 v2_user 主键写锁。
     *
     * 这里恢复 self::DB_THROTTLE 秒的节流。online_count / last_online_at 不是秒级精度字段，
     * 10s 滑窗对前端展示无可观察差异，但 SQL 写入量降一个数量级。
     */
    public function notifyUpdate(int $userId): void
    {
        $dbThrottleKey = "device:db_throttle:{$userId}";

        // setnx 返回 1 = 第一次拿锁；返回 0 = 节流窗内已经被人 update 过了，跳过
        if (!Redis::setnx($dbThrottleKey, 1)) {
            return;
        }
        Redis::expire($dbThrottleKey, self::DB_THROTTLE);

        User::query()
            ->whereKey($userId)
            ->update([
                'online_count' => $this->getDeviceCount($userId),
                'last_online_at' => now(),
            ]);
    }
}
