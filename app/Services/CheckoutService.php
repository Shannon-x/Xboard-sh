<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * 收银台的最后一步：把支付方式绑到订单上（含手续费），0 元单直接开通，否则向支付网关要收款地址。
 *
 * 登录后的收银台（OrderController::checkout）和续费账单的免登录付款（BillingPayService）共用这一段，
 * 绑定、手续费、金额异常、自选套餐不能用订阅式网关这些规则只在这里写一次。
 */
class CheckoutService
{
    /**
     * @param array{stripe_token?: string|null, return_url?: string|null} $extra 透传给网关的附加参数
     * @return array{type: int, data: mixed} 与 /user/order/checkout 的响应体一致：type=-1 是余额 / 优惠全额抵扣后已开通，1 是要跳转的收款地址
     * @throws ApiException
     */
    public function checkout(Order $order, ?Payment $payment, array $extra = []): array
    {
        $order = DB::transaction(function () use ($order, $payment) {
            $locked = Order::where('id', $order->id)->lockForUpdate()->first();
            if (!$locked || (int) $locked->status !== Order::STATUS_PENDING) {
                throw new ApiException(__('Order does not exist or has been paid'));
            }

            if ((int) $locked->total_amount < 0) {
                throw new ApiException('订单金额异常，请重新下单');
            }
            if ((int) $locked->total_amount === 0) {
                return $locked;
            }
            if (!$payment || !$payment->enable) {
                throw new ApiException(__('Payment method is not available'));
            }
            if ($locked->plan_snapshot && self::isSubscriptionGateway($payment)) {
                throw new ApiException('自选套餐请使用单次支付方式');
            }

            // 首次 checkout 后支付配置不可变。否则旧通道收款链接和新的 payment_id/
            // handling_amount 会脱钩，回调时无法可靠绑定金额与商户。
            if ($locked->payment_id !== null && (int) $locked->payment_id !== (int) $payment->id) {
                throw new ApiException('订单已绑定其他支付方式，请取消订单后重新下单');
            }

            if ($locked->payment_id === null) {
                $fixedFee = max(0, (int) ($payment->handling_fee_fixed ?? 0));
                $percentFee = max(0, min(100, (float) ($payment->handling_fee_percent ?? 0)));
                $handlingAmount = (int) round(((int) $locked->total_amount * ($percentFee / 100)) + $fixedFee);
                $locked->handling_amount = $handlingAmount > 0 ? $handlingAmount : null;
                $locked->payment_id = $payment->id;
                if (!$locked->save()) {
                    throw new ApiException(__('Request failed, please try again later'));
                }
            }

            if ((int) $locked->total_amount + (int) ($locked->handling_amount ?? 0) <= 0) {
                throw new ApiException('订单应付金额异常，请重新下单');
            }

            return $locked;
        });

        // 只有恰好为 0 的合法优惠/余额全额抵扣订单才能进入免费流程；负数已在锁内拒绝。
        if ((int) $order->total_amount === 0) {
            if (!(new OrderService($order))->paid($order->trade_no)) {
                throw new ApiException('支付失败');
            }
            return ['type' => -1, 'data' => true];
        }

        $result = $this->gateway($payment)->pay([
            'trade_no' => $order->trade_no,
            'total_amount' => (int) $order->total_amount + (int) ($order->handling_amount ?? 0),
            'user_id' => $order->user_id,
            'stripe_token' => $extra['stripe_token'] ?? null,
            'return_url' => $extra['return_url'] ?? null,
        ]);
        return ['type' => $result['type'], 'data' => $result['data']];
    }

    /** Stripe / PayPal 的订阅式网关：每单都会在网关那边开一个周期扣款，自选套餐和一次性账单都不能用 */
    public static function isSubscriptionGateway(Payment $payment): bool
    {
        return in_array(strtolower((string) $payment->payment), ['stripesubscription', 'paypalsubscription'], true);
    }

    /** 网关实例单独一个方法：测试里换成假网关，不用真装支付插件 */
    protected function gateway(Payment $payment): PaymentService
    {
        return new PaymentService($payment->payment, $payment->id);
    }
}
