<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;

class DeviceStateService
{
    private const PREFIX = 'user_devices:';
    private const TTL = 300;                     // device state ttl
    private const DB_THROTTLE = 10;             // update db throttle

    /**
     * 移除 Redis key 的前缀
     */
    private function removeRedisPrefix(string $key): string
    {
        $prefix = config('database.redis.options.prefix', '');
        return $prefix ? substr($key, strlen($prefix)) : $key;
    }

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
        }

        $this->notifyUpdate($userId);
    }

    /**
     * 获取某节点的所有设备数据
     * 返回: {userId: [ip1, ip2, ...], ...}
     */
    public function getNodeDevices(int $nodeId): array
    {
        $keys = Redis::keys(self::PREFIX . '*');
        $prefix = "{$nodeId}:";
        $result = [];
        foreach ($keys as $key) {
            $actualKey = $this->removeRedisPrefix($key);
            $uid = (int) substr($actualKey, strlen(self::PREFIX));
            $data = Redis::hgetall($actualKey);
            foreach ($data as $field => $timestamp) {
                if (str_starts_with($field, $prefix)) {
                    $ip = substr($field, strlen($prefix));
                    $result[$uid][] = $ip;
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

        foreach (Redis::hkeys($key) as $field) {
            if (str_starts_with($field, $prefix)) {
                Redis::hdel($key, $field);
            }
        }
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
            foreach (Redis::hkeys($key) as $field) {
                if (str_starts_with($field, $prefix)) {
                    Redis::hdel($key, $field);
                }
            }
            $this->notifyUpdate($userId);
        }

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
     */
    public function notifyUpdate(int $userId): void
    {
        $dbThrottleKey = "device:db_throttle:{$userId}";

        // if (Redis::setnx($dbThrottleKey, 1)) {
        //     Redis::expire($dbThrottleKey, self::DB_THROTTLE);

            User::query()
                ->whereKey($userId)
                ->update([
                    'online_count' => $this->getDeviceCount($userId),
                    'last_online_at' => now(),
                ]);
        // }
    }
}
