<?php

namespace App\Services\AccessLog;

use App\Models\AccessLogFile;
use App\Models\AccessLogQuery;
use App\Models\AccessLogUserDay;
use App\Models\Server;
use Carbon\Carbon;

/**
 * 按用户查访问记录。
 *
 * 日汇总直接读 v2_access_log_user_day。明细先由日汇总的小时位图定出该用户出现过的
 * (天, 节点, 小时)，再在目录里找出这些小时的批次对象，每个对象只按范围读该用户所在的那一段
 * （user_id % 32），解压后挑出该用户的行，写成 CSV 放回 S3，并顺带按目标汇总。
 */
final class AccessLogQueryService
{
    public const MAX_DAYS = 7;
    public const MAX_ROWS = 300000;
    public const MAX_FILES = 20000;
    /** 队列任务硬超时 140 秒（queue.php 的 retry_after 是 150），读到这里就收尾 */
    public const SOFT_DEADLINE = 110;
    public const TOP_HOSTS = 100;
    private const CHUNK = 64;
    private const CSV_HEADER = ['开始时间', '时长(秒)', '协议', '目标', '端口', '出站', '客户端 IP', '上行(字节)', '下行(字节)', '状态', '节点'];

    /** 某用户在一段日期内每天、每个节点的连接数和流量 */
    public static function summary(int $userId, string $from, string $to): array
    {
        $rows = AccessLogUserDay::query()
            ->where('user_id', $userId)
            ->whereBetween('dt', [$from, $to])
            ->orderByDesc('dt')
            ->get();
        $names = self::nodeNames($rows->map(fn($r) => [$r->node_type, $r->node_id])->all());
        $days = [];
        $total = ['records' => 0, 'up' => 0, 'down' => 0];
        foreach ($rows as $row) {
            $dt = (string) $row->dt;
            $days[$dt] ??= ['dt' => $dt, 'records' => 0, 'up' => 0, 'down' => 0, 'nodes' => []];
            $days[$dt]['records'] += $row->records;
            $days[$dt]['up'] += $row->up;
            $days[$dt]['down'] += $row->down;
            $days[$dt]['nodes'][] = [
                'node_type' => $row->node_type,
                'node_id' => $row->node_id,
                'node_name' => $names["{$row->node_type}-{$row->node_id}"] ?? null,
                'records' => $row->records,
                'up' => $row->up,
                'down' => $row->down,
                'hours' => self::hours($row->hours),
            ];
            $total['records'] += $row->records;
            $total['up'] += $row->up;
            $total['down'] += $row->down;
        }
        return ['days' => array_values($days), 'total' => $total];
    }

    public function __construct(private readonly AccessLogStorage $storage)
    {
    }

