<?php

namespace App\Services\AccessLog;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Redis 实现：每个 (天, 节点) 一个 hash，字段 "{uid}:r|u|d|m"（连接数、上行、下行、小时位图）。
 * 一个批次的「去重标记 + 全部累加」在一段 Lua 里完成，要么都做要么都不做。
 */
final class RedisSubtotalBuffer implements SubtotalBuffer
{
    private const PENDING = 'access_log:pending';
    private const RETRY = 'access_log:retry';
    /** 两个 drain 同时交会把 retry 里的同一份各加一次，所以交之前先拿这把锁 */
    private const LOCK = 'access_log:drain_lock';
    private const LOCK_TTL = 300;
    private const RELEASE = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
  return redis.call('DEL', KEYS[1])
end
return 0
LUA;
    /** 节点最多重传 8 天前的批次（见 AccessLogBatch::parse），标记留 9 天 */
    private const SEEN_TTL = 9 * 86400;
    private const ADD = <<<'LUA'
if not redis.call('SET', KEYS[1], '1', 'NX', 'EX', ARGV[1]) then
  return 0
end
local mask = tonumber(ARGV[2])
for i = 4, #ARGV, 4 do
  local uid = ARGV[i]
  redis.call('HINCRBY', KEYS[2], uid .. ':r', ARGV[i + 1])
  redis.call('HINCRBY', KEYS[2], uid .. ':u', ARGV[i + 2])
  redis.call('HINCRBY', KEYS[2], uid .. ':d', ARGV[i + 3])
  local old = tonumber(redis.call('HGET', KEYS[2], uid .. ':m') or '0')
  redis.call('HSET', KEYS[2], uid .. ':m', bit.bor(old, mask))
end
redis.call('SADD', KEYS[3], ARGV[3])
return 1
LUA;

    public function add(string $batchId, string $dt, int $hour, string $nodeType, int $nodeId, array $users): bool
    {
        $name = "access_log:sum:{$dt}:{$nodeType}:{$nodeId}";
        $args = [self::SEEN_TTL, 1 << $hour, $name];
        foreach ($users as [$uid, $records, $up, $down]) {
            array_push($args, $uid, $records, $up, $down);
        }
        $added = Redis::connection()->eval(
            self::ADD,
            3,
            'access_log:seen:' . $batchId,
            $name,
            self::PENDING,
            ...$args
        );
        return (int) $added === 1;
    }

    public function drain(callable $write, int $waitSeconds = 0): int
    {
        $redis = Redis::connection();
        $token = Str::random(20);
        $until = microtime(true) + $waitSeconds;
        while (!$redis->set(self::LOCK, $token, 'EX', self::LOCK_TTL, 'NX')) {
            if (microtime(true) >= $until) {
                return 0;
            }
            usleep(200_000);
        }
        try {
            return $this->drainLocked($write);
        } finally {
            $redis->eval(self::RELEASE, 1, self::LOCK, $token);
        }
    }

    private function drainLocked(callable $write): int
    {
        $redis = Redis::connection();
        $total = 0;
        // 上次写库失败留下的先交
        foreach ((array) $redis->smembers(self::RETRY) as $draining) {
            $total += $this->deliver($draining, $write);
        }
        foreach ((array) $redis->smembers(self::PENDING) as $name) {
            // 先摘掉再改名：改名前后到达的批次要么落进被改名的这份，要么重新登记进 pending
            $redis->srem(self::PENDING, $name);
            $draining = $name . ':draining:' . Str::lower(Str::random(8));
            try {
                if (!$redis->rename($name, $draining)) {
                    continue;
                }
            } catch (\Throwable) {
                continue;
            }
            $redis->sadd(self::RETRY, $draining);
            $total += $this->deliver($draining, $write);
        }
        return $total;
    }

    private function deliver(string $draining, callable $write): int
    {
        $redis = Redis::connection();
        if (!preg_match('/^access_log:sum:(\d{4}-\d{2}-\d{2}):([a-z0-9_-]+):(\d+):draining:/', $draining, $m)) {
            $redis->srem(self::RETRY, $draining);
            return 0;
        }
        $rows = self::rows($m[1], $m[2], (int) $m[3], (array) $redis->hgetall($draining));
        $write($rows);
        $redis->del($draining);
        $redis->srem(self::RETRY, $draining);
        return count($rows);
    }

    /** @param array<string,string> $fields */
    public static function rows(string $dt, string $nodeType, int $nodeId, array $fields): array
    {
        $users = [];
        foreach ($fields as $field => $value) {
            [$uid, $kind] = array_pad(explode(':', (string) $field, 2), 2, '');
            $users[(int) $uid][$kind] = (int) $value;
        }
        $rows = [];
        foreach ($users as $uid => $v) {
            if ($uid <= 0) {
                continue;
            }
            $rows[] = [
                'dt' => $dt,
                'node_type' => $nodeType,
                'node_id' => $nodeId,
                'user_id' => $uid,
                'records' => $v['r'] ?? 0,
                'up' => $v['u'] ?? 0,
                'down' => $v['d'] ?? 0,
                'hours' => $v['m'] ?? 0,
            ];
        }
        return $rows;
    }
}
