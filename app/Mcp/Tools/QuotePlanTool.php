<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\Mcp\McpOrderSpec;
use App\Services\Mcp\McpPresenter as P;
use App\Services\OrderService;
use App\Services\PlanCustomizationService;
use App\Services\PlanService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('quote_plan')]
#[Title('报价')]
#[Description('报价，不下单：续费当前套餐、新购或换套餐、按 GB 加购流量分别要多少钱；换套餐时会算出剩余时间折抵多少、折抵后应付多少。下单前先报价给客户确认。金额不含优惠码与余额抵扣，以下单结果和付款页为准。')]
#[IsReadOnly]
#[IsIdempotent]
class QuotePlanTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'mode' => $schema->string()->enum(McpOrderSpec::MODES)
                ->description('renew = 续费当前套餐；purchase = 新购或换成 plan_id 指定的套餐；traffic_topup = 当前套餐本周期加购流量。')->required(),
            'plan_id' => $schema->integer()->description('purchase 时必填，来自 list_plans。'),
            'period' => $schema->string()->description('付款周期：monthly / quarterly / half_yearly / yearly / two_yearly / three_yearly / onetime / reset_traffic。purchase 必填；renew 可选（默认上次的周期）。'),
            'topup_gb' => $schema->integer()->description('traffic_topup 时必填：加购多少 GB。')->min(1),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $user = User::findOrFail($user->id);
        $spec = McpOrderSpec::resolve($user, $request->all());
        (new PlanService($spec['plan']))->validatePurchase($user, $spec['period']);
        $quote = app(PlanCustomizationService::class)->quote($spec['plan'], $spec['period'], $spec['options'], $user);
        $preview = OrderService::previewOrderType($user, $spec['plan'], $spec['period'], $quote);
        $balanceApplied = min((int) $user->balance, (int) $preview['payable']);

        return self::result([
            'mode' => $spec['mode'],
            'plan_id' => (int) $spec['plan']->id,
            'plan_name' => (string) $spec['plan']->name,
            'period' => $spec['period'],
            'period_name' => P::periodName($spec['period']),
            'topup_gb' => $spec['options']['topup_gb'] ?? null,
            'price_yuan' => P::yuan($quote['amount']),
            'order_kind' => $preview['type'] !== null ? P::orderType((int) $preview['type']) : null,
            'remaining_value_deducted_yuan' => P::yuan($preview['surplus_amount']),
            'payable_yuan' => P::yuan($preview['payable']),
            'account_balance_will_cover_yuan' => P::yuan($balanceApplied),
            'cash_due_estimate_yuan' => P::yuan(max(0, (int) $preview['payable'] - $balanceApplied)),
            'blocked_reason' => $preview['blocked'],
            'note' => '下单时会自动用账户余额抵扣；优惠码、会员折扣在下单时计算。下单请调用 create_order，并把 price_yuan 原样放进 expected_price_yuan。',
        ]);
    }
}
