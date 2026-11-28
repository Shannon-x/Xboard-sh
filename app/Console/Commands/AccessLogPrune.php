<?php

namespace App\Console\Commands;

use App\Models\AccessLogFile;
use App\Models\AccessLogQuery;
use App\Models\AccessLogUserDay;
use App\Services\AccessLog\AccessLogConfig;
use App\Services\AccessLog\AccessLogStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 访问记录过期清理。桶上的生命周期规则（建议给 {prefix}/v1/ 设同样的天数）删对象更省事，
 * 这里兜底：按保留天数删对象和目录、删日汇总、删 7 天前的导出；查询审计记录留一年。
 */
class AccessLogPrune extends Command
{
    protected $signature = 'access-log:prune {--dry-run : 只统计不删除}';
    protected $description = '按保留天数清理访问记录的 S3 对象、目录、日汇总和导出';

    private const AUDIT_DAYS = 365;

    public function handle(): int
    {
        $config = AccessLogConfig::fromSettings();
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->startOfDay()->subDays($config->retentionDays - 1)->format('Y-m-d');
        $storage = $config->storageConfigured() ? app(AccessLogStorage::class) : null;
        $stats = ['files' => 0, 'summaries' => 0, 'exports' => 0, 'queries' => 0];

        // 对象删掉了才删目录行：存储暂时不可用时目录留着，明天再删
        if ($storage !== null) {
            AccessLogFile::query()->where('dt', '<', $cutoff)->chunkById(1000, function ($files) use ($storage, $dryRun, &$stats) {
                if (!$dryRun) {
                    try {
                        $storage->deleteMany($files->pluck('object_key')->all());
                    } catch (\Throwable $e) {
                        Log::warning('access log prune: delete objects failed: ' . $e->getMessage());
                        return false;
                    }
                    AccessLogFile::query()->whereIn('id', $files->pluck('id'))->delete();
                }
                $stats['files'] += $files->count();
            });
        }

        $days = AccessLogUserDay::query()->where('dt', '<', $cutoff)->distinct()->pluck('dt');
        foreach ($days as $dt) {
            $query = AccessLogUserDay::query()->where('dt', $dt);
            $stats['summaries'] += $dryRun ? $query->count() : $query->delete();
        }

        $exports = AccessLogQuery::query()
            ->whereNotNull('export_key')
            ->where('created_at', '<', now()->getTimestamp() - AccessLogConfig::EXPORT_DAYS * 86400);
        if ($storage !== null) {
            $exports->chunkById(500, function ($queries) use ($storage, $dryRun, &$stats) {
                if (!$dryRun) {
                    try {
                        $storage->deleteMany($queries->pluck('export_key')->all());
                    } catch (\Throwable $e) {
                        Log::warning('access log prune: delete exports failed: ' . $e->getMessage());
                        return false;
                    }
                    AccessLogQuery::query()->whereIn('id', $queries->pluck('id'))->update(['export_key' => null]);
                }
                $stats['exports'] += $queries->count();
            });
        }

        $old = AccessLogQuery::query()->where('created_at', '<', now()->getTimestamp() - self::AUDIT_DAYS * 86400)->whereNull('export_key');
        $stats['queries'] = $dryRun ? $old->count() : $old->delete();

        $this->info(json_encode($stats));
        return self::SUCCESS;
    }
}
