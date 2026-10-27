<?php

namespace App\Http\Middleware;

use App\Services\AuthService;
use App\Services\Mcp\McpOAuthServer;
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
            return self::deny(401, '令牌无效或已失效，请在网站「AI 助手」页重新连接。')
                ->header('WWW-Authenticate', self::challenge($config, $request));
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

    /**
     * 401 时告诉 AI 软件去哪儿授权（RFC 9728）：ChatGPT、Claude 据此发起一键授权。
     * 一键授权关着时只回 realm，手动令牌照旧。
     */
    private static function challenge(\App\Services\Mcp\McpConfig $config, Request $request): string
    {
        $header = 'Bearer realm="mcp"';
        if ($request->bearerToken()) {
            $header .= ', error="invalid_token"';
        }
        if ($config->oauthActive()) {
            $header .= ', resource_metadata="' . McpOAuthServer::make()->resourceMetadataUrl() . '"';
        }
        return $header;
    }

    private static function deny(int $status, string $message)
    {
        return response()->json(['message' => $message], $status);
    }
}
