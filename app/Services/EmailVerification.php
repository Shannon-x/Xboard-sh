<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Services\Mail\DeliveryMonitor;
use App\Services\Notification\NotificationPreference;
use App\Utils\Dict;
use App\Utils\Helper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * 邮箱软验证：注册与购买流程一步不加，事后补验。
 *
 *   · 注册成功、或订单付款开通后（老用户下一次付款时才轮到），把用户纳入验证流程：记下 started_at，
 *     往邮箱发一封带一次性链接的邮件（/verify-email/<凭据>，凭据 128 位随机，库里只存 SHA-256，7 天有效）。
 *   · 面板常驻横幅：剩余几天、重新发送（60 秒冷却、每天最多 5 封）、更换邮箱（新邮箱收到链接点开才真正换）。
 *   · 宽限期（email_verify_grace_days，默认 14 天）过后进入「限制状态」，限制什么由 email_verify_restrict_mode 定：
 *       features   订阅照常，但新下单 / 续费、提交工单、申请提现前必须先验证（默认）
 *       subscribe  在 features 之上再暂停订阅链接（客户端拉不到节点，节点侧也不再下发该用户）
 *       none       只提示不限制
 *   · 到期前 email_verify_remind_days 天（默认 3）再发一封提醒；退信地址（mail_suppressed_at）不再投递，面板上直接提示换邮箱。
 *   · 用验证码注册（email_verify 开着）和 Google 登录建的账号一进来就算已验证。
 *   · 注册时可选查域名 MX（email_verify_mx_check，默认开）：域名连收信服务器都没有的直接拒绝，DNS 查不到结果时放行。
 *
 * 验证邮件属于交易类通知（与验证码同级），不经过通知偏好闸门。
 */
final class EmailVerification
{
    public const MODE_FEATURES = 'features';
    public const MODE_SUBSCRIBE = 'subscribe';
    public const MODE_NONE = 'none';
    public const MODES = [self::MODE_FEATURES, self::MODE_SUBSCRIBE, self::MODE_NONE];

    public const SOURCE_REGISTER = 'register';
    public const SOURCE_ORDER = 'order';
    public const SOURCE_ADMIN = 'admin';
    public const SOURCE_CHANGE = 'change';

    /** 一次性链接有效期（秒） */
    public const TOKEN_TTL = 7 * 86400;
    /** 两封验证邮件之间的最短间隔（秒） */
    public const RESEND_COOLDOWN = 60;
    /** 每位用户每天最多发几封验证邮件（含换邮箱） */
    public const DAILY_LIMIT = 5;

    /** 测试里替换 DNS 查询：fn(string $domain): ?bool（true 有记录 / false 没有 / null 查不到） */
    public static $dnsResolver = null;

    // ---------------------------------------------------------------- 后台设置

    public static function enabled(): bool
    {
        return (bool) (int) admin_setting('email_verify_nudge_enable', 1);
    }

    public static function graceDays(): int
    {
        return max(1, min(90, (int) admin_setting('email_verify_grace_days', 14)));
    }

    public static function remindDaysBefore(): int
    {
        return max(0, min(30, (int) admin_setting('email_verify_remind_days', 3)));
    }

    public static function restrictMode(): string
    {
        $mode = (string) admin_setting('email_verify_restrict_mode', self::MODE_FEATURES);
        return in_array($mode, self::MODES, true) ? $mode : self::MODE_FEATURES;
    }

    public static function mxCheckEnabled(): bool
    {
        return (bool) (int) admin_setting('email_verify_mx_check', 1);
    }

    // ---------------------------------------------------------------- 状态

    public static function isVerified(User $user): bool
    {
        return $user->email_verified_at !== null;
    }

    /** 已纳入流程且还没验证 */
    public static function pending(User $user): bool
    {
        return !self::isVerified($user) && $user->email_verify_started_at !== null;
    }

    public static function dueAt(User $user): ?int
    {
        return $user->email_verify_started_at === null ? null : (int) $user->email_verify_started_at + self::graceDays() * 86400;
    }

    /** 宽限期已过、且后台没把限制关掉 */
    public static function restricted(User $user): bool
    {
        if (!self::enabled() || !self::pending($user) || self::restrictMode() === self::MODE_NONE) {
            return false;
        }
        return time() >= self::dueAt($user);
    }

    /** 新下单 / 工单 / 提现这些功能此刻是否被挡 */
    public static function blocksFeatures(User $user): bool
    {
        return self::restricted($user);
    }

