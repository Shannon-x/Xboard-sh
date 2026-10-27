<?php

namespace App\Mcp\Tools;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\AdvanceCycleService;
use App\Services\Mcp\McpPresenter as P;
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

#[Name('advance_traffic_cycle')]
#[Title('免费提前重置流量')]
#[Description('免费把流量提前重置成满额，代价是到期日提前 30 天（等于提前用下个月）。本周期加购的流量会一并清掉。不带 confirm 时只返回会发生什么；把到期日变化讲给客户、客户明确同意后再带 confirm=true 调用，做了不能撤销。想保留到期日的客户请改用流量重置包或加购流量（见 get_traffic_options）。')]
#[IsReadOnly(false)]
#[IsDestructive]
#[IsIdempotent(false)]
class AdvanceTrafficCycleTool extends McpTool
{
    protected bool $ordering = true;

    public function schema(JsonSchema $schema): array
    {
        return [
            'confirm' => $schema->boolean()->description('客户已同意「到期日提前 30 天」后传 true；不传只预览。'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $service = app(AdvanceCycleService::class);
        $user = User::findOrFail($user->id);

        if (!self::confirmed($request)) {
            $preview = $service->preview($user);
            if (!($preview['eligible'] ?? false)) {
                throw new ApiException('现在不能提前重置：' . $preview['message']);
            }
            return self::result([
                'done' => false,
                'expires_at_now' => P::time($preview['old_expired_at']),
                'expires_at_after' => P::time($preview['new_expired_at']),
                // 本周期加购的流量会被收回，所以重置后的额度是套餐额度，不含加购
                'traffic_after_gb' => P::gb(max(0, (int) $preview['transfer_enable'] - (int) ($user->transfer_topup ?? 0))),
                'topup_gb_forfeited' => P::gb((int) ($user->transfer_topup ?? 0)),
                'next_step' => '把到期日变化告诉客户；客户同意后带 confirm=true 再调用一次。',
            ]);
        }

        $result = $service->advance($user, ['ip' => request()->ip(), 'user_agent' => 'mcp']);
        if (!($result['eligible'] ?? false) || !($result['advanced'] ?? false)) {
            throw new ApiException('没有重置：' . ($result['message'] ?? '条件不满足'));
        }
        return self::result([
            'done' => true,
            'expires_at' => P::time($result['new_expired_at'] ?? null),
            'traffic_gb' => P::gb($result['remaining'] ?? 0),
            'note' => '流量已恢复满额，客户端里刷新订阅即可看到。',
        ]);
    }
}
