<?php

namespace App\Console\Commands;

use App\Jobs\SendEmailJob;
use App\Models\BillingDocument;
use App\Models\MailLog;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 邮件投递日报：过去 24 小时的失败数与分类、新标记为暂停投递的用户、未能投递的归档文档，
 * 发给 Telegram 管理员（开了 Bot 的话）和所有管理员账号的邮箱。没有失败就不发。
 * XBoard-admin 还没有邮件日志界面，这份日报就是后台看退信的入口。
 */
class MailDeliveryDigest extends Command
{
    protected $signature = 'mail:delivery-digest
                            {--hours=24 : 统计最近几小时}
                            {--force : 没有失败也发}
                            {--dry-run : 只打印不发送}';

    protected $description = '邮件投递日报：退信、暂停投递的用户、未投递的收据 / 账单';

    public function handle(): int
    {
        if (!(int) admin_setting('mail_digest_enable', 1)) {
            $this->warn('邮件投递日报未启用（mail_digest_enable）');
            return 0;
        }
        $hours = max(1, min(24 * 30, (int) $this->option('hours')));
        $since = time() - $hours * 3600;

        $sent = MailLog::where('created_at', '>=', $since)->where('status', 1)->count();
        $failed = MailLog::where('created_at', '>=', $since)->where('status', 0)->count();
        $byCategory = MailLog::where('created_at', '>=', $since)->where('status', 0)->whereNotNull('category')
            ->selectRaw('category, count(*) as n')->groupBy('category')->pluck('n', 'category')->all();
        $newlySuppressed = User::whereNotNull('mail_suppressed_at')->where('mail_suppressed_at', '>=', $since)
            ->orderBy('mail_suppressed_at', 'desc')->get(['id', 'email', 'mail_suppressed_reason', 'telegram_id']);
        $suppressedTotal = User::whereNotNull('mail_suppressed_at')->count();
        $pendingDocs = BillingDocument::whereNull('sent_at')->where('created_at', '>=', $since)->count();

        if ($failed === 0 && $newlySuppressed->isEmpty() && !$this->option('force')) {
            $this->info("最近 {$hours} 小时没有失败的邮件，不发日报。");
            return 0;
        }

        $text = $this->compose($hours, $sent, $failed, $byCategory, $newlySuppressed, $suppressedTotal, $pendingDocs);
        $this->line($text);
        if ($this->option('dry-run')) {
            return 0;
        }

        $appName = (string) admin_setting('app_name', 'XBoard');
        $subject = "邮件投递日报：{$failed} 封失败、{$newlySuppressed->count()} 位用户暂停投递 - {$appName}";
        if ((int) admin_setting('telegram_bot_enable', 0)) {
            try {
                app(TelegramService::class)->sendMessageWithAdmin($subject . "\n\n" . $text);
            } catch (\Throwable $e) {
                Log::warning('[mail] 投递日报 Telegram 发送失败', ['error' => $e->getMessage()]);
            }
        }
        $admins = User::where('is_admin', 1)->whereNotNull('email')->whereNull('mail_suppressed_at')->get(['id', 'email']);
        foreach ($admins as $admin) {
            SendEmailJob::dispatch([
                'email' => $admin->email,
                'user_id' => $admin->id,
                'subject' => $subject,
                'template_name' => 'notify',
                'template_value' => [
                    'name' => $appName,
                    'url' => admin_setting('app_url'),
                    // notify 模板自己会 nl2br(e())，这里传纯文本；先转义会让 <br /> 原样显示在邮件里
                    'content' => $text,
                ],
            ]);
        }
        $this->info("日报已发送：Telegram 管理员" . ((int) admin_setting('telegram_bot_enable', 0) ? '✓' : '✗') . "，管理员邮箱 {$admins->count()} 个");
        return 0;
    }

    private function compose(int $hours, int $sent, int $failed, array $byCategory, $newlySuppressed, int $suppressedTotal, int $pendingDocs): string
    {
        $names = ['suppressed' => '收件方抑制名单', 'bounce' => '永久拒收', 'temporary' => '临时失败', 'config' => 'SMTP 配置 / 连接'];
        $lines = [];
        $lines[] = "最近 {$hours} 小时：发出 {$sent} 封，失败 {$failed} 封" . ($sent + $failed > 0 ? '（失败率 ' . round($failed * 100 / ($sent + $failed), 1) . '%）' : '') . '。';
        if ($byCategory) {
            $parts = [];
            foreach ($byCategory as $category => $n) {
                $parts[] = ($names[$category] ?? $category) . " {$n}";
            }
            $lines[] = '失败分类：' . implode('，', $parts) . '。';
        }
        if (isset($byCategory['config']) && $byCategory['config'] > 0) {
            $lines[] = '⚠ 有 SMTP 配置 / 连接类失败，这不是收件人的问题，请检查后台的邮件服务设置。';
        }
        if ($newlySuppressed->isNotEmpty()) {
            $lines[] = "新标记为暂停投递的用户 {$newlySuppressed->count()} 位（累计 {$suppressedTotal} 位）：";
            foreach ($newlySuppressed->take(15) as $u) {
                $lines[] = "  · {$u->email}（" . ($names[$u->mail_suppressed_reason] ?? $u->mail_suppressed_reason) . ($u->telegram_id ? '，已绑定 Telegram，通知改走 Telegram' : '，未绑定 Telegram') . '）';
            }
            if ($newlySuppressed->count() > 15) {
                $lines[] = '  · …其余 ' . ($newlySuppressed->count() - 15) . ' 位见后台接口 admin/mail/suppressed';
            }
        } elseif ($suppressedTotal > 0) {
            $lines[] = "暂停投递的用户累计 {$suppressedTotal} 位，本期没有新增。";
        }
        if ($pendingDocs > 0) {
            $lines[] = "本期有 {$pendingDocs} 份收据 / 账单未能投递（用户可在面板「账单与收据」下载）。";
        }
        $lines[] = '处理：用户在面板点「重新测试邮箱」成功后自动恢复；也可用后台接口 admin/mail/unsuppress 解除标记。';
        return implode("\n", $lines);
    }
}
