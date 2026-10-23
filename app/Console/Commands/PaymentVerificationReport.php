<?php

namespace App\Console\Commands;

use App\Services\PaymentVerificationService;
use App\Support\AdminAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * 付款核验日报：当天核验了多少笔、各结论分布、异常明细，推送给管理员。Kernel 每天 23:30 跑。
 */
class PaymentVerificationReport extends Command
{
    protected $signature = 'payment:verify-report
        {--date= : 日期 Y-m-d，默认今天}
        {--no-send : 只输出，不推送给管理员}';

    protected $description = '付款核验日报';

    public function handle(PaymentVerificationService $service): int
    {
        $date = $this->option('date');
        $day = $date ? Carbon::createFromFormat('Y-m-d', (string) $date)->startOfDay() : Carbon::today();
        $report = $service->dailyReport($day);
        $this->line($report);
        if (!$this->option('no-send')) {
            AdminAlert::send($report);
        }
        return self::SUCCESS;
    }
}
