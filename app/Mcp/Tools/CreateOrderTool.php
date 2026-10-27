<?php

namespace App\Mcp\Tools;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\User;
use App\Services\Mcp\McpAudit;
use App\Services\Mcp\McpConfig;
use App\Services\Mcp\McpOrderSpec;
use App\Services\Mcp\McpPresenter as P;
use App\Services\Mcp\OrderPayService;
use App\Services\OrderService;
use App\Services\PlanService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('create_order')]
#[Title('下单')]
#[Description('替客户下单（续费 / 新购 / 换套餐 / 加购流量 / 流量重置包），返回订单号、应付金额和付款链接。钱由客户本人在付款链接里付，订单 2 小时内不付会自动取消。只在客户明确说要买之后调用，并先用 quote_plan 报价给客户确认。账户有余额时会先自动抵扣（取消订单会退回）。')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld(false)]
class CreateOrderTool extends McpTool
{
    protected bool $ordering = true;

    public function schema(JsonSchema $schema): array
    {
        return [
            'mode' => $schema->string()->enum(McpOrderSpec::MODES)->description(McpOrderSpec::MODE_HELP)->required(),
            'plan_id' => $schema->integer()->description('purchase 时必填，来自 list_plans。'),
            'period' => $schema->string()->description('付款周期，同 quote_plan。purchase 必填；renew 可选。'),
            'topup_gb' => $schema->integer()->description('traffic_topup 时必填：加购多少 GB。')->min(1),
            'traffic_gb' => $schema->integer()->min(1)->description('purchase 可选：可自选流量的套餐选哪一档（GB）；按量套餐就是这次充多少 GB。来自 recommend_plan 的 quote_args，不传就是套餐默认流量。'),
            'addon_group_ids' => $schema->array()->items($schema->integer())->description('purchase 可选：要加选的增值线路（如三网优化）的 group_id，来自 recommend_plan 的 quote_args 或 list_plans 的 lines；不传就不加。'),
            'coupon_code' => $schema->string()->description('客户提供的优惠码，可选。'),
            'expected_price_yuan' => $schema->string()->description('quote_plan 返回的 price_yuan，原样传入；价格在此期间变了会拒绝下单，避免按新价悄悄下单。'),
            'confirm_repeat' => $schema->boolean()->description('几分钟内刚买过同一套餐时会被拦下；客户确认确实要再买一次才传 true。'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $user = User::findOrFail($user->id);
        $limit = McpConfig::fromSettings()->dailyOrderLimit;
        if ($this->token && McpAudit::ordersToday((int) $this->token->id) >= $limit) {
            throw new ApiException("这个令牌今天已经建了 {$limit} 单，达到上限。请明天再试，或到面板下单。");
        }

        $pending = Order::where('user_id', $user->id)->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_PROCESSING])->orderByDesc('id')->first();
        if ($pending) {
            $hint = (int) $pending->status === Order::STATUS_PENDING
                ? '可以先付这一单（付款链接：' . OrderPayService::url((string) $pending->trade_no) . '），或者用 cancel_order 取消后再下新单。'
                : '这一单正在开通，稍等一两分钟再下新单。';
            throw new ApiException("你有一张未完成的订单 {$pending->trade_no}（" . P::orderType((int) $pending->type) . '）。' . $hint);
        }

        $spec = McpOrderSpec::resolve($user, $request->all());
        // 与面板下单（OrderController::save）同一串检查与同一个下单服务：价格、优惠码、邀请首单折扣、库存、佣金都一样
        (new PlanService($spec['plan']))->validatePurchase($user, $spec['period']);
        if (!$request->get('confirm_repeat')) {
            OrderService::assertNotRecentRepeat($user, $spec['plan'], $spec['period']);
        }
        $expected = $request->get('expected_price_yuan');
        $expectedCents = is_numeric($expected) ? (int) round((float) $expected * 100) : null;
        $coupon = trim((string) ($request->get('coupon_code') ?? ''));

        $order = OrderService::createFromRequest(
            $user,
            $spec['plan'],
            $spec['period'],
            $coupon !== '' ? $coupon : null,
            $spec['options'],
            $expectedCents,
        );
        $this->auditRef = (string) $order->trade_no;
        $order->refresh();

        $cash = (int) $order->total_amount;
        return self::result([
            'trade_no' => (string) $order->trade_no,
            'order_kind' => P::orderType((int) $order->type),
            'plan_name' => (string) $spec['plan']->name,
            'period_name' => P::periodName($spec['period']),
            'discount_yuan' => P::yuan($order->discount_amount),
            'remaining_value_deducted_yuan' => P::yuan($order->surplus_amount),
            'balance_used_yuan' => P::yuan($order->balance_amount),
            'cash_due_yuan' => P::yuan($cash),
            'payment_url' => OrderPayService::url((string) $order->trade_no),
            'pay_within_minutes' => 120,
            'next_step' => $cash > 0
                ? '请客户点开 payment_url 选择支付方式付款；2 小时内未付会自动取消。'
                : '余额已全额抵扣，请客户点开 payment_url 确认开通。',
        ]);
    }
}
