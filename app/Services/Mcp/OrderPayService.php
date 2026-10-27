<?php

namespace App\Services\Mcp;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\RechargeOrder;
use App\Models\User;
use App\Services\Billing\BillingPayService;
use App\Services\CheckoutService;
use App\Services\OrderService;
use App\Services\Recharge\RechargeService;

/**
 * 订单的免登录付款链接：AI 助手替客户下单后，把 <app_url>/pay/o-<订单号>-<签名> 交给客户，
 * 客户在手机上点开就能付，不用先登录面板。
 *
 * 和续费账单的 /pay 链接（BillingPayService）同一个思路：签名只证明「链接是我们发的」，
 * 能不能付按订单现状每次重算；付款走同一个收银台核心 CheckoutService（充值单走 RechargeService），
 * 网关、金额、回调与面板收银台完全相同。这里不建单、不改价，只给已经建好的待付单一个付款入口。
 *
 * 链接持有者能看到：套餐 / 周期 / 金额 / 打码邮箱；能做：付这一单、取消这一单。别的都做不了。
 */
class OrderPayService
{
    public const STATE_PENDING = 'pending';      // 待付：可以付，也可以取消
    public const STATE_PAID = 'paid';            // 已付（开通中 / 已完成 / 已折抵）
    public const STATE_CLOSED = 'closed';        // 已取消（手动 / 超时回收），链接失效
    public const STATE_UNAVAILABLE = 'unavailable';  // 账号被封等

    public const KIND_ORDER = 'order';
    public const KIND_RECHARGE = 'recharge';

    private const TOKEN_PATTERN = '/^o-([0-9A-Za-z]{8,40})-([a-f0-9]{32})$/';

    public static function token(string $tradeNo): string
    {
        return 'o-' . $tradeNo . '-' . self::signature($tradeNo);
    }

    public static function url(string $tradeNo): string
    {
        return rtrim((string) admin_setting('app_url', ''), '/') . '/pay/' . self::token($tradeNo);
    }

    private static function signature(string $tradeNo): string
    {
        return substr(hash_hmac('sha256', 'order-pay.' . $tradeNo, (string) config('app.key')), 0, 32);
    }

    /**
     * 凭据 → 订单（或充值单）。签名不对一律当不存在。
     * @return array{kind: string, order: Order|RechargeOrder}|null
     */
    public function resolve(string $token): ?array
    {
        if (!preg_match(self::TOKEN_PATTERN, $token, $m) || !hash_equals(self::signature($m[1]), $m[2])) {
            return null;
        }
        if ($order = Order::where('trade_no', $m[1])->first()) {
            return ['kind' => self::KIND_ORDER, 'order' => $order];
        }
        if ($recharge = RechargeOrder::where('trade_no', $m[1])->first()) {
            return ['kind' => self::KIND_RECHARGE, 'order' => $recharge];
        }
        return null;
    }

    public function state(array $target): string
    {
        $order = $target['order'];
        $user = User::find($order->user_id);
        if (!$user || $user->banned) {
            return self::STATE_UNAVAILABLE;
        }
        $status = (int) $order->status;
        if ($target['kind'] === self::KIND_RECHARGE) {
            return match ($status) {
                RechargeOrder::STATUS_PENDING => self::STATE_PENDING,
                RechargeOrder::STATUS_COMPLETED => self::STATE_PAID,
                default => self::STATE_CLOSED,
            };
        }
        return match ($status) {
            Order::STATUS_PENDING => self::STATE_PENDING,
            Order::STATUS_PROCESSING, Order::STATUS_COMPLETED, Order::STATUS_DISCOUNTED => self::STATE_PAID,
            default => self::STATE_CLOSED,
        };
    }

    /** 付款页要的一切：状态、订单摘要、应付、可用支付方式 */
    public function view(array $target): array
    {
        $state = $this->state($target);
        $order = $target['order'];
        $user = User::find($order->user_id);
        return [
            'state' => $state,
            'kind' => $target['kind'],
            'order' => $target['kind'] === self::KIND_RECHARGE ? self::rechargeInfo($order) : self::orderInfo($order),
            'email' => $user ? BillingPayService::maskEmail((string) $user->email) : null,
            'pay_until' => (int) $order->created_at + ($target['kind'] === self::KIND_RECHARGE
                ? RechargeService::PENDING_CANCEL_AFTER : \App\Jobs\OrderHandleJob::PENDING_CANCEL_AFTER),
            'methods' => $state === self::STATE_PENDING ? $this->methods() : [],
        ];
    }

