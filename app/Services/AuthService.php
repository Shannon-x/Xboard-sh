<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class AuthService
{
    /** 普通会话能力：用户面板的一切接口（User 中间件不看能力，只看登录态） */
    public const ABILITY_USER = 'user';
    /**
     * 后台能力：只有走完两步验证的后台登录才签发，Admin 中间件只认它。
     * 两步验证上线前签发的 token 能力是 ['*']，刻意不当作 admin —— 老会话一律重新登录并绑定验证器。
     */
    public const ABILITY_ADMIN = 'admin';

    /** 后台会话有效期（天）的默认值与上限，后台「安全设置 → 后台登录有效期」可改 */
    public const ADMIN_SESSION_DAYS_DEFAULT = 7;
    public const ADMIN_SESSION_DAYS_MAX = 90;

    private User $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    /**
     * 签发普通会话 token。用户面板登录 / 注册 / 邮件链接 / Google 登录都走这里，
     * 管理员账号在这些入口登录拿到的也是普通会话，进不了后台。
     */
    public function generateAuthData(): array
    {
        return $this->issueToken(Str::random(20), [self::ABILITY_USER], now()->addYear());
    }

    /**
     * 签发后台会话 token（两步验证通过后调用）。有效期按后台设置，到期必须重新登录并再验一次。
     * token 名里记下 IP 与客户端，「账号安全」页的会话列表靠它辨认设备。
     */
    public function generateAdminAuthData(?string $ip = null, ?string $userAgent = null): array
    {
        $days = self::adminSessionDays();
        return $this->issueToken(
            self::adminTokenName($ip, $userAgent),
            [self::ABILITY_USER, self::ABILITY_ADMIN],
            now()->addDays($days)
        );
    }

    public static function adminSessionDays(): int
    {
        $days = (int) admin_setting('admin_session_days', self::ADMIN_SESSION_DAYS_DEFAULT);
        return max(1, min(self::ADMIN_SESSION_DAYS_MAX, $days ?: self::ADMIN_SESSION_DAYS_DEFAULT));
    }

    public static function adminTokenName(?string $ip, ?string $userAgent): string
    {
        $ua = trim((string) preg_replace('/\s+/', ' ', (string) $userAgent));
        return Str::limit('admin|' . ((string) $ip ?: '-') . '|' . $ua, 250, '');
    }

    /** token 是否带后台能力（见 ABILITY_ADMIN 的说明） */
    public static function tokenGrantsAdmin(mixed $token): bool
    {
        if (!$token instanceof PersonalAccessToken) {
            return false;
        }
        $abilities = $token->abilities;
        if (is_array($abilities)) {
            return in_array(self::ABILITY_ADMIN, $abilities, true) && !in_array('*', $abilities, true);
        }
        // Sanctum::actingAs() 的测试替身没有 abilities 列，只回答 can()
        return (bool) $token->can(self::ABILITY_ADMIN);
    }

    private function issueToken(string $name, array $abilities, \DateTimeInterface $expiresAt): array
    {
        $token = $this->user->createToken($name, $abilities, $expiresAt);

        // Format token: remove ID prefix and add Bearer
        $tokenParts = explode('|', $token->plainTextToken);
        $formattedToken = 'Bearer ' . ($tokenParts[1] ?? $tokenParts[0]);

        return [
            'token' => $this->user->token,
            'auth_data' => $formattedToken,
            'is_admin' => $this->user->is_admin,
        ];
    }

    public function getSessions(): array
    {
        return $this->user->tokens()->get()->toArray();
    }

    public function removeSession(string $sessionId): bool
    {
        $this->user->tokens()->where('id', $sessionId)->delete();
        return true;
    }

    public function removeAllSessions(): bool
    {
        $this->user->tokens()->delete();
        return true;
    }

    public static function findUserByBearerToken(mixed $bearerToken): ?User
    {
        if (!is_string($bearerToken)) {
            return null;
        }

        $token = trim(str_replace('Bearer ', '', $bearerToken));
        if ($token === '' || strlen($token) > 255) {
            return null;
        }
        
        $accessToken = PersonalAccessToken::findToken($token);
        
        $tokenable = $accessToken?->tokenable;
        
        return $tokenable instanceof User ? $tokenable : null;
    }

    /**
     * 解密认证数据
     *
     * @param string $authorization
     * @return array|null 用户数据或null
     */
    public static function decryptAuthData(mixed $authorization): ?array
    {
        $user = self::findUserByBearerToken($authorization);
        
        if (!$user) {
            return null;
        }
        
        return [
            'id' => $user->id,
            'email' => $user->email,
            'is_admin' => (bool)$user->is_admin,
            'is_staff' => (bool)$user->is_staff
        ];
    }
}
