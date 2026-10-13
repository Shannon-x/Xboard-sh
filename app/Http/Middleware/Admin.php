<?php

namespace App\Http\Middleware;

use App\Services\AuthService;
use Illuminate\Support\Facades\Auth;
use Closure;
use App\Models\User;

class Admin
{
    /**
     * 后台接口守门：登录态 + is_admin 之外，token 本身还必须带 admin 能力。
     * 该能力只在后台登录流程里、两步验证通过后签发（AuthService::generateAdminAuthData）；
     * 用户面板 / 邮件链接 / Google 登录拿到的普通 token，以及两步验证上线前的旧 token，
     * 都在这里被挡下 —— 后台前端收到 403 后会清掉本地会话回到登录页，走一遍绑定。
     *
     * 接口令牌（账号安全页签发，给通知机器人之类的程序用）：完整令牌等同后台会话，只读令牌只放行查询类请求；
     * 两种都碰不到账号安全页自己的接口，拿到令牌也签不出新令牌、改不了两步验证。
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        /** @var User|null $user */
        $user = Auth::guard('sanctum')->user();
        
        if (!$user || !$user->is_admin) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $token = $user->currentAccessToken();
        if (!AuthService::tokenGrantsAdmin($token, $request)) {
            if (AuthService::isApiToken($token)) {
                return response()->json([
                    'message' => 'This API token is read-only',
                    'code' => 'ADMIN_TOKEN_READ_ONLY',
                ], 403);
            }
            return response()->json([
                'message' => 'Two-factor authentication required',
                'code' => 'ADMIN_2FA_REQUIRED',
            ], 403);
        }

        if (AuthService::isApiToken($token) && self::isSecurityRoute($request)) {
            return response()->json([
                'message' => 'API tokens cannot manage account security',
                'code' => 'ADMIN_SESSION_REQUIRED',
            ], 403);
        }


        return $next($request);
    }

    /** 账号安全页的接口（路由组前缀 …/security） */
    private static function isSecurityRoute($request): bool
    {
        $prefix = trim((string) $request->route()?->getPrefix(), '/');
        return $prefix === 'security' || str_ends_with($prefix, '/security');
    }
}
