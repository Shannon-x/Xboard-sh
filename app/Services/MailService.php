<?php

namespace App\Services;

use App\Jobs\SendBillingMailJob;
use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Services\Billing\BillingDocumentService;
use App\Services\Mail\DeliveryMonitor;
use App\Services\Notification\NotificationPreference;
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
            'expired_emails' => 0,
            'winback_emails' => 0,
            'errors' => 0,
            'skipped' => 0,
        ];

        User::select('id', 'email', 'expired_at', 'transfer_enable', 'u', 'd', 'remind_expire', 'remind_traffic',
            'plan_id', 'invoice_notified_at', 'invoice_final_notified_at', 'lifecycle_stage', 'lifecycle_expiry',
            'mail_suppressed_at', 'auto_renew', 'balance', 'traffic_notified_level', 'next_reset_at')
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

                // 到期之后：当天的「服务已暂停」（账单类，跟 remind_expire），之后按配置的天数发挽回邮件
                // （营销类，看用户有没有关掉「营销与活动」）；同一到期日每档只发一次
                if (($stage = $this->lifecycleStageDue($user)) !== null
                    && ($stage === 1 ? (bool) $user->remind_expire : NotificationPreference::allows($user, NotificationPreference::MARKETING))
                    && $this->markLifecycle($user, $stage)) {
                    SendBillingMailJob::dispatchLifecycle($user, $stage);
                    $statistics[$stage === 1 ? 'expired_emails' : 'winback_emails']++;
                    $emailsSent++;
                }

                // 流量提醒：同一周期「用到阈值」「用完」各一封，用量回落后自动重新武装。
                // 预警那封不和当天的账单 / 到期邮件叠发（明天再说），「用完」关系到服务可用性，照发
                if ($user->remind_traffic && ($stage = $this->trafficStageDue($user)) !== null
                    && ($emailsSent === 0 || $stage === 2)
                    && $this->markTraffic($user, $stage)) {
                    $this->remindTraffic($user, $stage);
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
     * 这个周期该发哪一档流量提醒：1 = 用到阈值（默认 80%），2 = 用完；null = 不发。
     * traffic_notified_level 记本周期已发到的档位；用量回落到阈值以下（流量重置、升级套餐、后台加流量）时清零，
     * 下个周期从头再来。直接到 100% 的用户只收「用完」那一封，不补发预警。
     */
    private function trafficStageDue(User $user): ?int
    {
        $total = (int) $user->transfer_enable;
        $level = (int) ($user->traffic_notified_level ?? 0);
        if ($total <= 0) {
            return null;
        }
        $percent = ((int) $user->u + (int) $user->d) * 100 / $total;
        if ($percent < BillingDocumentService::trafficWarnPercent()) {
            if ($level > 0) {
                User::where('id', $user->id)->update(['traffic_notified_level' => 0]);
                $user->setAttribute('traffic_notified_level', 0);
            }
            return null;
        }
        if ($percent >= 100) {
            return $level < 2 && BillingDocumentService::trafficExhaustedEnabled() ? 2 : null;
        }
        return $level < 1 ? 1 : null;
    }

    /** 先打标记再派发（条件更新，两个进程同时跑也只有一个能改到） */
    private function markTraffic(User $user, int $stage): bool
    {
        $changed = User::where('id', $user->id)
            ->where(fn ($q) => $q->whereNull('traffic_notified_level')->orWhere('traffic_notified_level', '<', $stage))
            ->update(['traffic_notified_level' => $stage, 'traffic_notified_at' => time()]);
        if ($changed > 0) {
            $user->setAttribute('traffic_notified_level', $stage);
        }
        return $changed > 0;
    }

    /**
     * 派发流量提醒。开着收据 / 账单邮件时走 billing 模板（带用量、重置日期、加购入口）；
     * 关掉时回落老的 remindTraffic 模板，只有预警这一档（老模板没有「用完」的文案）。
     */
    public function remindTraffic(User $user, int $stage = 1): void
    {
        if (BillingDocumentService::receiptEnabled()) {
            SendBillingMailJob::dispatchTraffic($user, $stage === 2 ? BillingDocumentService::TRAFFIC_EXHAUSTED : BillingDocumentService::TRAFFIC_WARN);
            return;
        }
        if ($stage !== 1 || $user->mail_suppressed_at !== null) {
            return;   // 老模板直接发邮件：退信标记的用户不再往 SMTP 塞
        }
        SendEmailJob::dispatch([
            'email' => $user->email,
            'user_id' => $user->id,
            'category' => NotificationPreference::USAGE,
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

    /**
     * 到期后该发哪一档：1 = 到期 2 天内的「服务已暂停」，2+ = billing_winback_days 里第 N 档挽回（各留 2 天窗口，
     * 扫描一天跑一次，漏跑一天也补得上）。lifecycle_* 记的是到期时间戳：续费后 expired_at 变了，序列自然重头开始。
     */
    private function lifecycleStageDue(User $user): ?int
    {
        if (!$user->plan_id || !$user->expired_at) {
            return null;
        }
        $expiredAt = (int) $user->expired_at;
        $now = time();
        if ($expiredAt > $now) {
            return null;
        }
        $elapsedDays = ($now - $expiredAt) / 86400;
        $current = (int) $user->lifecycle_expiry === $expiredAt ? (int) $user->lifecycle_stage : 0;
        if ($current < 1 && $elapsedDays <= 2 && BillingDocumentService::expiredEnabled() && !$this->autoRenewPending($user)) {
            return 1;
        }
        if (BillingDocumentService::winbackEnabled()) {
            foreach (BillingDocumentService::winbackDays() as $i => $days) {
                $stage = $i + 2;
                if ($current < $stage && $elapsedDays >= $days && $elapsedDays < $days + 2) {
                    return $stage;
                }
            }
        }
        return null;
    }

    /** 自动续费还在宽限期内且余额够：每小时跑的 renew:auto 马上会续上，这时发「已暂停」只是噪音。 */
    private function autoRenewPending(User $user): bool
    {
        if (!$user->auto_renew || !RenewService::siteEnabled()) {
            return false;
        }
        if (time() - (int) $user->expired_at > RenewService::graceHours() * 3600) {
            return false;
        }
        $full = User::find($user->id);
        if (!$full) {
            return false;
        }
        $spec = app(RenewService::class)->resolveSpec($full);
        return $spec['available'] && (int) ($full->balance ?? 0) >= (int) $spec['amount'];
    }

    private function markLifecycle(User $user, int $stage): bool
    {
        $expiredAt = (int) $user->expired_at;
        $changed = User::where('id', $user->id)
            ->where(fn ($q) => $q->whereNull('lifecycle_expiry')
                ->orWhere('lifecycle_expiry', '!=', $expiredAt)
                ->orWhere('lifecycle_stage', '<', $stage))
            ->update(['lifecycle_expiry' => $expiredAt, 'lifecycle_stage' => $stage]);
        return $changed > 0;
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

        if ($user->mail_suppressed_at !== null) {
            return false;   // 老模板直接发邮件：退信标记的用户不再往 SMTP 塞
        }
        SendEmailJob::dispatch([
            'email' => $user->email,
            'user_id' => $user->id,
            'category' => NotificationPreference::BILLING,
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

    /**
     * 发送邮件
     *
     * @param array $params 包含邮件参数的数组，必须包含以下字段：
     *   - email: 收件人邮箱地址
     *   - subject: 邮件主题
     *   - template_name: 邮件模板名称，例如 "welcome" 或 "password_reset"
     *   - template_value: 邮件模板变量，一个关联数组，包含模板中需要替换的变量和对应的值
     *   - user_id: 收件用户 id（可选；不传按 email 反查）
     *   - category: 通知类别（可选，见 NotificationPreference::CATEGORIES）。带类别的邮件在这里统一过一遍用户偏好：
     *     用户关掉了这一类就不发（返回 skipped=true，不记日志），模板里多一个 manage_url 页脚链接，
     *     批量类别再带 List-Unsubscribe 头。不带类别 = 交易类，永远发。
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
        $userId = isset($params['user_id']) ? (int) $params['user_id'] : null;
        $category = isset($params['category']) ? (string) $params['category'] : null;
        if (NotificationPreference::isCategory($category)) {
            $user = $userId ? User::find($userId) : User::where('email', $email)->first();
            if ($user && !NotificationPreference::allows($user, $category)) {
                return ['email' => $email, 'subject' => $subject, 'template_name' => $view, 'error' => null, 'category' => 'skipped', 'skipped' => true];
            }
            $userId = $user?->id ?? $userId;
        }
        return self::deliver($email, $subject, $view, is_array($templateValue) ? $templateValue : [], [], $userId, $category);
    }

    /**
     * 实际发信：套上后台 SMTP 配置 → Mail::send → 记 v2_mail_log。sendEmail() 与收据 / 账单任务共用。
     *
     * @param array $attachments 每项 ['name' => 文件名, 'data' => 二进制内容, 'mime' => MIME]
     * @param int|null $userId 收件用户 id；不传按 email 反查。投递结果会记到该用户头上（退信标记，见 DeliveryMonitor）
     * @param string|null $category 通知类别（NotificationPreference::CATEGORIES）。偏好本身由调用方（sendEmail / SendBillingMailJob）
     *   先判过；这里只负责类别带来的两样东西：模板变量 manage_url / manage_label（页脚那行「管理通知偏好」的免登录链接），
     *   以及批量类别的 List-Unsubscribe / List-Unsubscribe-Post 头（RFC 8058 一键退订，Gmail / Yahoo 对批量发件人的要求）。
     * @return array{email: string, subject: string, template_name: string, error: string|null, category: string}
     */
    public static function deliver(string $email, string $subject, string $view, array $data, array $attachments = [], ?int $userId = null, ?string $category = null): array
    {
        $headers = [];
        if (NotificationPreference::isCategory($category)) {
            $user = $userId ? User::find($userId) : User::where('email', $email)->first();
            if ($user) {
                $userId = (int) $user->id;
                $data['manage_url'] = NotificationPreference::manageUrl($user);
                $data['manage_label'] = NotificationPreference::footerLabel();
                $data['manage_category'] = $category;
                if (isset($data['settings_url'])) {
                    $data['settings_url'] = $data['manage_url'];   // 收据 / 账单模板的页脚链接也换成免登录偏好页
                }
                if (in_array($category, NotificationPreference::BULK, true) && NotificationPreference::listUnsubscribeEnabled()) {
                    $headers['List-Unsubscribe'] = '<' . NotificationPreference::unsubscribeUrl($user, $category) . '>';
                    $headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
                }
            }
        }
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
            Mail::send($view, $data, function ($message) use ($email, $subject, $attachments, $headers) {
                $message->to($email)->subject($subject);
                foreach ($headers as $name => $value) {
                    $message->getHeaders()->addTextHeader($name, $value);
                }
                foreach ($attachments as $attachment) {
                    $message->attachData($attachment['data'], $attachment['name'], ['mime' => $attachment['mime'] ?? 'application/octet-stream']);
                }
            });
            $error = null;
        } catch (\Exception $e) {
            Log::error($e);
            $error = $e->getMessage();
        }
        // 记 v2_mail_log 并维护用户的退信标记。故意不记 config('mail')：那会把 SMTP 密码明文落库。
        $category = DeliveryMonitor::record($email, $subject, $view, $error, $userId);
        return [
            'email' => $email,
            'subject' => $subject,
            'template_name' => $view,
            'error' => $error,
            'category' => $category,
        ];
    }
}
