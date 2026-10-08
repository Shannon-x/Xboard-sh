<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\EmailVerification;
use App\Http\Requests\User\OrderSave;
use App\Http\Resources\OrderResource;
use App\Models\BalanceLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\CouponService;
use App\Services\OrderService;
use App\Services\PlanService;
use App\Services\BalanceLedger;
use App\Services\UserService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function fetch(Request $request)
    {
        $request->validate([
            'status' => 'nullable|integer|in:0,1,2,3',
        ]);
        $orders = Order::with('plan')
            ->where('user_id', $request->user()->id)
            ->when($request->input('status') !== null, function ($query) use ($request) {
                $query->where('status', $request->input('status'));
            })
            ->orderBy('created_at', 'DESC')
            ->get();

        return $this->success(OrderResource::collection($orders));
    }

    public function detail(Request $request)
    {
        $request->validate([
            'trade_no' => 'required|string',
        ]);
        $order = Order::with(['payment', 'plan'])
            ->where('user_id', $request->user()->id)
            ->where('trade_no', $request->input('trade_no'))
            ->first();
        if (!$order) {
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

    public function save(OrderSave $request)
    {
        $request->validate([
            'plan_id' => 'required|exists:App\Models\Plan,id',
            'period' => 'required|string'
        ]);

        $user = User::findOrFail($request->user()->id);
        $userService = app(UserService::class);

        // 邮箱软验证的宽限期已过：先验证再下单（前端收到 reason=email_unverified 弹验证面板）
        EmailVerification::assertAllowed($user);

        if ($userService->isNotCompleteOrderByUserId($user->id)) {
            throw new ApiException(__('You have an unpaid or pending order, please try again later or cancel it'));
        }

        $plan = Plan::findOrFail($request->input('plan_id'));
        $planService = new PlanService($plan);

        $planService->validatePurchase($user, $request->input('period'));

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

    public function checkout(Request $request, CheckoutService $checkoutService)
    {
        $request->validate([
            'trade_no' => 'required|string',
            'method' => 'nullable|integer',
        ]);
        $method = $request->input('method');
        $payment = $method !== null ? Payment::find((int) $method) : null;
        $order = Order::where('trade_no', (string) $request->input('trade_no'))
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$order) {
            throw new ApiException(__('Order does not exist or has been paid'));
        }
        // 绑定支付方式、算手续费、0 元开通或向网关要收款地址都在 CheckoutService，账单的免登录付款与此共用
        return response($checkoutService->checkout($order, $payment, [
            'stripe_token' => $request->input('token'),
        ]));
    }

    public function check(Request $request)
    {
        $tradeNo = $request->input('trade_no');
        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$order) {
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

        $methods->each(function ($method) {
            $method->setAttribute('supports_customization', !in_array(strtolower((string) $method->payment), ['stripesubscription', 'paypalsubscription'], true));
        });
        return $this->success($methods);
    }

    public function cancel(Request $request)
    {
        if (empty($request->input('trade_no'))) {
            return $this->fail([422, __('Invalid parameter')]);
        }
        $order = Order::where('trade_no', $request->input('trade_no'))
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$order) {
            return $this->fail([400, __('Order does not exist')]);
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
