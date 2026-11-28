<?php

namespace App\Services\Monitor;

use App\Jobs\QueueCanaryJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;

/**
 * 队列健康：Horizon 在不在、每条队列的积压 / 等待 / 进程 / 吞吐 / 失败，以及金丝雀。
 *
 * 金丝雀：巡检每分钟往每条队列投一个空任务（QueueCanaryJob），任务执行时记下排了多久。
 * 只有它能同时证明「Redis 能写、Horizon 在跑、这条队列有 worker、而且没被积压堵住」——
 * Horizon 的 master 记录在、进程数大于 0，worker 也可能卡在某个任务上不动。
 * 同一条队列上一个金丝雀没被执行之前不再投新的（停摆三天也不会攒下几千个），
 * 「排了多久」从第一个没被执行的金丝雀算起。
 */
final class QueueHealth
{
    private const CANARY = 'monitor:canary:';
    private const PENDING = 'monitor:canary_pending:';
    private const REDISPATCH = 'monitor:canary_redispatch:';
    /** 金丝雀排了这么久还没执行，可能是 Redis 重启弄丢了：补投一个（不重置「排了多久」的起点） */
    private const REDISPATCH_AFTER = 600;

    /** 仪表盘上显示的用途 */
    public const LABELS = [
        'traffic_fetch' => '节点流量入账',
        'stat' => '流量统计',
        'order_handle' => '订单开通 / 取消',
        'default' => '支付事件与杂项',
        'node_sync' => '节点用户同步',
        'send_telegram' => 'Telegram 通知',
        'mail_instant' => '验证码邮件',
        'mail_transactional' => '收据与通知邮件',
        'mail_bulk' => '批量提醒邮件',
        'send_email' => '旧邮件队列（排空中）',
        'send_email_mass' => '旧群发队列（排空中）',
    ];

    /** @return string[] 当前环境 Horizon 消费的全部队列（config/horizon.php） */
    public static function queues(): array
    {
        $env = (string) config('app.env', 'production');
        $environments = (array) config('horizon.environments', []);
        $supervisors = (array) ($environments[$env] ?? $environments['production'] ?? []);
        $queues = [];
        foreach ($supervisors as $supervisor) {
            foreach ((array) ($supervisor['queue'] ?? []) as $queue) {
                foreach (explode(',', (string) $queue) as $name) {
                    $name = trim($name);
                    if ($name !== '') {
                        $queues[$name] = true;
                    }
                }
            }
        }
        return array_keys($queues);
    }

    /** @return string[] */
    public static function canaryQueues(): array
    {
        return array_values(array_diff(self::queues(), (array) config('monitor.canary_skip', [])));
    }

    /** 巡检每分钟调用：没有在排的金丝雀就投一个 */
    public static function dispatchCanaries(int $now): void
    {
        foreach (self::canaryQueues() as $queue) {
            try {
                $pendingSince = Cache::get(self::PENDING . $queue);
                if ($pendingSince === null) {
                    Cache::put(self::PENDING . $queue, $now, 86400 * 7);
                } elseif ($now - (int) $pendingSince < self::REDISPATCH_AFTER || !Cache::add(self::REDISPATCH . $queue, 1, self::REDISPATCH_AFTER)) {
                    continue;
                }
                QueueCanaryJob::dispatch($queue, microtime(true));
            } catch (\Throwable $e) {
                Log::warning('[monitor] 投递金丝雀失败', ['queue' => $queue, 'error' => $e->getMessage()]);
            }
        }
    }

    /** QueueCanaryJob 执行时调用 */
    public static function canaryRan(string $queue, float $dispatchedAt, ?float $now = null): void
    {
        $now ??= microtime(true);
        Cache::forget(self::PENDING . $queue);
        Cache::forget(self::REDISPATCH . $queue);
        Cache::forever(self::CANARY . $queue, [
            'ran_at' => (int) $now,
            'latency_ms' => (int) round(max(0, $now - $dispatchedAt) * 1000),
        ]);
    }

