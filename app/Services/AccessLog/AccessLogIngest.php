<?php

namespace App\Services\AccessLog;

use App\Models\AccessLogFile;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 收下节点上传的一个批次：对象原样写进 S3，用户小计记进缓冲，目录记一行。
 *
 * 每一步都可重做：重传同一批次时 S3 覆盖同一个键，缓冲按批次号只加一次，目录有唯一键。
 * 任何一步失败都抛出，由调用方回 503，节点稍后重传。
 */
final class AccessLogIngest
{
    public function __construct(
        private readonly AccessLogStorage $storage,
        private readonly SubtotalBuffer $buffer,
    ) {
    }

    /** @return bool 是否第一次收到这个批次 */
    public function store(string $nodeType, int $nodeId, AccessLogBatch $batch): bool
    {
        $exists = AccessLogFile::query()
            ->where('node_type', $nodeType)
            ->where('node_id', $nodeId)
            ->where('ledger', $batch->ledger)
            ->where('seq', $batch->seq)
            ->exists();
        if ($exists) {
            return false;
        }

        $local = Carbon::createFromTimestamp($batch->hour, config('app.timezone'));
        $dt = $local->format('Y-m-d');
        $hour = (int) $local->format('G');
        $key = $this->storage->batchKey($dt, $hour, $nodeType, $nodeId, $batch->ledger, $batch->seq);

        if ($batch->data !== '') {
            $this->storage->put($key, $batch->data);
        }
        $this->buffer->add("{$nodeType}:{$nodeId}:{$batch->ledger}:{$batch->seq}", $dt, $hour, $nodeType, $nodeId, $batch->users);

        $inserted = AccessLogFile::query()->insertOrIgnore([
            'node_type' => $nodeType,
            'node_id' => $nodeId,
            'ledger' => $batch->ledger,
            'seq' => $batch->seq,
            'dt' => $dt,
            'hour' => $hour,
            'hour_at' => $batch->hour,
            'object_key' => $key,
            'bytes' => strlen($batch->data),
            'records' => $batch->records,
            'dropped' => $batch->dropped,
            'users' => count($batch->users),
            'from_at' => min($batch->from, 4294967295),
            'to_at' => min($batch->to, 4294967295),
            'offsets' => json_encode(array_map(static fn(array $g) => $g[0], $batch->groups)),
            'created_at' => now()->getTimestamp(),
        ]);
        return $inserted > 0;
    }

    /**
     * 把缓冲里的小计并入日汇总：连接数和流量相加，小时位图按位或。
     *
     * @param array<int,array{dt:string,node_type:string,node_id:int,user_id:int,records:int,up:int,down:int,hours:int}> $rows
     */
    public static function writeSummary(array $rows): void
    {
        $now = now()->getTimestamp();
        $table = DB::getTablePrefix() . 'v2_access_log_user_day';
        $mysql = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
        $update = $mysql
            ? 'ON DUPLICATE KEY UPDATE records = records + VALUES(records), up = up + VALUES(up), '
                . 'down = down + VALUES(down), hours = hours | VALUES(hours), updated_at = VALUES(updated_at)'
            : 'ON CONFLICT (user_id, dt, node_type, node_id) DO UPDATE SET records = records + excluded.records, '
                . 'up = up + excluded.up, down = down + excluded.down, hours = hours | excluded.hours, updated_at = excluded.updated_at';
        foreach (array_chunk($rows, 500) as $chunk) {
            $placeholders = [];
            $bindings = [];
            foreach ($chunk as $row) {
                $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?)';
                array_push(
                    $bindings,
                    $row['user_id'],
                    $row['dt'],
                    $row['node_type'],
                    $row['node_id'],
                    $row['records'],
                    $row['up'],
                    $row['down'],
                    $row['hours'],
                    $now
                );
            }
            DB::statement(
                "INSERT INTO {$table} (user_id, dt, node_type, node_id, records, up, down, hours, updated_at) VALUES "
                    . implode(', ', $placeholders) . ' ' . $update,
                $bindings
            );
        }
    }
}
