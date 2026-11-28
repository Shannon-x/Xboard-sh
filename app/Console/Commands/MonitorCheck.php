<?php

namespace App\Console\Commands;

use App\Services\Monitor\SystemMonitor;
use Illuminate\Console\Command;

/**
 * 面板巡检（每分钟）：队列金丝雀、Horizon、失败任务、定时任务按时与否、邮件投递服务。
 * 有问题直发 Telegram（不经过队列），可选推外部心跳（MONITOR_HEARTBEAT_URL）。见 SystemMonitor。
 */
class MonitorCheck extends Command
{
    protected $signature = 'monitor:check';

    protected $description = '面板巡检：队列、定时任务、邮件投递服务，有问题直发告警';

    public function handle(): int
    {
        $report = SystemMonitor::check();
        $this->line('overall: ' . $report['overall']);
        foreach ($report['issues'] as $issue) {
            $this->line("[{$issue['level']}] {$issue['title']}");
        }
        return self::SUCCESS;
    }
}
