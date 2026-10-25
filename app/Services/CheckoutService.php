<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Support\PaymentGatewayBinding;
use App\Support\PaymentSessions;
use Illuminate\Support\Facades\DB;

/**
 * 收银台的最后一步：把支付方式绑到单据上（含手续费），0 元单直接开通，否则向支付网关要收款地址。
 *
 * 登录后的收银台（OrderController::checkout）、续费账单的免登录付款（BillingPayService）和余额充值
 * （RechargeService::checkout）共用这一段：绑定、手续费、拉起网关的规则只在这里写一次。
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
        $this->rejectUndeliverableTopup($order);

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

            if (self::mustRebind($locked->payment_id === null ? null : (int) $locked->payment_id, $payment)) {
                $handlingAmount = self::handlingFee($payment, (int) $locked->total_amount);
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

        return $this->requestPayment(
            $payment,
            PaymentSessions::SUBJECT_ORDER,
            (int) $order->id,
            (string) $order->trade_no,
            (int) $order->user_id,
            (int) $order->total_amount + (int) ($order->handling_amount ?? 0),
            $extra
        );
    }

    /**
     * 单据上的支付方式绑定规则：在持有单据行锁时调用。
     *
     * 第一次选支付方式时绑定并按它算手续费。之后再换：
     * - 同一商户的另一条展示配置（「支付宝支付」「微信支付」两个按钮共用一套 url+pid+key）照常放行、
     *   不改绑——收款会话互认，回调侧按 PaymentGatewayBinding 同源判定放行；
     * - 跨商户 / 跨网关：只有支付会话可用时才放行并改绑。会话把每次发起的支付和它报给网关的应付额
     *   单独记下来，旧通道的收款链接付了钱一样能认领（回调按会话号找单，期望金额取会话冻结的值）；
     *   会话不可用时旧链接付的钱会因为「回调网关与单据绑定网关不同源」被拒，所以照旧要求取消重下。
     *
     * @return bool 是否要按这条支付配置重算手续费并改绑
     * @throws ApiException 不允许换成这条支付配置
     */
    public static function mustRebind(?int $boundPaymentId, Payment $payment): bool
    {
        $paymentId = (int) $payment->id;
        if ($boundPaymentId === null) {
            return true;
        }
        if ($boundPaymentId === $paymentId) {
            return false;
        }
        $bound = Payment::find($boundPaymentId);
        // 旧通道发出去的收款链接也得认会话号，旧链接付的钱才能按会话认领；旧通道的链接带的是单号本身
        //（StripeCheckout、AlipayF2F 一类），改绑后付了旧链接，回调会因为「与单据绑定网关不同源」被拒
        if (PaymentSessions::available($payment) && ($bound === null || PaymentSessions::refAware($bound))) {
            return true;
        }
        // 自己接 webhook 的网关只认精确的 payment_id，同商户别名也不能这样切
        if ($bound && !self::hasOwnWebhook($payment) && PaymentGatewayBinding::sameMerchant($bound, $payment)) {
            return false;
        }
        throw new ApiException('订单已绑定其他支付方式，请取消订单后重新下单');
    }

    /** 手续费（分）：按支付配置的固定费 + 百分比，百分比夹在 0–100 */
    public static function handlingFee(Payment $payment, int $amount): int
    {
        $fixedFee = max(0, (int) ($payment->handling_fee_fixed ?? 0));
        $percentFee = max(0, min(100, (float) ($payment->handling_fee_percent ?? 0)));
        return (int) round(($amount * ($percentFee / 100)) + $fixedFee);
    }

    /**
     * 向网关要收款地址。
     *
     * 发给网关的 out_trade_no 在支付会话可用时是会话号而不是单号：易支付的 submit.php 是「创建预下单」，
     * 同一个 out_trade_no 只能建一次，用单号就意味着一张单只能发起一次支付。会话号在轮询期间复用、
     * 空闲一段时间后才换新（见 PaymentAttempt 插件），不会把网关侧会话反复重建。
     * 付完跳回的地址用真实单号：前端拿它查详情，不必再把会话号归一一遍。
     *
     * @param array{stripe_token?: string|null, return_url?: string|null} $extra
     * @return array{type: int, data: mixed}
     */
    public function requestPayment(
        Payment $payment,
        string $subjectType,
        int $subjectId,
        string $tradeNo,
        int $userId,
        int $expectedAmount,
        array $extra = []
    ): array {
        $gatewayTradeNo = PaymentSessions::begin($subjectType, $subjectId, $tradeNo, $userId, $payment, $expectedAmount);

        $result = $this->gateway($payment)->pay([
            'trade_no' => $gatewayTradeNo,
            'total_amount' => $expectedAmount,
            'user_id' => $userId,
            'stripe_token' => $extra['stripe_token'] ?? null,
            'return_url' => $extra['return_url'] ?? source_base_url('/#/order/' . $tradeNo),
        ]);
        return ['type' => $result['type'], 'data' => $result['data']];
    }

    /**
     * 加购单下单时订阅还有效，到点付款时可能已经到期 / 换了套餐 / 被停用——这时付了钱也加不上流量。
     * 在要收款地址（或余额全额抵扣直接开通）之前拦下：把这张待付单取消（余额抵扣随之退回），让用户先续费。
     * 已经拿到收款链接、在网关页面上跨过到期才付的，由 OrderService::open() 退回余额兜底。
     */
    private function rejectUndeliverableTopup(Order $order): void
    {
        if ((string) $order->period !== Plan::PERIOD_TRAFFIC_TOPUP || (int) $order->status !== Order::STATUS_PENDING) {
            return;
        }
        $problem = OrderService::topupSubscriptionProblem(User::find($order->user_id), $order);
        if ($problem === null) {
            return;
        }
        (new OrderService($order))->cancel();
        throw new ApiException("{$problem}，流量包已无法加到当前订阅。这张订单已自动取消，余额抵扣（如有）已退回；请先续费订阅，再重新购买流量包");
    }

    /** Stripe / PayPal 的订阅式网关：每单都会在网关那边开一个周期扣款，自选套餐、一次性账单和充值都不能用 */
    public static function isSubscriptionGateway(Payment $payment): bool
    {
        return in_array(strtolower((string) $payment->payment), ['stripesubscription', 'paypalsubscription', 'stripesub'], true);
    }

    /**
     * 自己接网关 webhook、不走核心 /payment/notify 的支付插件。它们的回调处理只认套餐订单（按 trade_no 查
     * v2_order，还要求 payment_id 精确相等），余额充值单付了钱也入不了账。
     */
    private const OWN_WEBHOOK_GATEWAYS = ['stripecheckout', 'stripesub', 'stripesubscription', 'paypalsubscription'];

    public static function hasOwnWebhook(Payment $payment): bool
    {
        return in_array(strtolower((string) $payment->payment), self::OWN_WEBHOOK_GATEWAYS, true);
    }

    /** 能否用来付余额充值：回调必须经过核心 PaymentController，充值单才认得出来 */
    public static function supportsRecharge(Payment $payment): bool
    {
        return !self::hasOwnWebhook($payment);
    }

    /** 网关实例单独一个方法：测试里换成假网关，不用真装支付插件 */
    protected function gateway(Payment $payment): PaymentService
    {
        return new PaymentService($payment->payment, $payment->id);
    }
}
