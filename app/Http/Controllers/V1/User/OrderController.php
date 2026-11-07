<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\OrderSave;
use App\Http\Resources\OrderResource;
use App\Http\Resources\RechargeOrderResource;
use App\Models\BalanceLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\RechargeOrder;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\CouponService;
use App\Services\OrderService;
use App\Services\PlanService;
use App\Services\BalanceLedger;
use App\Services\Recharge\RechargeService;
use App\Services\UserService;
use App\Support\PaymentSessions;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    /**
     * 订单列表。余额充值单与套餐订单放在同一个列表里（前端按 plan_id=0 / period=deposit 认出充值单），
     * 按创建时间倒序合并。
     */
    public function fetch(Request $request)
    {
        $request->validate([
            'status' => 'nullable|integer|in:0,1,2,3',
        ]);
        $userId = $request->user()->id;
        $status = $request->input('status');
        $orders = Order::with('plan')
            ->where('user_id', $userId)
            ->when($status !== null, function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->orderBy('created_at', 'DESC')
            ->get();
        $recharges = RechargeOrder::where('user_id', $userId)
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderBy('created_at', 'DESC')
            ->get();

        if ($recharges->isEmpty()) {
            return $this->success(OrderResource::collection($orders));
        }

        $items = collect(OrderResource::collection($orders)->resolve($request))
            ->merge(RechargeOrderResource::collection($recharges)->resolve($request))
            ->sortByDesc('created_at')
            ->values();
        return $this->success($items);
    }

    public function detail(Request $request)
    {
        $request->validate([
            'trade_no' => 'required|string',
        ]);
        $tradeNo = PaymentSessions::resolveTradeNo((string) $request->input('trade_no'));
        $order = Order::with(['payment', 'plan'])
            ->where('user_id', $request->user()->id)
            ->where('trade_no', $tradeNo)
            ->first();
        if (!$order) {
            $recharge = RechargeOrder::with('payment')
                ->where('user_id', $request->user()->id)
                ->where('trade_no', $tradeNo)
                ->first();
            if ($recharge) {
                return $this->success(RechargeOrderResource::make($recharge));
            }
            return $this->fail([400, __('Order does not exist or has been paid')]);
        }
        $order['try_out_plan_id'] = (int) admin_setting('try_out_plan_id');
        if (!$order->plan) {
            return $this->fail([400, __('Subscription plan does not exist')]);
        }
        if ($order->surplus_order_ids) {
            $order['surplus_orders'] = Order::whereIn('id', $order->surplus_order_ids)->get();
        }
        return $this->success(OrderResource::make($order));
    }

    public function save(OrderSave $request, RechargeService $rechargeService)
    {
        if ($request->isDeposit()) {
            $recharge = $rechargeService->create($request->user(), (int) $request->input('deposit_amount'));
            return $this->success($recharge->trade_no);
        }

        $request->validate([
            'plan_id' => 'required|exists:App\Models\Plan,id',
            'period' => 'required|string'
        ]);

        $user = User::findOrFail($request->user()->id);
        $userService = app(UserService::class);

        if ($userService->isNotCompleteOrderByUserId($user->id)) {
            throw new ApiException(__('You have an unpaid or pending order, please try again later or cancel it'));
        }

        $plan = Plan::findOrFail($request->input('plan_id'));
        $planService = new PlanService($plan);

        $planService->validatePurchase($user, $request->input('period'));

        if (!$request->boolean('confirm_repeat')) {
            OrderService::assertNotRecentRepeat($user, $plan, (string) $request->input('period'));
        }

        $order = OrderService::createFromRequest(
            $user,
            $plan,
            $request->input('period'),
            $request->input('coupon_code'),
            $request->planOptions(),
            $request->input('expected_amount'),
        );

        return $this->success($order->trade_no);
    }

    protected function applyCoupon(Order $order, string $couponCode): void
    {
        $couponService = new CouponService($couponCode);
        if (!$couponService->use($order)) {
            throw new ApiException(__('Coupon failed'));
        }
        $order->coupon_id = $couponService->getId();
    }

    protected function handleUserBalance(Order $order, User $user, UserService $userService): void
    {
        $remainingBalance = $user->balance - $order->total_amount;

        if ($remainingBalance > 0) {
            if (!$userService->addBalance($order->user_id, -$order->total_amount, BalanceLog::TYPE_ORDER_PAY,
                BalanceLedger::orderCtx($order, '余额支付'))) {
                throw new ApiException(__('Insufficient balance'));
            }
            $order->balance_amount = $order->total_amount;
            $order->total_amount = 0;
        } else {
            if (!$userService->addBalance($order->user_id, -$user->balance, BalanceLog::TYPE_ORDER_PAY,
                BalanceLedger::orderCtx($order, '余额抵扣部分金额'))) {
                throw new ApiException(__('Insufficient balance'));
            }
            $order->balance_amount = $user->balance;
            $order->total_amount = $order->total_amount - $user->balance;
        }
    }

    public function checkout(Request $request, CheckoutService $checkoutService, RechargeService $rechargeService)
    {
        $request->validate([
            'trade_no' => 'required|string',
            'method' => 'nullable|integer',
        ]);
        $method = $request->input('method');
        $payment = $method !== null ? Payment::find((int) $method) : null;
        // 网关付完跳回的地址、前端轮询带的都可能是支付会话号
        $tradeNo = PaymentSessions::resolveTradeNo((string) $request->input('trade_no'));
        $extra = ['stripe_token' => $request->input('token')];
        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$order) {
            $recharge = RechargeOrder::where('trade_no', $tradeNo)
                ->where('user_id', $request->user()->id)
                ->first();
            if ($recharge) {
                return response($rechargeService->checkout($recharge, $payment, $extra));
            }
            throw new ApiException(__('Order does not exist or has been paid'));
        }
        // 绑定支付方式、算手续费、0 元开通或向网关要收款地址都在 CheckoutService，账单的免登录付款与此共用
        return response($checkoutService->checkout($order, $payment, $extra));
    }

    public function check(Request $request)
    {
        $tradeNo = PaymentSessions::resolveTradeNo((string) $request->input('trade_no'));
        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$order) {
            $recharge = RechargeOrder::where('trade_no', $tradeNo)
                ->where('user_id', $request->user()->id)
                ->first();
            if ($recharge) {
                return $this->success($recharge->status);
            }
            return $this->fail([400, __('Order does not exist')]);
        }
        return $this->success($order->status);
    }

    public function getPaymentMethod()
    {
        $methods = Payment::select([
            'id',
            'name',
            'payment',
            'icon',
            'handling_fee_fixed',
            'handling_fee_percent'
        ])
            ->where('enable', 1)
            ->orderBy('sort', 'ASC')
            ->get();

        // 订阅式网关按周期反复扣款，自选套餐（快照价）不能用；余额充值还要求回调经过核心（自己接 webhook 的网关
        // 只认套餐订单）。前端据此过滤支付方式
        $methods->each(function ($method) {
            $method->setAttribute('supports_customization', !CheckoutService::isSubscriptionGateway($method));
            $method->setAttribute('supports_recharge', CheckoutService::supportsRecharge($method));
        });
        return $this->success($methods);
    }

    public function cancel(Request $request, RechargeService $rechargeService)
    {
        if (empty($request->input('trade_no'))) {
            return $this->fail([422, __('Invalid parameter')]);
        }
        $tradeNo = PaymentSessions::resolveTradeNo((string) $request->input('trade_no'));
        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$order) {
            $recharge = RechargeOrder::where('trade_no', $tradeNo)
                ->where('user_id', $request->user()->id)
                ->first();
            if (!$recharge) {
                return $this->fail([400, __('Order does not exist')]);
            }
            if (!$rechargeService->cancel($recharge)) {
                return $this->fail([400, __('You can only cancel pending orders')]);
            }
            return $this->success(true);
        }
        if ($order->status !== 0) {
            return $this->fail([400, __('You can only cancel pending orders')]);
        }
        $orderService = new OrderService($order);
        if (!$orderService->cancel()) {
            return $this->fail([400, __('Cancel failed')]);
        }
        return $this->success(true);
    }
}
