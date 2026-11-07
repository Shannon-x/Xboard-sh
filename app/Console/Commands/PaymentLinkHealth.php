<?php

namespace App\Console\Commands;

use App\Services\PaymentLinkReport;
use Illuminate\Console\Command;

/**
 * 支付通道白天巡检：最近 1 小时拉取失败过半、或最近 2 小时拉到链接却一笔没付时推管理员。
 * Kernel 9:00–23:59 每 15 分钟跑一次；阈值见 PaymentLinkReport。
 */
class PaymentLinkHealth extends Command
{
    protected $signature = 'payment:link-health';

    protected $description = '支付通道拉取失败率巡检';

    public function handle(PaymentLinkReport $report): int
    {
        $sent = $report->checkHealth();
        $this->line("alerts sent: {$sent}");
        return self::SUCCESS;
    }
}
