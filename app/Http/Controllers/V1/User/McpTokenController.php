<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Mcp\McpConfig;
use App\Services\Mcp\McpTokenService;
use Illuminate\Http\Request;

/**
 * 面板「账号设置 → AI 助手」：生成 / 列出 / 吊销客户自己的 AI 助手令牌。
 * 令牌只能用在 /mcp，面板接口一律拒绝（User 中间件）；明文只在生成时返回一次。
 */
class McpTokenController extends Controller
{
    public function fetch(Request $request)
    {
        $service = McpTokenService::make();
        $config = $service->config();
        $user = User::findOrFail($request->user()->id);
        $reason = $service->ineligibleReason($user);
        return $this->success([
            'enabled' => $config->enabled,
            'eligible' => $reason === null,
            'reason' => $reason,
            'endpoint_url' => $config->enabled ? $config->endpointUrl : '',
            'order_enabled' => $config->orderEnabled,
            'idle_days' => $config->tokenIdleDays,
            'max' => McpConfig::MAX_TOKENS_PER_USER,
            'tokens' => $config->enabled ? $service->list($user) : [],
        ]);
    }

    public function create(Request $request)
    {
        $request->validate([
            'name' => 'nullable|string|max:40',
            'can_order' => 'nullable|boolean',
        ]);
        $service = McpTokenService::make();
        $user = User::findOrFail($request->user()->id);
        $created = $service->create($user, (string) $request->input('name', ''), (bool) $request->input('can_order', false));
        return $this->success($created + ['endpoint_url' => $service->config()->endpointUrl]);
    }

    public function revoke(Request $request)
    {
        $request->validate(['id' => 'required|integer']);
        $user = User::findOrFail($request->user()->id);
        if (!McpTokenService::make()->revoke($user, (int) $request->input('id'))) {
            return $this->fail([400, __('Token does not exist')]);
        }
        return $this->success(true);
    }
}
