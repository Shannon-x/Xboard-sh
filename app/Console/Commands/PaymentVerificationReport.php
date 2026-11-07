<?php

namespace App\Console\Commands;

use App\Services\EpayGatewayMonitor;
use App\Services\PaymentLinkReport;
use App\Services\PaymentVerificationService;
use App\Support\AdminAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * 付款核验日报：当天核验了多少笔、各结论分布、异常明细，推送给管理员。Kernel 每天 23:30 跑。
 * 有易支付 V2 商户时，末尾追加网关侧余额与当天收入对账（见 EpayGatewayMonitor::merchantSummary）。
 * 最后是各支付通道当天拉取收款链接的成功 / 失败次数、失败原因与转化率（见 PaymentLinkReport）。
 */
class PaymentVerificationReport extends Command
{
    protected $signature = 'payment:verify-report
        {--date= : 日期 Y-m-d，默认今天}
        {--no-send : 只输出，不推送给管理员}';

    protected $description = '付款核验日报';

    public function handle(PaymentVerificationService $service, EpayGatewayMonitor $monitor, PaymentLinkReport $links): int
    {
        $date = $this->option('date');
        $day = $date ? Carbon::createFromFormat('Y-m-d', (string) $date)->startOfDay() : Carbon::today();
        $report = $service->dailyReport($day);
        // 网关只提供「今日 / 昨日」口径，补发历史日期的日报时不追加
        if ($day->isToday()) {
            $merchant = $monitor->merchantSummary($day);
            if ($merchant !== []) {
                $report .= "\n\n" . implode("\n", $merchant);
            }
        }
        $report .= "\n\n" . implode("\n", $links->dailyLines($day));
        $this->line($report);
        if (!$this->option('no-send')) {
            AdminAlert::send($report);
        }
        return self::SUCCESS;
    }
}