    /** @return array{ran_at: ?int, latency_ms: ?int, pending_since: ?int} */
    public static function canary(string $queue): array
    {
        $last = Cache::get(self::CANARY . $queue);
        $pending = Cache::get(self::PENDING . $queue);
        return [
            'ran_at' => is_array($last) ? (int) $last['ran_at'] : null,
            'latency_ms' => is_array($last) ? (int) $last['latency_ms'] : null,
            'pending_since' => $pending !== null ? (int) $pending : null,
        ];
    }

    /** 金丝雀排队超过多少秒算卡住：队列自己的等待阈值 + 60 秒（金丝雀每分钟一个），且不少于 stall_min_seconds */
    public static function stallAfter(string $queue): int
    {
        return max((int) config('monitor.stall_min_seconds', 120), self::waitThreshold($queue) + 60);
    }

    public static function waitThreshold(string $queue): int
    {
        return (int) (config('horizon.waits')["redis:{$queue}"] ?? 60);
    }

    /**
     * 从 Horizon 读出现状。任何一步读失败都只让对应字段为空，不抛异常（仪表盘照样能打开）。
     */
    public static function collect(int $now): array
    {
        $horizon = self::horizon();
        $workload = self::safe(fn () => collect(app(WorkloadRepository::class)->get())->keyBy('name')->all(), []);
        $failed = self::failed($now);
        $jobsPerMinute = self::safe(fn () => (int) round(app(MetricsRepository::class)->jobsProcessedPerMinute()), null);

        $queues = [];
        foreach (self::queues() as $queue) {
            $load = $workload[$queue] ?? null;
            $queues[] = [
                'name' => $queue,
                'label' => self::LABELS[$queue] ?? $queue,
                'length' => $load !== null ? (int) $load['length'] : null,
                'wait' => $load !== null ? (int) $load['wait'] : null,
                'wait_threshold' => self::waitThreshold($queue),
                'processes' => $load !== null ? (int) $load['processes'] : ($horizon['processes_by_queue'][$queue] ?? 0),
                'canary' => in_array($queue, (array) config('monitor.canary_skip', []), true) ? null : self::safe(fn () => self::canary($queue), null),
                'stall_after' => self::stallAfter($queue),
                'throughput' => self::throughput($queue),
                'failed_1h' => (int) ($failed['by_queue'][$queue] ?? 0),
            ];
        }
        unset($horizon['processes_by_queue']);

        return [
            'horizon' => $horizon + ['jobs_per_minute' => $jobsPerMinute],
            'queues' => $queues,
            'failed' => [
                'last_10m' => $failed['last_10m'],
                'last_1h' => $failed['last_1h'],
                'truncated' => $failed['truncated'],
            ],
        ];
    }

    /** 队列的单条状态：critical（卡住 / 没有 worker）、warning（等太久 / 失败多）、ok */
    public static function queueStatus(array $q, string $horizonStatus, int $now): string
    {
        $pendingSince = $q['canary']['pending_since'] ?? null;
        if ($horizonStatus === 'running') {
            if ($pendingSince !== null && $now - $pendingSince > $q['stall_after']) {
                return 'critical';
            }
            if ((int) $q['processes'] === 0) {
                return 'critical';
            }
        }
        if ($q['wait'] !== null && $q['wait'] > $q['wait_threshold']) {
            return 'warning';
        }
        if ($q['failed_1h'] >= max(1, (int) config('monitor.queue_failed_warn', 10))) {
            return 'warning';
        }
        return 'ok';
    }

