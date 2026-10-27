<?php

namespace App\Mcp\Tools;

use App\Exceptions\ApiException;
use App\Models\Plan;
use App\Models\User;
use App\Services\RestockNotifier;
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

#[Name('set_restock_alert')]
#[Title('到货提醒')]
#[Description('客户想买的套餐售罄时，登记到货提醒：有空位时给客户的邮箱发一封邮件（只发一次）。cancel=true 取消登记。plan_id 来自 list_plans(include_sold_out=true)。')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent]
class RestockAlertTool extends McpTool
{
    protected bool $ordering = true;

    public function schema(JsonSchema $schema): array
    {
        return [
            'plan_id' => $schema->integer()->description('售罄套餐的 plan_id。')->required(),
            'cancel' => $schema->boolean()->description('true = 取消这条到货提醒。'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        // 与面板登记同一个限流桶
        self::throttle("restock:user:{$user->id}", 6, 60, '操作太频繁，请稍后再试。', 'restock-notify');
        $plan = Plan::find((int) $request->get('plan_id'));
        if (!$plan) {
            throw new ApiException('找不到这个套餐，请先用 list_plans(include_sold_out=true) 查看。');
        }
        $fresh = User::findOrFail($user->id);
        $notifier = app(RestockNotifier::class);
        if (filter_var($request->get('cancel'), FILTER_VALIDATE_BOOLEAN)) {
            $notifier->cancel($plan, (string) $fresh->email);
            return self::result(['plan' => (string) $plan->name, 'subscribed' => false]);
        }
        $notifier->subscribe($plan, (string) $fresh->email, $fresh);
        return self::result([
            'plan' => (string) $plan->name,
            'subscribed' => true,
            'note' => '有空位时会给客户的注册邮箱发一封提醒，只发一次。',
        ]);
    }
}
