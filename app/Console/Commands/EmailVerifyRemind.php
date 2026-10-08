<?php

namespace App\Console\Commands;

use App\Services\EmailVerification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 邮箱验证的到期提醒：宽限期还剩 email_verify_remind_days 天、还没提醒过的用户再收一封。
 * 每天跑一次（Kernel 11:35，跟在 send:remindMail 后面）。
 */
class EmailVerifyRemind extends Command
{
    protected $signature = 'email-verify:remind {--dry-run : 只统计不发送}';

    protected $description = '邮箱验证到期前的提醒邮件';

    public function handle(): int
    {
        if (!EmailVerification::enabled() || EmailVerification::remindDaysBefore() === 0) {
            $this->info('邮箱验证提醒未启用');
            return 0;
        }
        $sent = 0;
        $query = EmailVerification::dueForReminder()->select('id', 'email', 'email_verified_at', 'email_verify_started_at',
            'email_verify_reminded_at', 'email_verify_sent_at', 'email_verify_pending_email', 'mail_suppressed_at', 'banned');
        if ($this->option('dry-run')) {
            $this->info('将发送 ' . $query->count() . ' 封邮箱验证提醒（dry-run，未发送）');
            return 0;
        }
        $query->orderBy('id')->chunkById(200, function ($users) use (&$sent) {
            foreach ($users as $user) {
                if (EmailVerification::sendReminder($user)) {
                    $sent++;
                }
            }
        });
        Log::info('[email-verify] reminder mails queued', ['sent' => $sent]);
        $this->info("已排队 {$sent} 封邮箱验证提醒");
        return 0;
    }
}