    /** 付款页轮询 */
    public function check(array $target): array
    {
        return ['state' => $this->state($target), 'order_status' => (int) $target['order']->status];
    }

    /**
     * 付款：只接待付单，走面板收银台同一个核心。
     * @return array{type: int, data: mixed, trade_no: string}
     * @throws ApiException
     */
    public function checkout(array $target, ?int $methodId): array
    {
        if ($this->state($target) !== self::STATE_PENDING) {
            throw new ApiException(__('Order does not exist or has been paid'));
        }
        $order = $target['order'];
        $payment = $methodId !== null ? Payment::where('id', $methodId)->where('enable', 1)->first() : null;
        if ($payment && CheckoutService::isSubscriptionGateway($payment)) {
            throw new ApiException(__('Payment method is not available'));
        }
        $extra = ['return_url' => self::url((string) $order->trade_no)];
        $result = $target['kind'] === self::KIND_RECHARGE
            ? app(RechargeService::class)->checkout($order, $payment, $extra)
            : app(CheckoutService::class)->checkout($order, $payment, $extra);
        return $result + ['trade_no' => (string) $order->trade_no];
    }

    /** 取消待付单；订单下单时占用的余额由 OrderService 退回 */
    public function cancel(array $target): bool
    {
        if ($this->state($target) !== self::STATE_PENDING) {
            throw new ApiException(__('You can only cancel pending orders'));
        }
        $order = $target['order'];
        return $target['kind'] === self::KIND_RECHARGE
            ? app(RechargeService::class)->cancel($order)
            : (new OrderService($order))->cancel();
    }

    private function methods(): array
    {
        return Payment::select(['id', 'name', 'payment', 'icon', 'handling_fee_fixed', 'handling_fee_percent'])
            ->where('enable', 1)->orderBy('sort', 'ASC')->get()
            ->reject(fn (Payment $p) => CheckoutService::isSubscriptionGateway($p))
            ->map(fn (Payment $p) => [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'payment' => (string) $p->payment,
                'icon' => $p->icon,
                'handling_fee_fixed' => (int) ($p->handling_fee_fixed ?? 0),
                'handling_fee_percent' => (float) ($p->handling_fee_percent ?? 0),
            ])->values()->all();
    }

    public static function orderInfo(Order $order): array
    {
        $plan = $order->plan_id ? Plan::find($order->plan_id) : null;
        $period = (string) $order->period;
        $periodKey = \App\Services\PlanService::getPeriodKey($period);
        return [
            'trade_no' => (string) $order->trade_no,
            'type' => (int) $order->type,
            'status' => (int) $order->status,
            'plan_name' => $plan ? (string) $plan->name : '',
            'period' => $periodKey,
            'period_name' => Plan::getAvailablePeriods()[$periodKey]['name'] ?? $periodKey,
            'total_amount' => (int) $order->total_amount,
            'balance_amount' => (int) ($order->balance_amount ?? 0),
            'discount_amount' => (int) ($order->discount_amount ?? 0),
            'surplus_amount' => (int) ($order->surplus_amount ?? 0),
            'handling_amount' => (int) ($order->handling_amount ?? 0),
            'payment_id' => $order->payment_id !== null ? (int) $order->payment_id : null,
            'created_at' => (int) $order->created_at,
        ];
    }

    public static function rechargeInfo(RechargeOrder $recharge): array
    {
        return [
            'trade_no' => (string) $recharge->trade_no,
            'type' => 0,
            'status' => (int) $recharge->status,
            'plan_name' => '',
            'period' => 'recharge',
            'period_name' => '',
            'total_amount' => (int) $recharge->pay_amount,
            'deposit_amount' => (int) $recharge->deposit_amount,
            'bonus_amount' => (int) $recharge->bonus_amount,
            'balance_amount' => 0,
            'discount_amount' => 0,
            'surplus_amount' => 0,
            'handling_amount' => (int) ($recharge->handling_amount ?? 0),
            'payment_id' => $recharge->payment_id !== null ? (int) $recharge->payment_id : null,
            'created_at' => (int) $recharge->created_at,
        ];
    }
}
