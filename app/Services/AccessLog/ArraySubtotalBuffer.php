<?php

namespace App\Services\AccessLog;

/** 进程内实现，供测试和没有 Redis 的单机调试用，语义与 RedisSubtotalBuffer 相同 */
final class ArraySubtotalBuffer implements SubtotalBuffer
{
    private array $seen = [];
    /** @var array<string,array<int,array{0:int,1:int,2:int,3:int}>> */
    private array $sums = [];

    public function add(string $batchId, string $dt, int $hour, string $nodeType, int $nodeId, array $users): bool
    {
        if (isset($this->seen[$batchId])) {
            return false;
        }
        $this->seen[$batchId] = true;
        $key = "{$dt}|{$nodeType}|{$nodeId}";
        foreach ($users as [$uid, $records, $up, $down]) {
            $sum = $this->sums[$key][$uid] ?? [0, 0, 0, 0];
            $this->sums[$key][$uid] = [$sum[0] + $records, $sum[1] + $up, $sum[2] + $down, $sum[3] | (1 << $hour)];
        }
        return true;
    }

    public function drain(callable $write): int
    {
        $rows = [];
        foreach ($this->sums as $key => $users) {
            [$dt, $nodeType, $nodeId] = explode('|', $key);
            foreach ($users as $uid => [$records, $up, $down, $hours]) {
                $rows[] = [
                    'dt' => $dt,
                    'node_type' => $nodeType,
                    'node_id' => (int) $nodeId,
                    'user_id' => $uid,
                    'records' => $records,
                    'up' => $up,
                    'down' => $down,
                    'hours' => $hours,
                ];
            }
        }
        if ($rows !== []) {
            $write($rows);
        }
        $this->sums = [];
        return count($rows);
    }
}