    /** 订阅链接与节点下发是否被挡（只有 subscribe 模式） */
    public static function blocksSubscribe(User $user): bool
    {
        return self::restrictMode() === self::MODE_SUBSCRIBE && self::restricted($user);
    }

    /** 功能入口统一调用：被挡就抛业务异常，前端据 reason 弹验证面板 */
    public static function assertAllowed(User $user): void
    {
        if (self::blocksFeatures($user)) {
            throw new ApiException(__('Please verify your email address first'), 403, ['reason' => 'email_unverified']);
        }
    }

    /**
     * 节点下发用户列表的 SQL 条件（subscribe 模式下排除受限用户）。
     * 受限 = 纳入流程、未验证、started_at + 宽限期 <= 现在。
     */
    public static function scopeNotBlocked(Builder|QueryBuilder $query): Builder|QueryBuilder
    {
        if (!self::enabled() || self::restrictMode() !== self::MODE_SUBSCRIBE) {
            return $query;
        }
        $threshold = time() - self::graceDays() * 86400;
        return $query->where(function ($q) use ($threshold) {
            $q->whereNotNull('email_verified_at')
                ->orWhereNull('email_verify_started_at')
                ->orWhere('email_verify_started_at', '>', $threshold);
        });
    }

    /** /user/info 里的 email_verification 块 */
    public static function view(User $user): array
    {
        $now = time();
        $due = self::dueAt($user);
        $pending = self::enabled() && self::pending($user);
        $resendAfter = $user->email_verify_sent_at ? (int) $user->email_verify_sent_at + self::RESEND_COOLDOWN : 0;
        return [
            'verified' => self::isVerified($user),
            'pending' => $pending,
            'started_at' => $user->email_verify_started_at ? (int) $user->email_verify_started_at : null,
            'due_at' => $pending ? $due : null,
            'days_left' => $pending && $due ? max(0, (int) ceil(($due - $now) / 86400)) : null,
            'restricted' => self::restricted($user),
            'restrict_mode' => self::restrictMode(),
            'resend_after' => $resendAfter > $now ? $resendAfter : 0,
            'pending_email' => $user->email_verify_pending_email ? NotificationPreference::maskEmail((string) $user->email_verify_pending_email) : null,
            'mail_suppressed' => $user->mail_suppressed_at !== null,
        ];
    }

    // ---------------------------------------------------------------- 纳入 / 通过

    /**
     * 纳入验证流程并发第一封邮件。已验证、已纳入、功能关闭时什么也不做。
     * 注册与付款开通都从这里进来；付款开通在订单事务提交之后调用。
     */
    public static function start(User $user, string $source, bool $sendMail = true): bool
    {
        if (!self::enabled() || self::isVerified($user) || $user->email_verify_started_at !== null || !$user->email) {
            return false;
        }
        $now = time();
        $updated = User::where('id', $user->id)->whereNull('email_verify_started_at')->whereNull('email_verified_at')
            ->update(['email_verify_started_at' => $now, 'email_verify_source' => $source]);
        if (!$updated) {
            return false;
        }
        $user->forceFill(['email_verify_started_at' => $now, 'email_verify_source' => $source]);
        if ($sendMail && $user->mail_suppressed_at === null) {
            self::send($user, null, false);
        }
        return true;
    }

    public static function markVerified(User $user, ?string $source = null): void
    {
        $data = [
            'email_verified_at' => time(),
            'email_verify_token' => null,
            'email_verify_token_expires_at' => null,
            'email_verify_pending_email' => null,
        ];
        if ($source !== null && $user->email_verify_source === null) {
            $data['email_verify_source'] = $source;
        }
        User::where('id', $user->id)->update($data);
        $user->forceFill($data);
    }

    /** 后台「重新要求验证」：清掉已验证标记并重新起算宽限期 */
    public static function reset(User $user): void
    {
        $data = [
            'email_verified_at' => null,
            'email_verify_started_at' => time(),
            'email_verify_source' => self::SOURCE_ADMIN,
            'email_verify_token' => null,
            'email_verify_token_expires_at' => null,
            'email_verify_reminded_at' => null,
            'email_verify_pending_email' => null,
        ];
        User::where('id', $user->id)->update($data);
        $user->forceFill($data);
    }

    // ---------------------------------------------------------------- 发信

