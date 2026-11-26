<?php

namespace App\Console\Commands;

use App\Support\SchedulerHeartbeat;
use Illuminate\Console\Command;

/**
 * 定时任务健康检查：心跳在阈值内返回 0，否则返回 1。给 scheduler 容器的 healthcheck 与外部监控用。
 */
class SchedulerHealth extends Command
{
    protected $signature = 'scheduler:health {--max-age= : 心跳最长允许多少秒，默认取 scheduler.stale_after}';

    protected $description = '定时任务健康检查：心跳过期返回非 0';

    public function handle(): int
    {
        $max = $this->option('max-age');
        $max = $max !== null && $max !== '' ? max(1, (int) $max) : (int) config('scheduler.stale_after', 150);
        $age = SchedulerHeartbeat::age();
        if ($age === null) {
            $this->error('定时任务从未上报心跳');
            return self::FAILURE;
        }
        if ($age > $max) {
            $this->error("定时任务心跳已过期：{$age} 秒前（阈值 {$max} 秒）");
            return self::FAILURE;
        }
        $this->info("定时任务正常：心跳 {$age} 秒前");
        return self::SUCCESS;
    }
}
