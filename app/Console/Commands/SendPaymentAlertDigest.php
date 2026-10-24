<?php

namespace App\Console\Commands;

use App\Services\PaymentAlertDigest;
use Illuminate\Console\Command;

/**
 * 支付异常每日邮件（见 PaymentAlertDigest）。Kernel 每天 9:00 发，汇总过去 24 小时。
 */
class SendPaymentAlertDigest extends Command
{
    protected $signature = 'payment:alert-digest
        {--hours=24 : 汇总最近多少小时}
        {--dry-run : 只输出，不发邮件}';

    protected $description = '支付异常每日邮件：自动封号、待确认删号、核验统计、支付投诉';

    public function handle(PaymentAlertDigest $digest): int
    {
        $until = time();
        $since = $until - max(1, (int) $this->option('hours')) * 3600;
        if ($this->option('dry-run')) {
            $built = $digest->build($since, $until);
            $this->line($built['subject']);
            $this->line($built['content']);
            $this->line('收件人：' . implode(', ', $digest->recipients()));
            return self::SUCCESS;
        }
        $sent = $digest->send($since, $until);
        $this->info("已发给 {$sent} 个收件人");
        return self::SUCCESS;
    }
}
