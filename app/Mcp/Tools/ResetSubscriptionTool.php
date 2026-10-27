<?php

namespace App\Mcp\Tools;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('reset_subscription')]
#[Title('重置订阅链接')]
#[Description('订阅链接泄露、被别人用了时，作废旧链接并生成新链接（和面板「重置订阅」一样）。所有设备会立刻断开，要到面板「我的订阅」复制新链接重新导入客户端；新链接不会经过你，你也拿不到。不带 confirm 只说明后果；客户明确同意后带 confirm=true。')]
#[IsReadOnly(false)]
#[IsDestructive]
#[IsIdempotent(false)]
class ResetSubscriptionTool extends McpTool
{
    protected bool $ordering = true;

    public function schema(JsonSchema $schema): array
    {
        return [
            'confirm' => $schema->boolean()->description('客户已同意「所有设备断开、要重新导入」后传 true；不传只说明后果。'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        if (!self::confirmed($request)) {
            return self::result([
                'done' => false,
                'effect' => '旧订阅链接立即失效，所有设备断开；客户要到面板「我的订阅」复制新链接，在每台设备上重新导入。',
                'next_step' => '客户同意后带 confirm=true 再调用一次。',
            ]);
        }
        // 一天最多三次：令牌落到别人手里时，也只能折腾这么多次
        self::throttle("mcp_reset_subscription:user:{$user->id}", 3, 86400, '今天已经重置过 3 次了，请明天再试，或到面板操作。');

        $fresh = User::findOrFail($user->id);
        $fresh->uuid = Helper::guid(true);
        $fresh->token = Helper::guid();
        if (!$fresh->save()) {
            throw new ApiException(__('Reset failed'));
        }
        return self::result([
            'done' => true,
            'note' => '已重置。旧链接已失效，请客户到面板「我的订阅」复制新链接，在每台设备上重新导入。新链接不会通过 AI 助手提供。',
        ]);
    }
}