    public function run(AccessLogQuery $query, ?int $deadline = null): void
    {
        $deadline ??= time() + self::SOFT_DEADLINE;
        $userId = $query->user_id;
        $group = AccessLogBatch::groupOf($userId);
        $filter = (array) ($query->filter ?? []);
        $hostFilter = strtolower(trim((string) ($filter['host'] ?? '')));
        $networkFilter = (string) ($filter['network'] ?? '');

        [$files, $truncated] = $this->files($userId, (string) $query->date_from, (string) $query->date_to);
        $names = self::nodeNames(array_map(fn(AccessLogFile $f) => [$f->node_type, $f->node_id], $files));

        $csv = fopen('php://temp/maxmemory:' . (8 * 1024 * 1024), 'w+b');
        fwrite($csv, "\xEF\xBB\xBF");
        fputcsv($csv, self::CSV_HEADER, ',', '"', '');
        $hosts = [];
        $rows = 0;
        $bytes = 0;
        $missing = 0;
        $pattern = '/^' . $userId . '\t[^\n]*/m';

        foreach (array_chunk($files, self::CHUNK) as $chunk) {
            if (time() >= $deadline) {
                $truncated = true;
                break;
            }
            $ranges = array_map(fn(AccessLogFile $f) => [$f->object_key, ...$f->range($group)], $chunk);
            $bodies = $this->storage->getRanges($ranges);
            foreach ($chunk as $i => $file) {
                if ($ranges[$i][2] === 0) {
                    continue;
                }
                $body = $bodies[$i] ?? null;
                $text = $body === null ? false : @gzdecode($body);
                if ($text === false) {
                    $missing++;
                    continue;
                }
                $bytes += strlen($body);
                if (!preg_match_all($pattern, $text, $matches)) {
                    continue;
                }
                $node = $names["{$file->node_type}-{$file->node_id}"] ?? "{$file->node_type}-{$file->node_id}";
                foreach ($matches[0] as $line) {
                    $cols = explode("\t", $line);
                    if (count($cols) !== count(AccessLogBatch::COLUMNS)) {
                        continue;
                    }
                    [, $started, $seconds, $network, $host, $port, $outbound, $source, $up, $down, $finished] = $cols;
                    if ($networkFilter !== '' && $network !== $networkFilter) {
                        continue;
                    }
                    if ($hostFilter !== '' && !str_contains($host, $hostFilter)) {
                        continue;
                    }
                    if ($rows >= self::MAX_ROWS) {
                        $truncated = true;
                        break 3;
                    }
                    $rows++;
                    $h = &$hosts[$host];
                    $h ??= [0, 0, 0];
                    $h[0]++;
                    $h[1] += (int) $up;
                    $h[2] += (int) $down;
                    unset($h);
                    fputcsv($csv, [
                        Carbon::createFromTimestamp((int) $started, config('app.timezone'))->format('Y-m-d H:i:s'),
                        $seconds,
                        $network,
                        $host,
                        $port,
                        $outbound,
                        $source,
                        $up,
                        $down,
                        $finished === '1' ? '已结束' : '进行中',
                        $node,
                    ], ',', '"', '');
                }
            }
        }

        $exportKey = null;
        if ($rows > 0) {
            rewind($csv);
            $exportKey = $this->storage->exportKey($query->id);
            $this->storage->put($exportKey, (string) stream_get_contents($csv), 'text/csv; charset=utf-8');
        }
        fclose($csv);

        uasort($hosts, static fn(array $a, array $b) => ($b[1] + $b[2]) <=> ($a[1] + $a[2]));
        $top = [];
        foreach (array_slice($hosts, 0, self::TOP_HOSTS, true) as $host => [$records, $up, $down]) {
            $top[] = ['host' => (string) $host, 'records' => $records, 'up' => $up, 'down' => $down];
        }

        $query->forceFill([
            'status' => AccessLogQuery::STATUS_DONE,
            'files' => count($files),
            'missing' => $missing,
            'bytes' => $bytes,
            'rows' => $rows,
            'truncated' => $truncated,
            'summary' => ['hosts' => $top, 'distinct_hosts' => count($hosts)],
            'export_key' => $exportKey,
            'error' => null,
        ])->save();
    }

    /**
     * 该用户在日期范围内出现过的批次，按时间排序。
     *
     * @return array{0:AccessLogFile[],1:bool}
     */
    private function files(int $userId, string $from, string $to): array
    {
        $days = AccessLogUserDay::query()
            ->where('user_id', $userId)
            ->whereBetween('dt', [$from, $to])
            ->where('hours', '>', 0)
            ->get(['dt', 'node_type', 'node_id', 'hours']);
        $files = [];
        foreach ($days as $day) {
            $batch = AccessLogFile::query()
                ->where('dt', $day->dt)
                ->where('node_type', $day->node_type)
                ->where('node_id', $day->node_id)
                ->whereIn('hour', self::hours($day->hours))
                ->get();
            foreach ($batch as $file) {
                $files[] = $file;
            }
            if (count($files) > self::MAX_FILES) {
                break;
            }
        }
        usort($files, static fn(AccessLogFile $a, AccessLogFile $b) => [$a->from_at, $a->id] <=> [$b->from_at, $b->id]);
        $truncated = count($files) > self::MAX_FILES;
        return [array_slice($files, 0, self::MAX_FILES), $truncated];
    }

    /** @return int[] */
    public static function hours(int $mask): array
    {
        $hours = [];
        for ($h = 0; $h < 24; $h++) {
            if ($mask & (1 << $h)) {
                $hours[] = $h;
            }
        }
        return $hours;
    }

    /**
     * @param array<int,array{0:string,1:int}> $nodes
     * @return array<string,string> "type-id" => 名称
     */
    public static function nodeNames(array $nodes): array
    {
        $ids = array_values(array_unique(array_map(fn($n) => (int) $n[1], $nodes)));
        if ($ids === []) {
            return [];
        }
        $names = [];
        foreach (Server::query()->whereIn('id', $ids)->get(['id', 'type', 'name']) as $server) {
            $names["{$server->type}-{$server->id}"] = (string) $server->name;
        }
        return $names;
    }
}
