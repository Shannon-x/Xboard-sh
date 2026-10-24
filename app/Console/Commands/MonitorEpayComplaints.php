<?php

namespace App\Console\Commands;

use App\Services\EpayGatewayMonitor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 拉易支付 V2 商户的支付宝 / 微信投诉，新投诉或状态变化推给管理员（见 EpayGatewayMonitor）。
 * Kernel 每 10 分钟跑一次；没有 V2 配置时什么都不做。
 */
class MonitorEpayComplaints extends Command
{
    protected $signature = 'payment:epay-complaints';

    protected $description = '巡检易支付 V2 商户的支付投诉并告警';

    public function handle(EpayGatewayMonitor $monitor): int
    {
        $stats = $monitor->complaints();
        foreach ($stats['errors'] as $error) {
            Log::warning('[epay-complaints] ' . $error);
            $this->warn($error);
        }
        $this->info("商户 {$stats['merchants']} 个，投诉 {$stats['complaints']} 条，本次告警 {$stats['alerted']} 条，回查 {$stats['rechecked']} 笔");
        return self::SUCCESS;
    }
}
