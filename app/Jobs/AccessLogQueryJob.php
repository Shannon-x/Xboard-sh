<?php

namespace App\Jobs;

use App\Models\AccessLogQuery;
use App\Services\AccessLog\AccessLogQueryService;
use App\Services\AccessLog\AccessLogStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** 后台发起的一次访问明细查询 / 导出。见 AccessLogQueryService */
class AccessLogQueryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    /** 小于 queue.php 的 retry_after（150 秒），查询自己在 110 秒处收尾 */
    public $timeout = 140;

    public function __construct(public readonly int $queryId)
    {
        // 用已有的 default 队列：生产机的 horizon.php 是挂载进容器的，新加队列要手工同步才会有 worker。
        // 任务自己的 timeout（140 秒）优先于 supervisor 的 60 秒，且小于 retry_after。
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $query = AccessLogQuery::find($this->queryId);
        if (!$query || $query->status !== AccessLogQuery::STATUS_PENDING) {
            return;
        }
        $query->forceFill(['status' => AccessLogQuery::STATUS_RUNNING])->save();
        try {
            (new AccessLogQueryService(app(AccessLogStorage::class)))->run($query);
        } catch (\Throwable $e) {
            $query->forceFill([
                'status' => AccessLogQuery::STATUS_FAILED,
                'error' => Str::limit($e->getMessage(), 250),
            ])->save();
        }
    }

    public function failed(\Throwable $e): void
    {
        AccessLogQuery::whereKey($this->queryId)
            ->whereIn('status', [AccessLogQuery::STATUS_PENDING, AccessLogQuery::STATUS_RUNNING])
            ->update(['status' => AccessLogQuery::STATUS_FAILED, 'error' => Str::limit($e->getMessage(), 250), 'updated_at' => now()->getTimestamp()]);
    }
}