    /** @return array{status: string, masters: int, supervisors: list<array>, processes: int, processes_by_queue: array<string, int>} */
    private static function horizon(): array
    {
        $masters = self::safe(fn () => app(MasterSupervisorRepository::class)->all(), null);
        $supervisors = self::safe(fn () => app(SupervisorRepository::class)->all(), []);
        $status = match (true) {
            $masters === null => 'unknown',
            $masters === [] => 'stopped',
            collect($masters)->contains(fn ($m) => ($m->status ?? '') === 'paused') => 'paused',
            default => 'running',
        };

        $byQueue = [];
        $rows = [];
        $total = 0;
        foreach ($supervisors as $supervisor) {
            $processes = (array) ($supervisor->processes ?? []);
            $count = (int) array_sum($processes);
            $total += $count;
            foreach ($processes as $key => $n) {
                // 键是 "redis:traffic_fetch"
                $queue = str_contains((string) $key, ':') ? substr((string) $key, strpos((string) $key, ':') + 1) : (string) $key;
                foreach (explode(',', $queue) as $name) {
                    $byQueue[$name] = ($byQueue[$name] ?? 0) + (int) $n;
                }
            }
            $name = (string) ($supervisor->name ?? '');
            $rows[] = [
                // master 名:supervisor 名，仪表盘只显示后半段
                'name' => str_contains($name, ':') ? substr($name, strrpos($name, ':') + 1) : $name,
                'status' => (string) ($supervisor->status ?? ''),
                'processes' => $count,
            ];
        }
        usort($rows, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return [
            'status' => $status,
            'masters' => is_array($masters) ? count($masters) : 0,
            'supervisors' => $rows,
            'processes' => $total,
            'processes_by_queue' => $byQueue,
        ];
    }

    /**
     * 最近一次 Horizon 指标快照（horizon:snapshot 每 5 分钟一条）里这条队列的处理量和平均耗时。
     *
     * @return array{per_minute: ?float, runtime_ms: ?float}
     */
    private static function throughput(string $queue): array
    {
        $snapshots = self::safe(fn () => array_values(app(MetricsRepository::class)->snapshotsForQueue($queue)), []);
        $count = count($snapshots);
        if ($count === 0) {
            return ['per_minute' => null, 'runtime_ms' => null];
        }
        $last = $snapshots[$count - 1];
        $minutes = 5.0;
        if ($count >= 2) {
            $span = (int) ($last->time ?? 0) - (int) ($snapshots[$count - 2]->time ?? 0);
            if ($span > 0) {
                $minutes = max(1.0, $span / 60);
            }
        }
        return [
            'per_minute' => round(((float) ($last->throughput ?? 0)) / $minutes, 1),
            'runtime_ms' => isset($last->runtime) ? round((float) $last->runtime, 1) : null,
        ];
    }

    /**
     * 近 1 小时的失败任务，按队列计数。Horizon 只给分页（每页 50，最新的在前），最多翻 10 页。
     *
     * @return array{last_10m: int, last_1h: int, by_queue: array<string, int>, truncated: bool}
     */
    private static function failed(int $now): array
    {
        $result = ['last_10m' => 0, 'last_1h' => 0, 'by_queue' => [], 'truncated' => false];
        try {
            $repository = app(JobRepository::class);
            $afterIndex = null;
            for ($page = 0; $page < 10; $page++) {
                $jobs = $repository->getFailed($afterIndex);
                if ($jobs->isEmpty()) {
                    return $result;
                }
                foreach ($jobs as $job) {
                    $failedAt = (int) ($job->failed_at ?? 0);
                    if ($failedAt && $failedAt < $now - 3600) {
                        return $result;
                    }
                    $result['last_1h']++;
                    if ($failedAt >= $now - 600) {
                        $result['last_10m']++;
                    }
                    $queue = (string) ($job->queue ?? '');
                    $result['by_queue'][$queue] = ($result['by_queue'][$queue] ?? 0) + 1;
                }
                if ($jobs->count() < 50 || ($afterIndex = $jobs->last()->index ?? null) === null) {
                    return $result;
                }
            }
            $result['truncated'] = true;
        } catch (\Throwable $e) {
            Log::notice('[monitor] 读失败任务失败', ['error' => $e->getMessage()]);
        }
        return $result;
    }

    /**
     * @template T
     * @param callable(): T $read
     * @param T $default
     * @return T
     */
    private static function safe(callable $read, mixed $default): mixed
    {
        try {
            return $read();
        } catch (\Throwable $e) {
            Log::notice('[monitor] 读队列状态失败', ['error' => $e->getMessage()]);
            return $default;
        }
    }
}
