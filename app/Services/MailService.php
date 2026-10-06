<?php

namespace App\Services;

use App\Jobs\SendBillingMailJob;
use App\Jobs\SendEmailJob;
use App\Models\MailLog;
use App\Models\User;
use App\Services\Billing\BillingDocumentService;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MailService
{
    // Render {{key}} / {{key|default}} placeholders.
    private static function renderPlaceholders(string $template, array $vars): string
    {
        if ($template === '' || empty($vars)) {
            return $template;
        }

        return (string) preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.-]+)(?:\|([^}]*))?\s*\}\}/', function ($m) use ($vars) {
            $key = $m[1] ?? '';
            $default = array_key_exists(2, $m) ? trim((string) $m[2]) : null;

            if (!array_key_exists($key, $vars) || $vars[$key] === null || $vars[$key] === '') {
                return $default !== null ? $default : $m[0];
            }

            $value = $vars[$key];
            if (is_bool($value)) {
                return $value ? '1' : '0';
            }
            if (is_scalar($value)) {
                return (string) $value;
            }

            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }, $template);
    }

    /**
     * 获取需要发送提醒的用户总数
     */
    public function getTotalUsersNeedRemind(): int
    {
        return User::where(function ($query) {
            $query->where('remind_expire', true)
                ->orWhere('remind_traffic', true);
        })
            ->where('banned', false)
            ->whereNotNull('email')
            ->count();
    }

    /**
     * 分块处理用户提醒邮件
     */
    public function processUsersInChunks(int $chunkSize, ?callable $progressCallback = null): array
    {
        $statistics = [
            'processed_users' => 0,
            'expire_emails' => 0,
            'invoice_emails' => 0,
            'traffic_emails' => 0,
            'errors' => 0,
            'skipped' => 0,
        ];

        User::select('id', 'email', 'expired_at', 'transfer_enable', 'u', 'd', 'remind_expire', 'remind_traffic',
            'plan_id', 'invoice_notified_at', 'invoice_final_notified_at')
            ->where(function ($query) {
                $query->where('remind_expire', true)
                    ->orWhere('remind_traffic', true);
            })
            ->where('banned', false)
            ->whereNotNull('email')
            ->chunk($chunkSize, function ($users) use (&$statistics, $progressCallback) {
                $this->processUserChunk($users, $statistics);

                if ($progressCallback) {
                    $progressCallback();
                }

                // 定期清理内存
                if ($statistics['processed_users'] % 2500 === 0) {
                    gc_collect_cycles();
                }
            });

        return $statistics;
    }

    /**
     * 处理用户块
     */
    private function processUserChunk($users, array &$statistics): void
    {
        foreach ($users as $user) {
            try {
                $statistics['processed_users']++;
                $emailsSent = 0;

                // 到期前 N 天：带续费账单 PDF 的首张账单（24 小时内的归下面的最后提醒）
                if ($user->remind_expire && $this->shouldSendInvoiceFirst($user)) {
                    $this->sendInvoiceFirst($user);
                    $statistics['invoice_emails']++;
                    $emailsSent++;
                }

                // 检查并发送过期提醒
                if ($user->remind_expire && $this->remindExpire($user)) {
                    $statistics['expire_emails']++;
                    $emailsSent++;
                }

                // 检查并发送流量提醒
                if ($user->remind_traffic && $this->shouldSendTrafficRemind($user)) {
                    $this->remindTraffic($user);
                    $statistics['traffic_emails']++;
                    $emailsSent++;
                }

                if ($emailsSent === 0) {
                    $statistics['skipped']++;
                }

            } catch (\Exception $e) {
                $statistics['errors']++;

                Log::error('发送提醒邮件失败', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * 检查是否应该发送过期提醒
     */
    private function shouldSendExpireRemind(User $user): bool
    {
        if ($user->expired_at === NULL) {
            return false;
        }
        $expiredAt = $user->expired_at;
        $now = time();
        if (($expiredAt - 86400) < $now && $expiredAt > $now) {
            return true;
        }
        return false;
    }

    /**
     * 检查是否应该发送流量提醒
     */
    private function shouldSendTrafficRemind(User $user): bool
    {
        if ($user->transfer_enable <= 0) {
            return false;
        }

        $usedBytes = $user->u + $user->d;
        $usageRatio = $usedBytes / $user->transfer_enable;

        // 流量使用超过80%时发送提醒
        return $usageRatio >= 0.8;
    }

    public function remindTraffic(User $user)
    {
        if (!$user->remind_traffic)
            return;
        if (!$this->remindTrafficIsWarnValue($user->u, $user->d, $user->transfer_enable))
            return;
        $flag = CacheKey::get('LAST_SEND_EMAIL_REMIND_TRAFFIC', $user->id);
        if (Cache::get($flag))
            return;
        if (!Cache::put($flag, 1, 24 * 3600))
            return;

        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => __('The traffic usage in :app_name has reached 80%', [
                'app_name' => admin_setting('app_name', 'XBoard')
            ]),
            'template_name' => 'remindTraffic',
            'template_value' => [
                'name' => admin_setting('app_name', 'XBoard'),
                'url' => admin_setting('app_url')
            ]
        ]);
    }

    /**
     * 首张续费账单：到期前 billing_invoice_days 天内、且不在最后 24 小时里，同一个到期日只发一次。
     */
    private function shouldSendInvoiceFirst(User $user): bool
    {
        $days = BillingDocumentService::invoiceDays();
        if ($days <= 0 || !BillingDocumentService::invoiceEnabled() || !$user->plan_id || $user->expired_at === null) {
            return false;
        }
        $expiredAt = (int) $user->expired_at;
        $now = time();
        if ($expiredAt <= $now + 86400 || $expiredAt > $now + $days * 86400) {
            return false;
        }
        return (int) ($user->invoice_notified_at ?? 0) !== $expiredAt;
    }

    private function sendInvoiceFirst(User $user): void
    {
        if ($this->markInvoiceNotified($user, 'invoice_notified_at')) {
            SendBillingMailJob::dispatchInvoice($user, BillingDocumentService::STAGE_FIRST);
        }
    }

    /**
     * 先打标记再派发（条件更新：两个进程同时跑也只有一个能改到），标记值是到期时间戳，
     * 续费后 expired_at 变了下个周期自然再发。
     */
    private function markInvoiceNotified(User $user, string $column): bool
    {
        $expiredAt = (int) $user->expired_at;
        $changed = User::where('id', $user->id)
            ->where(fn ($q) => $q->whereNull($column)->orWhere($column, '!=', $expiredAt))
            ->update([$column => $expiredAt]);
        return $changed > 0;
    }

    /** @return bool 是否真的派发了邮件（同一个到期日的最后提醒只发一次） */
    public function remindExpire(User $user): bool
    {
        if (!$this->shouldSendExpireRemind($user)) {
            return false;
        }

        // 新版最后提醒：带续费账单 PDF、续费入口和可选套餐；关掉开关或用户没有套餐时回落旧模板
        if (BillingDocumentService::invoiceEnabled() && $user->plan_id) {
            if (!$this->markInvoiceNotified($user, 'invoice_final_notified_at')) {
                return false;
            }
            SendBillingMailJob::dispatchInvoice($user, BillingDocumentService::STAGE_FINAL);
            return true;
        }

        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => __('The service in :app_name is about to expire', [
                'app_name' => admin_setting('app_name', 'XBoard')
            ]),
            'template_name' => 'remindExpire',
            'template_value' => [
                'name' => admin_setting('app_name', 'XBoard'),
                'url' => admin_setting('app_url')
            ]
        ]);
        return true;
    }

    private function remindTrafficIsWarnValue($u, $d, $transfer_enable)
    {
        $ud = $u + $d;
        if (!$ud)
            return false;
        if (!$transfer_enable)
            return false;
        $percentage = ($ud / $transfer_enable) * 100;
        if ($percentage < 80)
            return false;
        if ($percentage >= 100)
            return false;
        return true;
    }

    /**
     * 发送邮件
     *
     * @param array $params 包含邮件参数的数组，必须包含以下字段：
     *   - email: 收件人邮箱地址
     *   - subject: 邮件主题
     *   - template_name: 邮件模板名称，例如 "welcome" 或 "password_reset"
     *   - template_value: 邮件模板变量，一个关联数组，包含模板中需要替换的变量和对应的值
     * @return array 包含邮件发送结果的数组，包含以下字段：
     *   - email: 收件人邮箱地址
     *   - subject: 邮件主题
     *   - template_name: 邮件模板名称
     *   - error: 如果邮件发送失败，包含错误信息；否则为 null
     * @throws \InvalidArgumentException 如果 $params 参数缺少必要的字段，抛出此异常
     */
    public static function sendEmail(array $params)
    {
        $email = $params['email'];
        $subject = $params['subject'];

        $templateValue = $params['template_value'] ?? [];
        $vars = is_array($templateValue) ? ($templateValue['vars'] ?? []) : [];
        $contentMode = is_array($templateValue) ? ($templateValue['content_mode'] ?? null) : null;

        if (is_array($vars) && !empty($vars)) {
            $subject = self::renderPlaceholders((string) $subject, $vars);

            if (is_array($templateValue) && isset($templateValue['content']) && is_string($templateValue['content'])) {
                $templateValue['content'] = self::renderPlaceholders($templateValue['content'], $vars);
            }
        }

        // Mass mail default: treat admin content as plain text and escape.
        if ($contentMode === 'text' && is_array($templateValue) && isset($templateValue['content']) && is_string($templateValue['content'])) {
            $templateValue['content'] = e($templateValue['content']);
        }

        $view = 'mail.' . admin_setting('email_template', 'default') . '.' . $params['template_name'];
        return self::deliver($email, $subject, $view, is_array($templateValue) ? $templateValue : []);
    }

    /**
     * 实际发信：套上后台 SMTP 配置 → Mail::send → 记 v2_mail_log。sendEmail() 与收据 / 账单任务共用。
     *
     * @param array $attachments 每项 ['name' => 文件名, 'data' => 二进制内容, 'mime' => MIME]
     * @return array{email: string, subject: string, template_name: string, error: string|null}
     */
    public static function deliver(string $email, string $subject, string $view, array $data, array $attachments = []): array
    {
        if (admin_setting('email_host')) {
            Config::set('mail.host', admin_setting('email_host', config('mail.host')));
            Config::set('mail.port', admin_setting('email_port', config('mail.port')));
            Config::set('mail.encryption', admin_setting('email_encryption', config('mail.encryption')));
            Config::set('mail.username', admin_setting('email_username', config('mail.username')));
            Config::set('mail.password', admin_setting('email_password', config('mail.password')));
            Config::set('mail.from.address', admin_setting('email_from_address', config('mail.from.address')));
            Config::set('mail.from.name', admin_setting('app_name', 'XBoard'));
        }
        try {
            Mail::send($view, $data, function ($message) use ($email, $subject, $attachments) {
                $message->to($email)->subject($subject);
                foreach ($attachments as $attachment) {
                    $message->attachData($attachment['data'], $attachment['name'], ['mime' => $attachment['mime'] ?? 'application/octet-stream']);
                }
            });
            $error = null;
        } catch (\Exception $e) {
            Log::error($e);
            $error = $e->getMessage();
        }
        $log = [
            'email' => $email,
            'subject' => $subject,
            'template_name' => $view,
            'error' => $error,
            // 故意不写 'config' => config('mail')：
            // ① v2_mail_log 表 schema 没有 config 列，Eloquent 会静默丢弃这个 key —— 没有真实写入；
            // ② 任何后续加列或开 strict mode 都会立即让 SMTP 密码以明文落库，是个潜伏地雷；
            // ③ 真要排查可以临时开 mail debug log，而不是把生产凭据持久化在业务表。
        ];
        MailLog::create($log);
        return $log;
    }
}
