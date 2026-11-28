<?php

namespace App\Console\Commands;

use App\Services\PaymentAlertDigest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * 支付异常每日邮件（见 PaymentAlertDigest）。
 *
 * Kernel 从 9:00 起每 15 分钟带 --once-per-day 跑一次：当天发过就跳过，没发过就发，范围从上一封
 * 发出时起（最多 72 小时）。9:00 那一刻调度恰好停着也不会整天没信，恢复后补发、覆盖停摆期间。
 */
class SendPaymentAlertDigest extends Command
{
    protected $signature = 'payment:alert-digest
        {--hours=24 : 汇总最近多少小时}
        {--dry-run : 只输出，不发邮件}
        {--once-per-day : 当天已发过就跳过；没发过则从上一封起汇总（最多 72 小时）}';

    protected $description = '支付异常每日邮件：自动封号、自动删号、核验统计、支付投诉';

    public const LAST_SENT_KEY = 'payment_alert_digest:last_sent_at';

    public function handle(PaymentAlertDigest $digest): int
    {
        $until = time();
        $since = $until - max(1, (int) $this->option('hours')) * 3600;
        if ($this->option('once-per-day')) {
            $last = (int) Cache::get(self::LAST_SENT_KEY, 0);
            if ($last > 0 && date('Y-m-d', $last) === date('Y-m-d', $until)) {
                $this->line('今天已经发过，跳过');
                return self::SUCCESS;
            }
            if ($last > 0) {
                $since = max($until - 72 * 3600, min($since, $last));
            }
        }
        if ($this->option('dry-run')) {
            $built = $digest->build($since, $until);
            $this->line($built['subject']);
            $this->line($built['content']);
            $this->line('收件人：' . implode(', ', $digest->recipients()));
            return self::SUCCESS;
        }
        $sent = $digest->send($since, $until);
        Cache::forever(self::LAST_SENT_KEY, $until);
        $this->info("已发给 {$sent} 个收件人");
        return self::SUCCESS;
    }
}
