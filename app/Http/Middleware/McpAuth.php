<?php

namespace App\Http\Middleware;

use App\Services\AuthService;
use App\Services\Mcp\McpTokenService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * /mcp 的门：只认客户 AI 助手令牌（mcp:read），面板会话令牌、后台令牌都不放行。
 * 依次检查：功能开关 → 令牌 → 账号（封禁 / 白名单 / 付过款）→ 频率。
 * 失败一律回 JSON（AI 客户端会把 message 原样给客户看），不回面板那套 403 跳登录。
 */
class McpAuth
{
    public function handle(Request $request, Closure $next)
    {
        $service = McpTokenService::make();
        $config = $service->config();
        if (!$config->enabled) {
            return self::deny(403, 'AI 助手暂未开放。');
        }

        $user = Auth::guard('sanctum')->user();
        $token = $user?->currentAccessToken();
        if (!$user || !$token instanceof PersonalAccessToken || !AuthService::isMcpToken($token)) {
            return self::deny(401, '令牌无效或已失效，请在面板「AI 助手」里重新生成。')
                ->header('WWW-Authenticate', 'Bearer realm="mcp"');
        }
        if ($service->ineligibleReason($user) !== null) {
            return self::deny(403, '这个账号暂时不能使用 AI 助手。');
        }

        foreach ([['m', 60, $config->ratePerMinute], ['d', 86400, $config->ratePerDay]] as [$suffix, $decay, $limit]) {
            $key = "mcp:{$suffix}:{$token->id}";
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                return self::deny(429, '调用太频繁，请稍后再试。')
                    ->header('Retry-After', (string) RateLimiter::availableIn($key));
            }
            RateLimiter::hit($key, $decay);
        }

        $service->touch($token);
        Auth::shouldUse('sanctum');
        return $next($request);
    }

    private static function deny(int $status, string $message)
    {
        return response()->json(['message' => $message], $status);
    }
}
