<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
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
    /**
     * 只读后台能力：只给「接口令牌」用。Admin 中间件放行 GET 以及 POST 到 …/fetch、…/detail 的查询类接口，
     * 其余写操作一律拒绝。给通知机器人这类只拉订单 / 工单的程序用，泄露了也改不了数据。
     */
    public const ABILITY_ADMIN_READ = 'admin:read';
    /**
     * 接口令牌标记：在「账号安全」页输入验证码后签发，给后台集成程序用（不走密码登录，也就不用两步验证）。
     * 不过期、单独列出、单独吊销；碰不到账号安全页本身的接口（见 Admin 中间件）。
     */
    public const ABILITY_API = 'api';
    /**
     * 客户 AI 助手（MCP）令牌：只认 /mcp，面板接口（User 中间件）、快捷登录换票一律拒绝，
     * 泄露了也只能查这个账号、替这个账号下一张要本人去付款页付的单。mcp:order 是客户勾选「允许下单」才带的。
     */
    public const ABILITY_MCP_READ = 'mcp:read';
    public const ABILITY_MCP_ORDER = 'mcp:order';

    /** token 名前缀：后台会话 admin|ip|ua，接口令牌 api|名称，用户面板 user|ip|ua（老 token 是 20 位随机串） */
    public const TOKEN_KIND_ADMIN = 'admin';
    public const TOKEN_KIND_API = 'api';
    public const TOKEN_KIND_USER = 'user';
    public const TOKEN_KIND_MCP = 'mcp';

    /** 只读令牌在 POST 方法下能调的接口（路径最后一段）；GET 一律算查询 */
    private const READ_ONLY_POST_ENDPOINTS = ['fetch', 'detail'];

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
    public function generateAuthData(?string $ip = null, ?string $userAgent = null): array
    {
        return $this->issueToken(self::sessionTokenName(self::TOKEN_KIND_USER, $ip, $userAgent), [self::ABILITY_USER], now()->addYear());
    }

    /**
     * 签发接口令牌（不过期）。$readOnly 为 true 时只带 admin:read。
     * 返回值多一个 id，方便调用方写审计；明文 token 只在这一次返回。
     */
    public function generateApiToken(string $label, bool $readOnly): array
    {
        $label = trim((string) preg_replace('/\s+/', ' ', $label));
        $abilities = [$readOnly ? self::ABILITY_ADMIN_READ : self::ABILITY_ADMIN, self::ABILITY_API];
        $token = $this->user->createToken(Str::limit('api|' . $label, 250, ''), $abilities, null);
        $plain = explode('|', $token->plainTextToken, 2)[1] ?? $token->plainTextToken;
        return [
            'id' => $token->accessToken->id,
            'token' => $plain,
            'auth_data' => 'Bearer ' . $plain,
        ];
    }

    /**
     * 签发后台会话 token（两步验证通过后调用）。有效期按后台设置，到期必须重新登录并再验一次。
     * token 名里记下 IP 与客户端，「账号安全」页的会话列表靠它辨认设备。
     */
    public function generateAdminAuthData(?string $ip = null, ?string $userAgent = null): array
    {
        $days = self::adminSessionDays();
        return $this->issueToken(
            self::sessionTokenName(self::TOKEN_KIND_ADMIN, $ip, $userAgent),
            [self::ABILITY_USER, self::ABILITY_ADMIN],
            now()->addDays($days)
        );
    }

    public static function adminSessionDays(): int
    {
        $days = (int) admin_setting('admin_session_days', self::ADMIN_SESSION_DAYS_DEFAULT);
        return max(1, min(self::ADMIN_SESSION_DAYS_MAX, $days ?: self::ADMIN_SESSION_DAYS_DEFAULT));
    }

    public static function sessionTokenName(string $kind, ?string $ip, ?string $userAgent): string
    {
        $ua = trim((string) preg_replace('/\s+/', ' ', (string) $userAgent));
        return Str::limit($kind . '|' . ((string) $ip ?: '-') . '|' . $ua, 250, '');
    }

    /** 兼容旧调用：后台会话的 token 名 */
    public static function adminTokenName(?string $ip, ?string $userAgent): string
    {
        return self::sessionTokenName(self::TOKEN_KIND_ADMIN, $ip, $userAgent);
    }

    /**
     * token 属于哪一类：后台会话 / 接口令牌 / 用户面板（含两步验证上线前的旧 token）。
     * 只认能力，不认名字 —— 名字前缀只用来展示 IP 与客户端。
     */
    public static function tokenKind(PersonalAccessToken $token): string
    {
        $abilities = is_array($token->abilities) ? $token->abilities : [];
        if (in_array('*', $abilities, true)) {
            return self::TOKEN_KIND_USER;
        }
        if (in_array(self::ABILITY_API, $abilities, true)) {
            return self::TOKEN_KIND_API;
        }
        if (in_array(self::ABILITY_MCP_READ, $abilities, true)) {
            return self::TOKEN_KIND_MCP;
        }
        if (in_array(self::ABILITY_ADMIN, $abilities, true)) {
            return self::TOKEN_KIND_ADMIN;
        }
        return self::TOKEN_KIND_USER;
    }

    /** 只读令牌能调的请求：GET / HEAD，或 POST 到 …/fetch、…/detail */
    public static function isReadOnlyAdminRequest(Request $request): bool
    {
        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return true;
        }
        if (!$request->isMethod('POST')) {
            return false;
        }
        $segments = explode('/', trim($request->path(), '/'));
        return in_array(end($segments), self::READ_ONLY_POST_ENDPOINTS, true);
    }

    /**
     * token 是否能调这个后台请求：带 admin 能力的后台会话 / 完整接口令牌一律可以；
     * 只读接口令牌只能调查询类请求（$request 为空时按写操作算，即不放行）。
     */
    public static function tokenGrantsAdmin(mixed $token, ?Request $request = null): bool
    {
        if (!$token instanceof PersonalAccessToken) {
            return false;
        }
        $abilities = $token->abilities;
        if (is_array($abilities)) {
            if (in_array('*', $abilities, true)) {
                return false;
            }
            if (in_array(self::ABILITY_ADMIN, $abilities, true)) {
                return true;
            }
            return in_array(self::ABILITY_ADMIN_READ, $abilities, true)
                && $request !== null
                && self::isReadOnlyAdminRequest($request);
        }
        // Sanctum::actingAs() 的测试替身没有 abilities 列，只回答 can()
        return (bool) $token->can(self::ABILITY_ADMIN);
    }

    /** 是否接口令牌（Sanctum 测试替身一律不算） */
    public static function isApiToken(mixed $token): bool
    {
        return $token instanceof PersonalAccessToken
            && is_array($token->abilities)
            && self::tokenKind($token) === self::TOKEN_KIND_API;
    }

    /** 是否客户 AI 助手（MCP）令牌（Sanctum 测试替身一律不算） */
    public static function isMcpToken(mixed $token): bool
    {
        return $token instanceof PersonalAccessToken
            && is_array($token->abilities)
            && self::tokenKind($token) === self::TOKEN_KIND_MCP;
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
        // AI 助手令牌不能拿来换快捷登录链接：那等于把只读 / 下单令牌升级成完整的面板会话
        if (self::isMcpToken($accessToken)) {
            return null;
        }

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