    /**
     * 发（或重发）验证邮件。$newEmail 非空 = 换邮箱：链接发到新地址，点开后才把 email 换过去。
     * 返回 ['sent' => bool, 'resend_after' => 时间戳]；超过冷却 / 日限时 $strict 下抛业务异常，否则静默不发。
     */
    public static function send(User $user, ?string $newEmail = null, bool $strict = true): array
    {
        $now = time();
        if ($user->email_verify_sent_at && $now - (int) $user->email_verify_sent_at < self::RESEND_COOLDOWN) {
            if ($strict) {
                throw new ApiException(__('Verification email was just sent, please try again later'), 429);
            }
            return ['sent' => false, 'resend_after' => (int) $user->email_verify_sent_at + self::RESEND_COOLDOWN];
        }
        $dayKey = 'email_verify:daily:' . $user->id . ':' . date('Ymd', $now);
        $count = (int) Cache::get($dayKey, 0);
        if ($count >= self::DAILY_LIMIT) {
            if ($strict) {
                throw new ApiException(__('Too many verification emails today, please try again tomorrow'), 429);
            }
            return ['sent' => false, 'resend_after' => strtotime('tomorrow', $now)];
        }
        Cache::put($dayKey, $count + 1, 86400);

        $token = bin2hex(random_bytes(16));
        $data = [
            'email_verify_token' => hash('sha256', $token),
            'email_verify_token_expires_at' => $now + self::TOKEN_TTL,
            'email_verify_sent_at' => $now,
            'email_verify_pending_email' => $newEmail,
        ];
        User::where('id', $user->id)->update($data);
        $user->forceFill($data);

        $to = $newEmail ?: (string) $user->email;
        $name = admin_setting('app_name', 'XBoard');
        $changing = $newEmail !== null;
        $due = self::dueAt($user);
        SendEmailJob::dispatch([
            'email' => $to,
            'user_id' => (int) $user->id,
            'subject' => $changing
                ? __('Confirm your new email address for :name', ['name' => $name])
                : __('Please verify your email address for :name', ['name' => $name]),
            'template_name' => 'verifyEmail',
            'template_value' => [
                'name' => $name,
                'url' => admin_setting('app_url'),
                'link' => self::link($token),
                'email' => $to,
                'changing' => $changing,
                'reminder' => false,
                'grace_days' => self::graceDays(),
                'due_date' => $due && !$changing ? date('Y-m-d', $due) : null,
                'restrict_mode' => self::restrictMode(),
                'token_days' => (int) (self::TOKEN_TTL / 86400),
            ],
        ]);
        return ['sent' => true, 'resend_after' => $now + self::RESEND_COOLDOWN];
    }

    /** 到期前的提醒：同一条链接重发一遍（没有效链接就签一条新的），只发一次 */
    public static function sendReminder(User $user): bool
    {
        if (!self::pending($user) || $user->email_verify_reminded_at !== null || $user->mail_suppressed_at !== null) {
            return false;
        }
        $now = time();
        $token = bin2hex(random_bytes(16));
        $data = [
            'email_verify_token' => hash('sha256', $token),
            'email_verify_token_expires_at' => $now + self::TOKEN_TTL,
            'email_verify_sent_at' => $now,
            'email_verify_reminded_at' => $now,
            'email_verify_pending_email' => null,
        ];
        User::where('id', $user->id)->update($data);
        $user->forceFill($data);
        $name = admin_setting('app_name', 'XBoard');
        SendEmailJob::dispatch([
            'email' => (string) $user->email,
            'user_id' => (int) $user->id,
            'subject' => __('Reminder: verify your email address for :name', ['name' => $name]),
            'template_name' => 'verifyEmail',
            'template_value' => [
                'name' => $name,
                'url' => admin_setting('app_url'),
                'link' => self::link($token),
                'email' => (string) $user->email,
                'changing' => false,
                'reminder' => true,
                'grace_days' => self::graceDays(),
                'due_date' => date('Y-m-d', (int) self::dueAt($user)),
                'restrict_mode' => self::restrictMode(),
                'token_days' => (int) (self::TOKEN_TTL / 86400),
            ],
        ]);
        return true;
    }

    /** 到期前 N 天、还没提醒过的用户；每天由 email-verify:remind 扫一次 */
    public static function dueForReminder(): Builder
    {
        $now = time();
        $grace = self::graceDays() * 86400;
        $window = self::remindDaysBefore() * 86400;
        // started + grace - window <= now < started + grace
        return User::whereNull('email_verified_at')
            ->whereNotNull('email_verify_started_at')
            ->whereNull('email_verify_reminded_at')
            ->whereNull('mail_suppressed_at')
            ->where('banned', 0)
            ->where('email_verify_started_at', '<=', $now - $grace + $window)
            ->where('email_verify_started_at', '>', $now - $grace);
    }

