<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Utils\CacheKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Laravel\Horizon\WaitTimeCalculator;
use App\Helpers\ResponseEnum;

class SystemController extends Controller
{
    public function getSystemStatus()
    {
        $data = [
            'schedule' => $this->getScheduleStatus(),
            'horizon' => $this->getHorizonStatus(),
            'schedule_last_runtime' => Cache::get(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null)),
        ];
        return $this->success($data);
    }

    public function getQueueWorkload(WorkloadRepository $workload)
    {
        return $this->success(collect($workload->get())->sortBy('name')->values()->toArray());
    }

    protected function getScheduleStatus(): bool
    {
        return (time() - 120) < Cache::get(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null));
    }

    protected function getHorizonStatus(): bool
    {
        if (!$masters = app(MasterSupervisorRepository::class)->all()) {
            return false;
        }

        return collect($masters)->contains(function ($master) {
            return $master->status === 'paused';
        }) ? false : true;
    }

    public function getQueueStats()
    {
        $failedByJob = $this->failedJobsByName();
        $data = [
            'failedJobs' => app(JobRepository::class)->countRecentlyFailed(),
            'jobsPerMinute' => app(MetricsRepository::class)->jobsProcessedPerMinute(),
            'pausedMasters' => $this->totalPausedMasters(),
            'periods' => [
                'failedJobs' => config('horizon.trim.recent_failed', config('horizon.trim.failed')),
                'recentJobs' => config('horizon.trim.recent'),
            ],
            'processes' => $this->totalProcessCount(),
            'queueWithMaxRuntime' => app(MetricsRepository::class)->queueWithMaximumRuntime(),
            'queueWithMaxThroughput' => app(MetricsRepository::class)->queueWithMaximumThroughput(),
            'recentJobs' => app(JobRepository::class)->countRecent(),
            // 失败窗口内按任务类名分组的前 5 名：后台一眼看出是哪类任务在挂（periods.failedJobs 是窗口分钟数，不是数量）。
            // failedByJobScanned 是实际翻看的失败条数，小于 failedJobs 时说明只按最近这么多条统计
            'failedByJob' => $failedByJob['groups'],
            'failedByJobScanned' => $failedByJob['scanned'],
            'status' => $this->getHorizonStatus(),
            'wait' => collect(app(WaitTimeCalculator::class)->calculate())->take(1),
        ];
        return $this->success($data);
    }

    /**
     * 失败窗口（horizon.trim.recent_failed 分钟）内的失败任务按类名计数，取前 5。
     * Horizon 只给分页（每页 50，最新的在前），最多翻 10 页，翻到窗口外就停。
     *
     * @return array{groups: array<int, array{name: string, total: int, last_failed_at: int}>, scanned: int}
     */
    protected function failedJobsByName(): array
    {
        $repository = app(JobRepository::class);
        $minutes = (int) config('horizon.trim.recent_failed', config('horizon.trim.failed', 10080));
        $cutoff = time() - $minutes * 60;
        $groups = [];
        $scanned = 0;
        $afterIndex = null;
        for ($page = 0; $page < 10; $page++) {
            $jobs = $repository->getFailed($afterIndex);
            if ($jobs->isEmpty()) {
                break;
            }
            $reachedCutoff = false;
            foreach ($jobs as $job) {
                $failedAt = (int) ($job->failed_at ?? 0);
                if ($failedAt && $failedAt < $cutoff) {
                    $reachedCutoff = true;
                    break;
                }
                $scanned++;
                $name = (string) ($job->name ?? 'unknown');
                $groups[$name] ??= ['name' => $name, 'total' => 0, 'last_failed_at' => 0];
                $groups[$name]['total']++;
                $groups[$name]['last_failed_at'] = max($groups[$name]['last_failed_at'], $failedAt);
            }
            if ($reachedCutoff || $jobs->count() < 50) {
                break;
            }
            $afterIndex = $jobs->last()->index ?? null;
            if ($afterIndex === null) {
                break;
            }
        }
        usort($groups, fn ($a, $b) => $b['total'] <=> $a['total']);
        return ['groups' => array_slice(array_values($groups), 0, 5), 'scanned' => $scanned];
    }

    /**
     * Get the total process count across all supervisors.
     *
     * @return int
     */
    protected function totalProcessCount()
    {
        $supervisors = app(SupervisorRepository::class)->all();

        return collect($supervisors)->reduce(function ($carry, $supervisor) {
            return $carry + collect($supervisor->processes)->sum();
        }, 0);
    }

    /**
     * Get the number of master supervisors that are currently paused.
     *
     * @return int
     */
    protected function totalPausedMasters()
    {
        if (!$masters = app(MasterSupervisorRepository::class)->all()) {
            return 0;
        }

        return collect($masters)->filter(function ($master) {
            return $master->status === 'paused';
        })->count();
    }

    public function getAuditLog(Request $request)
    {
        $current = max(1, (int) $request->input('current', 1));
        $pageSize = max(10, (int) $request->input('page_size', 10));

        $builder = AdminAuditLog::with('admin:id,email')
            ->orderBy('id', 'DESC')
            // 动作标识是 user.update / auth.login 这种点分形式，筛选按子串匹配（原本精确匹配 'update' 永远筛不到东西）
            ->when($request->input('action'), fn($q, $v) => $q->where('action', 'like', '%' . $v . '%'))
            ->when($request->input('admin_id'), fn($q, $v) => $q->where('admin_id', $v))
            ->when($request->input('keyword'), function ($q, $keyword) {
                $q->where(function ($q) use ($keyword) {
                    $q->where('uri', 'like', '%' . $keyword . '%')
                      ->orWhere('request_data', 'like', '%' . $keyword . '%');
                });
            });

        $total = $builder->count();
        $res = $builder->forPage($current, $pageSize)->get();

        return response(['data' => $res, 'total' => $total]);
    }

    public function getHorizonFailedJobs(Request $request, JobRepository $jobRepository)
    {
        // page_size 加硬上限 100，避免 admin 误传 page_size=99999 时拖死 Horizon Redis。
        // 同时 current 也加硬上限：当前实现仍依赖 collect(...)->slice() 拿到当页，
        // Horizon JobRepository::getFailed 不暴露 cursor pagination，跳过太深的页代价随线性增长。
        $current = max(1, min(1000, (int) $request->input('current', 1)));
        $pageSize = max(10, min(100, (int) $request->input('page_size', 20)));
        $offset = ($current - 1) * $pageSize;

        $failedJobs = collect($jobRepository->getFailed())
            ->sortByDesc('failed_at')
            ->slice($offset, $pageSize)
            ->values();

        $total = $jobRepository->countFailed();

        return response()->json([
            'data' => $failedJobs,
            'total' => $total,
            'current' => $current,
            'page_size' => $pageSize,
        ]);
    }

}
