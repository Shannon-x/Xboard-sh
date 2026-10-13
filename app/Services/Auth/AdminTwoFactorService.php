<?php

namespace App\Services\Auth;

use App\Models\AdminAuditLog;
use App\Models\AdminTwoFactor;
use App\Models\User;
use App\Services\TelegramService;
use App\Support\Totp;
use App\Utils\CacheKey;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 管理员两步验证：登录挑战、绑定 / 重新绑定、验证码与恢复码校验、审计与 Telegram 通知。
 *
 * 流程（后台登录）：
 *   1. 邮箱 + 密码正确 → 不发 token，发一个 5 分钟有效的「挑战」（challenge）；
 *   2. 尚未绑定的管理员：用挑战换取新密钥与二维码，扫码后输入验证码 → 绑定完成，拿到恢复码与正式 token；
 *   3. 已绑定的管理员：输入验证器里的 6 位码（或一个恢复码）→ 拿到正式 token。
 * 挑战错满 CHALLENGE_MAX_ATTEMPTS 次作废，必须重新输入密码。
 *
 * 正式 token 带 admin 能力（AuthService::ABILITY_ADMIN），Admin 中间件只认它；
 * 用户面板登录、邮件链接登录、Google 登录签发的都是普通 token，永远进不了后台。
 */
class AdminTwoFactorService
{
    public const CHALLENGE_TTL = 300;
    public const CHALLENGE_MAX_ATTEMPTS = 5;
    public const RECOVERY_CODE_COUNT = 8;

    public const STATE_REQUIRED = 'required';
    public const STATE_SETUP_REQUIRED = 'setup_required';

    /** 恢复码字符集：去掉 0/O/1/I/L 这些抄写时容易混淆的字符 */
    private const RECOVERY_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    // ------------------------------------------------------------------ 状态

    public function record(User $user): ?AdminTwoFactor
    {
        return AdminTwoFactor::find($user->id);
    }

    public function isEnabled(User $user): bool
    {
        return (bool) $this->record($user)?->isEnabled();
    }

    public function status(User $user): array
    {
        $record = $this->record($user);
        return [
            'enabled' => (bool) $record?->isEnabled(),
            'confirmed_at' => $record?->confirmed_at,
            'recovery_codes_remaining' => $record?->recoveryCodesRemaining() ?? 0,
        ];
    }

    // ------------------------------------------------------------------ 登录挑战

    /**
     * 密码正确后签发挑战。返回给前端的结构不含任何 token。
     */
    public function issueChallenge(User $user, ?string $ip = null): array
    {
        $token = Str::random(48);
        $state = $this->isEnabled($user) ? self::STATE_REQUIRED : self::STATE_SETUP_REQUIRED;
        Cache::put($this->challengeKey($token), [
            'user_id' => $user->id,
            'state' => $state,
            'attempts' => 0,
            'ip' => $ip,
            'issued_at' => time(),
        ], self::CHALLENGE_TTL);

        return [
            'two_factor' => $state,
            'challenge' => $token,
            'expires_in' => self::CHALLENGE_TTL,
        ];
    }

    /** 解析挑战；过期 / 不存在 / 用户已不是管理员都返回 null */
    public function resolveChallenge(?string $token): ?array
    {
        if (!is_string($token) || strlen($token) < 32 || strlen($token) > 128) {
            return null;
        }
        $key = $this->challengeKey($token);
        $data = Cache::get($key);
        if (!is_array($data) || empty($data['user_id'])) {
            return null;
        }
        $user = User::find($data['user_id']);
        if (!$user || !$user->is_admin || $user->banned) {
            Cache::forget($key);
            return null;
        }
        return ['key' => $key, 'user' => $user, 'data' => $data];
    }

    /** 记一次失败；错满次数直接作废挑战，返回 true 表示已作废 */
    public function failChallenge(string $key, array $data): bool
    {
        $data['attempts'] = (int) ($data['attempts'] ?? 0) + 1;
        if ($data['attempts'] >= self::CHALLENGE_MAX_ATTEMPTS) {
            Cache::forget($key);
            return true;
        }
        $remaining = max(1, self::CHALLENGE_TTL - (time() - (int) ($data['issued_at'] ?? time())));
        Cache::put($key, $data, $remaining);
        return false;
    }

    public function consumeChallenge(string $key): void
    {
        Cache::forget($key);
    }

    private function challengeKey(string $token): string
    {
        // 缓存里只放 token 的哈希：Redis 被读走也拼不出可用的挑战
        return CacheKey::get('ADMIN_2FA_CHALLENGE', hash('sha256', $token));
    }

    // ------------------------------------------------------------------ 绑定

    /**
     * 开始绑定：生成新密钥写进 pending_secret（已启用的旧密钥照常生效，直到新密钥确认）。
     */
    public function beginEnrollment(User $user): array
    {
        $secret = Totp::generateSecret();
        $record = AdminTwoFactor::firstOrNew(['user_id' => $user->id]);
        $record->pending_secret = $secret;
        $record->save();

        $issuer = (string) admin_setting('app_name', 'XBoard');
        $url = Totp::otpauthUrl($issuer, $user->email, $secret);

        return [
            'secret' => $secret,
            'secret_grouped' => trim(chunk_split($secret, 4, ' ')),
            'otpauth_url' => $url,
            'qr_svg' => $this->qrDataUri($url),
            'issuer' => $issuer,
            'account' => $user->email,
        ];
    }