    /** 用户端的验证页（相对 app_url）：/verify-email/<凭据> */
    public static function link(string $token): string
    {
        return rtrim((string) admin_setting('app_url'), '/') . '/verify-email/' . $token;
    }

    // ---------------------------------------------------------------- 换邮箱 / 确认

    /**
     * 用户在面板里申请换邮箱：核对密码，新地址走与注册相同的格式 / 白名单 / MX 检查，然后往新地址发链接。
     */
    public static function requestChange(User $user, string $newEmail, string $password): array
    {
        $newEmail = strtolower(trim($newEmail));
        if (!$user->password || !Hash::check($password, $user->password)) {
            throw new ApiException(__('The old password is wrong'), 403);
        }
        if ($newEmail === strtolower((string) $user->email)) {
            throw new ApiException(__('The new email address is the same as the current one'), 422);
        }
        self::assertEmailUsable($newEmail);
        return self::send($user, $newEmail);
    }

    /** 新邮箱可不可用：格式、白名单、MX、未被占用 */
    public static function assertEmailUsable(string $email): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 64) {
            throw new ApiException(__('Invalid email address'), 422);
        }
        if ((int) admin_setting('email_whitelist_enable', 0)
            && !Helper::emailSuffixVerify($email, admin_setting('email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT))) {
            throw new ApiException(__('Email suffix is not in the Whitelist'), 422);
        }
        if (self::mxCheckEnabled() && self::domainAcceptsMail($email) === false) {
            throw new ApiException(__('This email domain cannot receive mail, please check the address'), 422);
        }
        if (User::byEmail($email)->exists()) {
            throw new ApiException(__('Email already exists'), 422);
        }
    }

    /**
     * 点开邮件里的链接。成功返回 ['user' => User, 'changed' => bool]；凭据不认识 / 过期返回 null。
     * 换邮箱的链接点开时再查一次新地址有没有被别人注册掉。
     */
    public static function confirm(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $user = User::where('email_verify_token', hash('sha256', $token))->first();
        if (!$user) {
            return null;
        }
        if ($user->email_verify_token_expires_at !== null && (int) $user->email_verify_token_expires_at < time()) {
            return null;
        }
        $changed = false;
        $pendingEmail = $user->email_verify_pending_email ? strtolower((string) $user->email_verify_pending_email) : null;
        if ($pendingEmail !== null && $pendingEmail !== strtolower((string) $user->email)) {
            if (User::byEmail($pendingEmail)->where('id', '!=', $user->id)->exists()) {
                throw new ApiException(__('Email already exists'), 422);
            }
            User::where('id', $user->id)->update(['email' => $pendingEmail]);
            $user->setAttribute('email', $pendingEmail);
            $changed = true;
        }
        self::markVerified($user);
        // 链接能点开说明这个地址收得到信：之前因退信暂停的投递可以恢复
        if ($user->mail_suppressed_at !== null) {
            DeliveryMonitor::unsuppress($user);
        }
        return ['user' => $user, 'changed' => $changed];
    }

    // ---------------------------------------------------------------- MX

    /**
     * 域名收不收信：有 MX 记录、或退而求其次有 A / AAAA 记录就算收。
     * 返回 null = DNS 查不到结果（超时 / 异常），调用方放行，不让 DNS 故障挡住注册。
     */
    public static function domainAcceptsMail(string $email): ?bool
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return false;
        }
        $domain = rtrim(strtolower(substr($email, $at + 1)), '.');
        if ($domain === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
            return false;
        }
        if (self::$dnsResolver !== null) {
            return (self::$dnsResolver)($domain);
        }
        try {
            if (@checkdnsrr($domain . '.', 'MX')) {
                return true;
            }
            if (@checkdnsrr($domain . '.', 'A') || @checkdnsrr($domain . '.', 'AAAA')) {
                return true;
            }
            // 域名根本不存在（NXDOMAIN）与查不到结果分不开：都按「没有记录」处理，
            // 但 DNS 整体不可用时（随便查个一定存在的域都失败）放行
            if (!@checkdnsrr('gmail.com.', 'MX')) {
                return null;
            }
            return false;
        } catch (\Throwable) {
            return null;
        }
    }
}
