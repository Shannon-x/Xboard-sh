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
 *   · 注册成功、或订单付款开通后，把用户纳入验证流程：记下 started_at，
 *     往邮箱发一封带一次性链接的邮件（/verify-email/<凭据>，凭据 128 位随机，库里只存 SHA-256，7 天有效）。
 *     同一封邮件里还附一个 6 位验证码（与链接同时签发、同时过期，库里同样只存 SHA-256）：
 *     链接点不开的人（邮件客户端禁用了链接、安全扫描先替用户点掉了一次性链接、浏览器没反应），
 *     登录面板后在提示栏里输入验证码也能完成验证；输错 5 次作废，重新发送一封即可。
 *   · 面板常驻横幅：剩余几天、重新发送（60 秒冷却、每天最多 5 封）、更换邮箱（新邮箱收到链接点开才真正换）。
 *     已验证的用户也能换邮箱：新地址记在 email_verify_pending_email，链接 / 验证码对上才换过去；
 *     这段时间账号仍是「已验证」，所以 confirm / confirmCode 的「已验证就直接回」必须给换邮箱让路（见 changingEmail）。
 *   · 宽限期（email_verify_grace_days，默认 14 天）过后进入「限制状态」，限制什么由 email_verify_restrict_mode 定：
 *       features   订阅照常，但新建工单（「支付与订单」类除外）、佣金提现、佣金转余额前必须先验证（默认）
 *       subscribe  在 features 之上再暂停订阅链接（客户端拉不到节点，节点侧也不再下发该用户）
 *       none       只提示不限制
 *     任何模式下都**不拦下单、续费、付款**（shannon 2026-10-08：「不要影响我收钱」）。
 *     支付类工单也放行：付了钱没到账的人必须找得到客服。
 *   · 到期前 email_verify_remind_days 天（默认 3）再发一封提醒；退信地址（mail_suppressed_at）不再投递，面板上直接提示换邮箱。
 *   · 用验证码注册（email_verify 开着）和 Google 登录建的账号一进来就算已验证。
 *   · 功能上线前注册的老用户由迁移直接记为已验证（来源 legacy），永远不用验证（shannon 2026-10-08）。
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
    /** 功能上线前注册的老用户，迁移时直接记为已验证 */
    public const SOURCE_LEGACY = 'legacy';

    /** 一次性链接有效期（秒） */
    public const TOKEN_TTL = 7 * 86400;
    /** 两封验证邮件之间的最短间隔（秒） */
    public const RESEND_COOLDOWN = 60;
    /** 每位用户每天最多发几封验证邮件（含换邮箱） */
    public const DAILY_LIMIT = 5;
    /** 同一个验证码最多允许输错几次，错满作废（6 位数字一共一百万种，限次之后猜中的概率是百万分之五） */
    public const CODE_MAX_ATTEMPTS = 5;

    /** 测试里替换 DNS 查询：fn(string $domain): ?bool（true 有记录 / false 没有 / null 查不到） */
    public static $dnsResolver = null;

    /** 测试里替换 MX 查询：fn(string $domain): ?array（收信主机名，小写、去掉末尾的点；null = 查不到） */
    public static $mxResolver = null;

    /**
     * 注册 / 换邮箱一律不收的域名（含子域名）：有 DNS 记录的示例域名、一次性邮箱 mail.tm 本身。
     * .test / .invalid 这类根本不存在的域名不用列，domainAcceptsMail 会拦。
     * 另外自动加上本站域名（app_url 去掉 www.），防止注册 admin@本站域名 冒充客服；
     * 后台设置 email_blocked_domains 可追加（逗号分隔）。
     */
    private const BLOCKED_DOMAINS = ['example.com', 'example.net', 'example.org', 'mail.tm'];

    /**
     * 收信服务器落在这些主机上的域名视为一次性邮箱：mail.tm 一个 MX 挂着几十个轮换域名（如 maxxspace.com），按域名封不完。
     * 后台设置 email_blocked_mx_hosts 可追加（逗号分隔）。
     */
    private const BLOCKED_MX_HOSTS = ['mail.tm'];

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

    /**
     * 有一个申请了、还没确认的新邮箱。换邮箱不看 email_verified_at：已验证的用户换邮箱时账号照样是「已验证」，
     * 只有这个字段能说明「这条链接 / 这枚验证码是来换邮箱的，不是重复点旧链接」。
     * 换成功（markVerified）、重发到当前邮箱（send 不带新地址）、到期提醒、后台重新要求验证都会把它清掉。
     */
    public static function changingEmail(User $user): bool
    {
        return $user->email_verify_pending_email !== null && $user->email_verify_pending_email !== '';
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

    /** 工单 / 佣金提现 / 佣金转余额这些功能此刻是否被挡（下单、续费、付款永远不挡） */
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

    /** 受限时仍可新建的工单分类：付款没到账、订单出问题的人必须找得到客服 */
    public const TICKET_CATEGORIES_ALWAYS_ALLOWED = ['billing'];

    /** 新建工单入口：支付类工单放行，其余同 assertAllowed */
    public static function assertTicketAllowed(User $user, ?string $category): void
    {
        if ($category !== null && in_array($category, self::TICKET_CATEGORIES_ALWAYS_ALLOWED, true)) {
            return;
        }
        self::assertAllowed($user);
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
        // 验证码还有用的两种情形：还没验证（注册 / 付款后那封）、或正在换邮箱（新地址那封）—— 后者与已验证与否无关
        $awaitingCode = $pending || (self::enabled() && self::changingEmail($user));
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
            // 面板上的验证码输入框只在「有一个还没过期、还没错满的验证码」时出现
            'code_sent' => $awaitingCode && self::codeUsable($user),
            'code_attempts_left' => $awaitingCode && self::codeUsable($user)
                ? max(0, self::CODE_MAX_ATTEMPTS - (int) $user->email_verify_code_attempts)
                : 0,
        ];
    }

    /** 库里有验证码、链接周期没过期、还没错满 */
    private static function codeUsable(User $user): bool
    {
        if ($user->email_verify_code === null) {
            return false;
        }
        if ($user->email_verify_token_expires_at !== null && (int) $user->email_verify_token_expires_at < time()) {
            return false;
        }
        return (int) $user->email_verify_code_attempts < self::CODE_MAX_ATTEMPTS;
    }

    /** 验证码存库的形态：带用户 id 的 SHA-256，库里不存明文 */
    private static function codeHash(User $user, string $code): string
    {
        return hash('sha256', $user->id . ':' . $code);
    }

    /** 6 位数字验证码，首位允许 0 */
    private static function newCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
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
        // 链接凭据刻意不清：邮件 App / QQ 内置浏览器常把验证页加载两次（预加载一次、用户看到的一次），
        // 用户再点一次旧链接也很常见。线上 97% 的 confirm 404 都发生在同一人刚成功之后几秒到几分钟内，
        // 页面却显示「链接已失效」，用户以为没验上。凭据在到期前继续被 confirm() 认出，只回「已验证」、
        // 不再改任何东西（见 confirm）。验证码则立刻作废。
        $data = [
            'email_verified_at' => time(),
            'email_verify_code' => null,
            'email_verify_code_attempts' => 0,
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
            'email_verify_code' => null,
            'email_verify_code_attempts' => 0,
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
        $code = self::newCode();
        $data = [
            'email_verify_token' => hash('sha256', $token),
            'email_verify_token_expires_at' => $now + self::TOKEN_TTL,
            'email_verify_code' => self::codeHash($user, $code),
            'email_verify_code_attempts' => 0,
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
                'code' => $code,
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
        $code = self::newCode();
        $data = [
            'email_verify_token' => hash('sha256', $token),
            'email_verify_token_expires_at' => $now + self::TOKEN_TTL,
            'email_verify_code' => self::codeHash($user, $code),
            'email_verify_code_attempts' => 0,
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
                'code' => $code,
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
        if (self::domainBlocked($email)) {
            throw new ApiException(__('This email domain is not supported, please use another email address'), 422);
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
        // 已经验证过的用户再点同一条链接（页面二次加载 / 再点一次）：幂等地回「已验证」，什么都不改。
        // 但「已验证」不等于「没事可做」：已验证的用户换邮箱时账号本来就是已验证状态，新地址还挂在
        // email_verify_pending_email 上等着换 —— 这条链接是来换邮箱的，必须往下走。换成功后 pending 清空，
        // 同一条链接再点一次又回到这里，照样幂等地回「已验证」（#3582：之前这里不看 pending，已验证的人永远换不成）。
        if (self::isVerified($user) && !self::changingEmail($user)) {
            return ['user' => $user, 'changed' => false];
        }
        return self::complete($user);
    }

    /**
     * 面板里输入邮件中的 6 位验证码（用户已登录，验证码只对自己这个账号有效）。
     * 成功返回 ['user' => User, 'changed' => bool]；没有可用的验证码 / 已过期 / 已错满 → 抛业务异常，
     * 输错 → 记一次并抛业务异常（错满 5 次作废，必须重新发送）。
     */
    public static function confirmCode(User $user, string $code): array
    {
        $code = trim($code);
        if (!preg_match('/^[0-9]{6}$/', $code)) {
            throw new ApiException(__('Please enter the 6-digit verification code from the email'), 422);
        }
        // 同 confirm：已验证但正在换邮箱的，验证码是新地址那封里的，要继续核对（#3582）
        if (self::isVerified($user) && !self::changingEmail($user)) {
            throw new ApiException(__('Your email address is already verified'), 400);
        }
        if ($user->email_verify_code === null) {
            throw new ApiException(__('No verification code is active, please send a new verification email'), 400);
        }
        if ($user->email_verify_token_expires_at !== null && (int) $user->email_verify_token_expires_at < time()) {
            throw new ApiException(__('The verification code has expired, please send a new verification email'), 400);
        }
        if ((int) $user->email_verify_code_attempts >= self::CODE_MAX_ATTEMPTS) {
            throw new ApiException(__('Too many wrong attempts, please send a new verification email'), 400);
        }
        if (!hash_equals((string) $user->email_verify_code, self::codeHash($user, $code))) {
            $attempts = (int) $user->email_verify_code_attempts + 1;
            // 错满作废：验证码本身也清掉，面板的输入框随之收起
            $data = $attempts >= self::CODE_MAX_ATTEMPTS
                ? ['email_verify_code' => null, 'email_verify_code_attempts' => $attempts]
                : ['email_verify_code_attempts' => $attempts];
            User::where('id', $user->id)->update($data);
            $user->forceFill($data);
            if ($attempts >= self::CODE_MAX_ATTEMPTS) {
                throw new ApiException(__('Too many wrong attempts, please send a new verification email'), 400);
            }
            throw new ApiException(__('The verification code is incorrect, :n attempts left', ['n' => self::CODE_MAX_ATTEMPTS - $attempts]), 422);
        }
        return self::complete($user);
    }

    /**
     * 链接或验证码对上之后的共同收尾：换邮箱的把新地址换上去，记已验证，退信暂停解除。
     * 换邮箱时再查一次新地址有没有被别人注册掉。
     */
    private static function complete(User $user): array
    {
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
        // 链接能点开 / 验证码能抄过来，说明这个地址收得到信：之前因退信暂停的投递可以恢复
        if ($user->mail_suppressed_at !== null) {
            DeliveryMonitor::unsuppress($user);
        }
        return ['user' => $user, 'changed' => $changed];
    }

    // ---------------------------------------------------------------- MX

    /**
     * 注册 / 换邮箱时拒收的邮箱：示例域名、本站域名、一次性邮箱（按收信服务器识别），
     * 以及声明了 null MX（RFC 7505，「.」）的域名——example.com 就是这样，它有 A 记录，domainAcceptsMail 拦不住。
     * 2026-10 的渗透号就是用 admin@本站域名、*@example.com、mail.tm 系列批量注册的。
     * MX 部分受 email_verify_mx_check 开关控制，DNS 查不到结果时放行。已注册的老用户不受影响（只在注册 / 换邮箱时查）。
     */
    public static function domainBlocked(string $email): bool
    {
        $at = strrpos($email, '@');
        $domain = $at === false ? '' : rtrim(strtolower(substr($email, $at + 1)), '.');
        if ($domain === '') {
            return false;
        }

        $blockedDomains = self::BLOCKED_DOMAINS;
        $siteHost = strtolower((string) parse_url((string) admin_setting('app_url', ''), PHP_URL_HOST));
        if ($siteHost !== '') {
            $blockedDomains[] = preg_replace('/^www\./', '', $siteHost);
        }
        foreach (array_merge($blockedDomains, self::listSetting('email_blocked_domains')) as $blocked) {
            if ($domain === $blocked || str_ends_with($domain, '.' . $blocked)) {
                return true;
            }
        }

        if (!self::mxCheckEnabled()) {
            return false;
        }
        $mxHosts = self::mxHosts($domain);
        if ($mxHosts === null) {
            return false;
        }
        $blockedHosts = array_merge(self::BLOCKED_MX_HOSTS, self::listSetting('email_blocked_mx_hosts'));
        foreach ($mxHosts as $host) {
            if ($host === '') {
                return true;
            }
            foreach ($blockedHosts as $blocked) {
                if ($host === $blocked || str_ends_with($host, '.' . $blocked)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return string[]|null 收信主机名；没有 MX 记录或查询失败返回 null（是否收信由 domainAcceptsMail 判断） */
    private static function mxHosts(string $domain): ?array
    {
        if (self::$mxResolver !== null) {
            return (self::$mxResolver)($domain);
        }
        try {
            $records = @dns_get_record($domain . '.', DNS_MX);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($records) || !$records) {
            return null;
        }
        return array_map(static fn (array $r) => rtrim(strtolower((string) ($r['target'] ?? '')), '.'), $records);
    }

    /** @return string[] 后台逗号分隔 / 数组形态的域名列表，统一成小写、去掉前导 @ 与点 */
    private static function listSetting(string $key): array
    {
        $raw = admin_setting($key, []);
        $items = is_array($raw) ? $raw : preg_split('/[\s,，;]+/u', (string) $raw);
        $list = [];
        foreach ($items ?: [] as $item) {
            $item = trim(strtolower((string) $item), " \t\n\r@.");
            if ($item !== '') {
                $list[] = $item;
            }
        }
        return $list;
    }

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