    /**
     * 用新密钥算出的验证码确认绑定；成功后返回一次性展示的恢复码明文。
     *
     * @return array<int, string>|null 失败返回 null
     */
    public function confirmEnrollment(User $user, string $code): ?array
    {
        $record = $this->record($user);
        if (!$record || empty($record->pending_secret)) {
            return null;
        }
        $step = Totp::matchStep($record->pending_secret, $code);
        if ($step === null) {
            return null;
        }
        $codes = $this->generateRecoveryCodes();
        $record->secret = $record->pending_secret;
        $record->pending_secret = null;
        $record->confirmed_at = time();
        $record->recovery_codes = array_map(fn($c) => Hash::make($c), $codes);
        $record->last_used_step = $step;
        $record->save();
        return $codes;
    }

    /**
     * 校验验证器里的 6 位码，或一个恢复码。恢复码用掉即删。
     *
     * @return array{ok: bool, recovery: bool, remaining: int}
     */
    public function verifyCode(User $user, string $code): array
    {
        $record = $this->record($user);
        $result = ['ok' => false, 'recovery' => false, 'remaining' => 0];
        if (!$record || !$record->isEnabled()) {
            return $result;
        }
        $result['remaining'] = $record->recoveryCodesRemaining();

        $step = Totp::matchStep($record->secret, $code);
        if ($step !== null) {
            // 同一个 30 秒内的码只能用一次：被偷看到的码在它自己的窗口里也无法重放
            if ($record->last_used_step !== null && $step <= $record->last_used_step) {
                return $result;
            }
            $record->last_used_step = $step;
            $record->save();
            $result['ok'] = true;
            return $result;
        }

        $normalized = $this->normalizeRecoveryCode($code);
        if ($normalized === null) {
            return $result;
        }
        $hashes = is_array($record->recovery_codes) ? $record->recovery_codes : [];
        foreach ($hashes as $index => $hash) {
            if (is_string($hash) && Hash::check($normalized, $hash)) {
                unset($hashes[$index]);
                $record->recovery_codes = array_values($hashes);
                $record->save();
                $result['ok'] = true;
                $result['recovery'] = true;
                $result['remaining'] = count($hashes);
                return $result;
            }
        }
        return $result;
    }

    /** @return array<int, string> 新恢复码明文（旧的全部作废） */
    public function regenerateRecoveryCodes(User $user): ?array
    {
        $record = $this->record($user);
        if (!$record || !$record->isEnabled()) {
            return null;
        }
        $codes = $this->generateRecoveryCodes();
        $record->recovery_codes = array_map(fn($c) => Hash::make($c), $codes);
        $record->save();
        return $codes;
    }

    /** 解除绑定（只给 artisan admin:two-factor-reset 用），下次登录重新绑定 */
    public function reset(User $user): void
    {
        AdminTwoFactor::where('user_id', $user->id)->delete();
    }

    /** @return array<int, string> */
    private function generateRecoveryCodes(): array
    {
        $codes = [];
        $max = strlen(self::RECOVERY_ALPHABET) - 1;
        while (count($codes) < self::RECOVERY_CODE_COUNT) {
            $raw = '';
            for ($i = 0; $i < 10; $i++) {
                $raw .= self::RECOVERY_ALPHABET[random_int(0, $max)];
            }
            $code = substr($raw, 0, 5) . '-' . substr($raw, 5);
            if (!in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }
        return $codes;
    }

    private function normalizeRecoveryCode(string $input): ?string
    {
        $clean = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', $input));
        if (strlen($clean) !== 10) {
            return null;
        }
        return substr($clean, 0, 5) . '-' . substr($clean, 5);
    }

    private function qrDataUri(string $content): string
    {
        $renderer = new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd());
        $svg = (new Writer($renderer))->writeString($content);
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    // ------------------------------------------------------------------ 审计与通知

    public const ACTION_LOGIN = 'auth.login';
    public const ACTION_LOGIN_FAILED = 'auth.login_failed';
    public const ACTION_TWO_FACTOR_FAILED = 'auth.two_factor_failed';
    public const ACTION_TWO_FACTOR_LOCKED = 'auth.two_factor_locked';
    public const ACTION_TWO_FACTOR_ENROLLED = 'auth.two_factor_enrolled';
    public const ACTION_RECOVERY_CODE_USED = 'auth.recovery_code_used';
    public const ACTION_RECOVERY_CODES_REGENERATED = 'auth.recovery_codes_regenerated';
    public const ACTION_SESSION_REVOKED = 'auth.session_revoked';
    public const ACTION_SESSIONS_REVOKED = 'auth.sessions_revoked';

    /** 登录相关事件写进后台审计日志（与 RequestLog 同一张表，日志页可按「登录」筛选） */
    public function audit(User $user, string $action, Request $request, array $data = []): void
    {
        try {
            AdminAuditLog::insert([
                'admin_id' => $user->id,
                'action' => $action,
                'method' => $request->method(),
                'uri' => $request->getRequestUri(),
                'request_data' => json_encode(array_merge([
                    'email' => $user->email,
                    'user_agent' => Str::limit((string) $request->userAgent(), 200, ''),
                ], $data), JSON_UNESCAPED_UNICODE),
                'ip' => $request->getClientIp(),
                'created_at' => time(),
                'updated_at' => time(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Admin auth audit write failed: ' . $e->getMessage());
        }
    }

    /** 管理员登录成功 / 账号被锁 这类事件推给绑定了 Telegram 的管理员；没开机器人就静默 */
    public function notifyAdmins(string $text): void
    {
        if (!(int) admin_setting('telegram_bot_enable', 0)) {
            return;
        }
        try {
            app(TelegramService::class)->sendMessageWithAdmin($text, false, '');
        } catch (\Throwable $e) {
            Log::warning('Admin auth telegram notify failed: ' . $e->getMessage());
        }
    }
}
