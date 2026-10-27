<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Mcp\McpOAuthServer;
use Illuminate\Http\Request;

/**
 * 网站授权页（/ai/authorize）：客户用平常的账号登录后，看是哪个 AI 软件要连、能做什么，点「允许」或「拒绝」。
 * 返回的 redirect 由前端整页跳转回 AI 软件。三条都登记进中间件路径表。
 */
class McpAuthorizeController extends Controller
{
    public function show(Request $request)
    {
        $request->validate(['req' => 'required|string|max:64']);
        $server = McpOAuthServer::make();
        if (!$server->config()->oauthActive()) {
            return $this->fail([403, __('The AI assistant is not available for this account')]);
        }
        $user = User::findOrFail($request->user()->id);
        return $this->success($server->pending($user, (string) $request->input('req')));
    }

    public function approve(Request $request)
    {
        $request->validate([
            'req' => 'required|string|max:64',
            'can_order' => 'nullable|boolean',
        ]);
        $server = McpOAuthServer::make();
        if (!$server->config()->oauthActive()) {
            return $this->fail([403, __('The AI assistant is not available for this account')]);
        }
        $user = User::findOrFail($request->user()->id);
        return $this->success(['redirect' => $server->approve($user, (string) $request->input('req'), $request->boolean('can_order'))]);
    }

    public function deny(Request $request)
    {
        $request->validate(['req' => 'required|string|max:64']);
        $server = McpOAuthServer::make();
        if (!$server->config()->oauthActive()) {
            return $this->fail([403, __('The AI assistant is not available for this account')]);
        }
        return $this->success(['redirect' => $server->deny((string) $request->input('req'))]);
    }
}
