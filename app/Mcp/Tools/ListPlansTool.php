<?php

namespace App\Mcp\Tools;

use App\Models\Plan;
use App\Models\User;
use App\Services\Mcp\McpPresenter as P;
use App\Services\Payg\PaygService;
use App\Services\PlanCapacity;
use App\Services\PlanCustomizationService;
use App\Services\PlanService;
use App\Support\PlanCatalog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_plans')]
#[Title('在售套餐')]
#[Description('列出在售套餐：套餐 ID、名称、运营商 / 分类、每个周期的价格（元）、流量、设备数、是否有货、是否支持按 GB 加购流量。客户自己正在用的套餐即使已下架或满员也会列出（可以续费）。下单或报价前先用它拿到 plan_id 和可选周期。')]
#[IsReadOnly]
#[IsIdempotent]
class ListPlansTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'keyword' => $schema->string()->description('按名称 / 运营商 / 标签筛选，可选，例如「日本」「AT&T」「按量」。'),
            'include_sold_out' => $schema->boolean()->description('是否列出已售罄的套餐，默认否。'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $keyword = mb_strtolower(trim((string) ($request->get('keyword') ?? '')));
        $plans = app(PlanService::class)->getAvailablePlans((bool) $request->get('include_sold_out'));
        if ($user->plan_id && !$plans->contains('id', $user->plan_id) && ($own = Plan::find($user->plan_id)) && $own->renew) {
            $plans->push($own);
        }
        $remaining = PlanCapacity::remaining($plans);
        $customizer = app(PlanCustomizationService::class);
        $payg = app(PaygService::class);

        $items = $plans->map(function (Plan $plan) use ($user, $remaining, $customizer, $payg) {
            $catalog = PlanCatalog::split($plan->tags);
            $prices = [];
            foreach ((array) $plan->prices as $period => $price) {
                if ($price !== null && $price !== '' && is_numeric($price)) {
                    $prices[] = ['period' => $period, 'period_name' => P::periodName($period), 'price_yuan' => number_format((float) $price, 2, '.', '')];
                }
            }
            $topup = $customizer->topupRule($plan);
            $paygInfo = $payg->planPayload($plan, $user);
            return [
                'plan_id' => (int) $plan->id,
                'name' => (string) $plan->name,
                'carrier' => $catalog['catalog']['carrier'] ?? null,
                'segment' => $catalog['catalog']['segment'] ?? null,
                'tags' => array_values(array_filter((array) $catalog['tags'], 'is_string')),
                'in_stock' => ($remaining[(int) $plan->id] ?? null) !== 0,
                'is_current_plan' => (int) $user->plan_id === (int) $plan->id,
                'prices' => $prices,
                'traffic_gb' => (int) $plan->transfer_enable,
                'device_limit' => $plan->device_limit,
                'speed_limit_mbps' => $plan->speed_limit,
                'payg' => $paygInfo ? [
                    'price_per_gb_yuan' => P::yuan((float) $paygInfo['unit_price']),
                    'min_recharge_yuan' => P::yuan($paygInfo['min_amount']),
                ] : null,
                'traffic_topup' => $topup ? [
                    'price_per_gb_yuan' => P::yuan($topup['price_per_gb']),
                    'min_gb' => $topup['min_gb'],
                    'max_gb' => $topup['max_gb'],
                ] : null,
                'description' => P::plain($plan->content, 240),
            ];
        })->filter(function (array $p) use ($keyword) {
            if ($keyword === '') {
                return true;
            }
            $hay = mb_strtolower($p['name'] . ' ' . $p['carrier'] . ' ' . $p['segment'] . ' ' . implode(' ', $p['tags']) . ' ' . ($p['payg'] ? '按量' : ''));
            return str_contains($hay, $keyword);
        })->values()->all();

        return self::result([
            'plans' => $items,
            'note' => '价格以报价工具 quote_plan 和付款页为准；需要定制流量、增值线路时请到面板下单。',
        ]);
    }
}
