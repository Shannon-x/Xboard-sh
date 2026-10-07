<?php

namespace App\Services\Mail;

use App\Models\MailLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * 邮件投递闭环：每条 v2_mail_log 打分类，按分类维护用户的「暂停投递」标记。
 *
 * 分类：
 *   ok          交给 SMTP 成功（是否真进收件箱 SMTP 不会告诉我们）
 *   suppressed  收件方把地址列入抑制名单（OCI Email Delivery 的 "4.7.1 … suppressed"、SES suppression list 同理）
 *   bounce      永久拒收：5.1.x 用户不存在、5.2.x 邮箱问题、5.7.x 策略拒收、550 / 551 / 553 / 554
 *   temporary   临时失败：4xx、超时、对方繁忙
 *   config      我们自己的问题：SMTP 认证失败、连不上服务器、TLS 证书 —— 与收件人无关，不算在用户头上
 *
 * 标记规则：suppressed / bounce 一次即标记；temporary 要在不同时段累计 3 次才标记 —— 一小时内的临时失败只算一次，
 * 否则同一封信的 3 次队列重试（几秒到一分钟内）就会把人标上，赶上发信高峰时 OCI 偶发超时会误标一批用户；
 * 任何一次成功立即清零并解除。
 * 标记只拦系统主动发的邮件（收据、账单、提醒、挽回），用户自己点的验证码 / 登录链接照发 ——
 * 既是用户当下要用，也是修好邮箱之后自动解除标记的机会。
 */
final class DeliveryMonitor
{
    public const OK = 'ok';
    public const SUPPRESSED = 'suppressed';
    public const BOUNCE = 'bounce';
    public const TEMPORARY = 'temporary';
    public const CONFIG = 'config';

    public const TEMPORARY_FAILURES_TO_SUPPRESS = 3;

    /** 临时失败的计数窗口（秒）：上次计数后这段时间内的临时失败不再累加 */
    public const TEMPORARY_FAILURE_WINDOW = 3600;

    public static function classify(?string $error): string
    {
        if ($error === null || trim($error) === '') {
            return self::OK;
        }
        $e = strtolower($error);
        if (str_contains($e, 'suppress')) {
            return self::SUPPRESSED;
        }
        if (preg_match('/authenticat|\b535\b|connection could not be established|could not connect|failed to connect|getaddrinfo|name or service not known|network is unreachable|\b(ssl|tls)\b|certificate/', $e)) {
            return self::CONFIG;
        }
        if (preg_match('/\b5\.[1247]\.\d+\b|\b55[0134]\b|user unknown|unknown user|no such (user|recipient|mailbox)|does not exist|not exist|mailbox (unavailable|not found|disabled|full)|recipient (address )?rejected|invalid recipient|address rejected|invalid address|bad destination/', $e)) {
            return self::BOUNCE;
        }
        return self::TEMPORARY;
    }

    public static function isHardFailure(string $category): bool
    {
        return in_array($category, [self::SUPPRESSED, self::BOUNCE], true);
    }

    /** 失败了值不值得重试：临时错误和我们自己的配置问题值得，退信不值得。 */
    public static function isRetryable(string $category): bool
    {
        return in_array($category, [self::TEMPORARY, self::CONFIG], true);
    }

    /**
     * 记 v2_mail_log 并维护用户标记。$userId 不传时按 email 反查（一封邮件一次查询，量级可以接受）。
     *
     * @return string 分类（self::OK / …）
     */
    public static function record(string $email, string $subject, string $view, ?string $error, ?int $userId = null): string
    {
        $category = self::classify($error);
        $user = $userId ? User::find($userId) : User::where('email', $email)->first();
        try {
            MailLog::create([
                'email' => $email,
                'user_id' => $user?->id,
                'subject' => $subject,
                'template_name' => $view,
                'error' => $error,
                'status' => $category === self::OK ? 1 : 0,
                'category' => $category === self::OK ? null : $category,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[mail] 写 v2_mail_log 失败', ['email' => $email, 'error' => $e->getMessage()]);
        }
        if ($user) {
            self::touchUser($user, $category);
        }
        return $category;
    }

    private static function touchUser(User $user, string $category): void
    {
        if ($category === self::OK) {
            if ((int) ($user->mail_failed_count ?? 0) === 0 && $user->mail_suppressed_at === null) {
                return;
            }
            self::unsuppress($user);
            return;
        }
        if ($category === self::CONFIG) {
            return;
        }
        $now = time();
        if ($category === self::TEMPORARY && (int) ($user->mail_failed_count ?? 0) > 0
            && $user->mail_failed_at !== null && $now - (int) $user->mail_failed_at < self::TEMPORARY_FAILURE_WINDOW) {
            return;   // 同一封信的重试、同一轮扫描里的几封信：已经记过一次，日志照记，计数不再加
        }
        $count = (int) ($user->mail_failed_count ?? 0) + 1;
        $data = ['mail_failed_count' => $count, 'mail_failed_at' => $now];
        if ($user->mail_suppressed_at === null && (self::isHardFailure($category) || $count >= self::TEMPORARY_FAILURES_TO_SUPPRESS)) {
            $data['mail_suppressed_at'] = $now;
            $data['mail_suppressed_reason'] = $category;
            Log::warning('[mail] 用户邮箱标记为暂停投递', ['user_id' => $user->id, 'email' => $user->email, 'reason' => $category, 'failed_count' => $count]);
        }
        User::where('id', $user->id)->update($data);
        $user->forceFill($data);
    }

    /** 系统主动发的邮件要不要跳过这个用户 */
    public static function suppressed(User $user): bool
    {
        return $user->mail_suppressed_at !== null;
    }

    public static function unsuppress(User $user): void
    {
        $data = ['mail_failed_count' => 0, 'mail_failed_at' => null, 'mail_suppressed_at' => null, 'mail_suppressed_reason' => null];
        User::where('id', $user->id)->update($data);
        $user->forceFill($data);
    }
}
