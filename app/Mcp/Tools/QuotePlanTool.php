<?php

namespace App\Mcp\Tools;

use App\Models\Plan;
use App\Models\User;
use App\Services\Mcp\McpOffers;
use App\Services\Mcp\McpOrderSpec;
use App\Services\Mcp\McpPresenter as P;
use App\Services\OrderService;
use App\Services\Payg\PaygConfig;
use App\Services\Payg\PaygService;
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
#[Description('报价，不下单：续费当前套餐、新购或换套餐（可选流量档 traffic_gb、加选三网等增值线路 addon_group_ids；按量套餐用 traffic_gb 定这次充多少 GB）、按 GB 加购流量、流量重置包分别要多少钱；换套餐时会算出剩余时间折抵多少、折抵后应付多少。下单前先报价给客户确认。带上 coupon_code 会估出优惠码能减多少；最终金额以下单结果和付款页为准。')]
#[IsReadOnly]
#[IsIdempotent]
class QuotePlanTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'mode' => $schema->string()->enum(McpOrderSpec::MODES)->description(McpOrderSpec::MODE_HELP)->required(),
            'plan_id' => $schema->integer()->description('purchase 时必填，来自 list_plans。'),
            'period' => $schema->string()->description('付款周期：monthly / quarterly / half_yearly / yearly / two_yearly / three_yearly / onetime。purchase 必填；renew 可选（默认上次的周期）。'),
            'topup_gb' => $schema->integer()->description('traffic_topup 时必填：加购多少 GB。')->min(1),
            'traffic_gb' => $schema->integer()->min(1)->description('purchase 可选：可自选流量的套餐选哪一档（GB）；按量套餐就是这次充多少 GB。来自 recommend_plan 的 quote_args，不传就是套餐默认流量。'),
            'addon_group_ids' => $schema->array()->items($schema->integer())->description('purchase 可选：要加选的增值线路（如三网优化）的 group_id，来自 recommend_plan 的 quote_args 或 list_plans 的 lines；不传就不加。'),
            'coupon_code' => $schema->string()->description('优惠码，可选（来自 list_offers 或客户提供）：会算出能减多少。'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $user = User::findOrFail($user->id);
        $spec = McpOrderSpec::resolve($user, $request->all());
        (new PlanService($spec['plan']))->validatePurchase($user, $spec['period']);
        $quote = app(PlanCustomizationService::class)->quote($spec['plan'], $spec['period'], $spec['options'], $user);
        $preview = OrderService::previewOrderType($user, $spec['plan'], $spec['period'], $quote);
        $coupon = null;
        $payable = (int) $preview['payable'];
        $code = trim((string) ($request->get('coupon_code') ?? ''));
        if ($code !== '') {
            $estimate = McpOffers::estimate($code, $user, $spec['plan'], $spec['period'], $payable);
            $coupon = ['code' => $code, 'valid' => $estimate['message'] === null, 'discount_yuan' => P::yuan($estimate['discount']), 'message' => $estimate['message']];
            $payable -= $estimate['discount'];
        }
        $balanceApplied = min((int) $user->balance, $payable);

        return self::result([
            'mode' => $spec['mode'],
            'plan_id' => (int) $spec['plan']->id,
            'plan_name' => (string) $spec['plan']->name,
            'period' => $spec['period'],
            'period_name' => P::periodName($spec['period']),
            'topup_gb' => $spec['options']['topup_gb'] ?? null,
            'traffic_gb' => $spec['mode'] === 'purchase' ? (int) ($quote['options']['transfer_enable'] ?? $spec['plan']->transfer_enable) : null,
            'addon_lines' => self::addonLines($spec['plan'], $quote['options'][PlanCustomizationService::ADDON_KEY] ?? []),
            'addon_fee_yuan' => ($quote['breakdown'][PlanCustomizationService::ADDON_KEY] ?? 0) > 0 ? P::yuan($quote['breakdown'][PlanCustomizationService::ADDON_KEY]) : null,
            'payg_bonus_gb' => self::paygBonus($spec['plan'], (int) $quote['amount'], $user),
            'price_yuan' => P::yuan($quote['amount']),
            'order_kind' => $preview['type'] !== null ? P::orderType((int) $preview['type']) : null,
            'remaining_value_deducted_yuan' => P::yuan($preview['surplus_amount']),
            'coupon' => $coupon,
            'payable_yuan' => P::yuan($payable),
            'account_balance_will_cover_yuan' => P::yuan($balanceApplied),
            'cash_due_estimate_yuan' => P::yuan(max(0, $payable - $balanceApplied)),
            'blocked_reason' => $preview['blocked'],
            'note' => '下单时会自动用账户余额抵扣；会员折扣、被邀请人首单折扣在下单时计算。下单请调用 create_order，参数与这次报价相同（traffic_gb、addon_group_ids 也要带上），把 price_yuan 原样放进 expected_price_yuan，优惠码放进 coupon_code。',
        ]);
    }

    /** 这次报价里加选的增值线路（随套餐包含的不列），费用合计在 addon_fee_yuan */
    private static function addonLines(Plan $plan, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $customizer = app(PlanCustomizationService::class);
        $display = $customizer->addonGroupsForDisplay($plan);
        return array_values(array_map(fn (int $id) => [
            'group_id' => $id,
            'name' => $display[(string) $id]['name'] ?? '增值线路',
        ], $ids));
    }

    /** 按量套餐：这一单开通时送多少 GB（满额赠送 + 首充） */
    private static function paygBonus(Plan $plan, int $amount, User $user): ?float
    {
        $config = PaygConfig::fromSettings();
        if (!$config->isPaygPlanId($plan->id)) {
            return null;
        }
        $bonus = app(PaygService::class)->quoteBonus($plan, $amount, $user, $config);
        return P::gb($bonus['bonus_bytes'] + $bonus['first_bonus_bytes']);
    }
}
