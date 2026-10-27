<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\ServerService;
use App\Services\UserService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_nodes')]
#[Title('节点状态')]
#[Description('列出客户当前可用的节点：名称、协议类型、扣费倍率、是否在线。只给名称和状态，不给地址与端口。套餐到期或流量用完时列表为空。')]
#[IsReadOnly]
#[IsIdempotent]
class ListNodesTool extends McpTool
{
    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $user = User::findOrFail($user->id);
        if (!app(UserService::class)->isAvailable($user)) {
            return self::result(['nodes' => [], 'note' => '套餐已到期或流量已用完，暂无可用节点。']);
        }
        $nodes = collect(ServerService::getAvailableServers($user))->map(fn ($s) => [
            'name' => (string) $s['name'],
            'type' => (string) $s['type'],
            'rate' => (float) $s['rate'],
            'online' => (bool) ($s['is_online'] ?? false),
            'tags' => array_values((array) ($s['tags'] ?? [])),
        ])->values()->all();
        return self::result([
            'nodes' => $nodes,
            'online_count' => count(array_filter($nodes, fn ($n) => $n['online'])),
            'note' => '倍率 ×2 表示用 1 GB 扣 2 GB。节点地址请在客户端里看。',
        ]);
    }
}
