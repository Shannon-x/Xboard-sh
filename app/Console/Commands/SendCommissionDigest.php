<?php

namespace App\Console\Commands;

use App\Services\Commission\CommissionDigest;
use App\Services\Commission\CommissionNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 佣金入账汇总通知：commission_notify_frequency = daily 时每天发，weekly 时只在周一发，instant 时什么都不做
 * （instant 由 check:commission 每轮结束直接发）。Kernel 每天 12:00 调一次。
 */
class SendCommissionDigest extends Command
{
    protected $signature = 'commission:notify-digest {--force : 不看今天是不是周一，立即汇总发送}';

    protected $description = '佣金入账的每日 / 每周汇总邮件';

    public function handle(): int
    {
        $frequency = CommissionNotifier::frequency();
        if ($frequency === CommissionNotifier::FREQUENCY_INSTANT) {
            $this->info('佣金通知是每轮即时发送，不需要汇总');
            return 0;
        }
        if ($frequency === CommissionNotifier::FREQUENCY_WEEKLY && !now()->isMonday() && !$this->option('force')) {
            $this->info('每周汇总只在周一发送');
            return 0;
        }
        $result = (new CommissionDigest())->run($frequency);
        Log::info('[commission] digest', ['frequency' => $frequency] + $result);
        $this->info("汇总 {$result['logs']} 笔入账，{$result['inviters']} 位邀请人，已排队 {$result['sent']} 封");
        return 0;
    }
}
