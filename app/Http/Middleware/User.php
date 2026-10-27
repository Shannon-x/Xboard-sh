<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\AuthService;
use Auth;
use Closure;
use Illuminate\Support\Facades\Cache;

class User
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $user = Auth::guard('sanctum')->user();
        if (!$user) {
            throw new ApiException('未登录或登陆已过期', 403);
        }
        // 封禁只在登录时检查不够：封号前签发的 token 仍然有效（批量封禁、直接改库都不吊销会话），
        // 2026-10 被封的渗透小号拿旧 token 照样能开工单、下单。每个请求按库里的 banned 再拦一次。
        if ($user->banned) {
            throw new ApiException(__('Your account has been suspended'), 403);
        }
        // AI 助手（MCP）令牌只认 /mcp：面板接口能改密码、提现、看订阅链接，绝不能被它调用。
        // 只看令牌能力，现有会话令牌（user / 两步验证上线前的 *）行为不变。
        if (AuthService::isMcpToken($user->currentAccessToken())) {
            throw new ApiException(__('This token can only be used by the AI assistant'), 403);
        }
        return $next($request);
    }
}
