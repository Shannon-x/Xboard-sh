<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Support\FeatureFlag;
use App\Support\PaymentMetrics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\Plugin\HookManager;

class PaymentController extends Controller
{
    public function notify($method, $uuid, Request $request)
    {
        HookManager::call('payment.notify.before', [$method, $uuid, $request]);
        try {
            $paymentService = new PaymentService($method, null, $uuid);
            $verify = $paymentService->notify($request->input());
            if (!$verify) {
                HookManager::call('payment.notify.failed', [$method, $uuid, $request]);
                return $this->fail([422, 'verify error']);
            }
            // 兼容历史插件：notify() 返回非数组真值（老版直接 return 'IPN OK' 之类字符串）时，
            // 视为「已确认、无需开单」回 200。绝不能往下走 $verify['trade_no']——对字符串做
            // 字符串下标解引用在 PHP8 下抛 TypeError。
            if (!is_array($verify)) {
                return is_string($verify) ? $verify : 'success';
            }
            // 已验签但非「可结算」事件（如 Coinbase charge:created、BTCPay 未结清发票、
            // CoinPayments pending）：插件返回 ['acknowledge' => true]，回 200 让网关停止重投，不开单。
            if (!empty($verify['acknowledge'])) {
                return $verify['custom_result'] ?? 'success';
            }
            HookManager::call('payment.notify.verified', $verify);
            if (!$this->handle($verify['trade_no'] ?? null, $verify['callback_no'] ?? null, $uuid)) {
                return $this->fail([400, 'handle error']);
            }
            return (isset($verify['custom_result']) ? $verify['custom_result'] : 'success');
        } catch (\Throwable $e) {
            // \Throwable 而非 \Exception：插件里的 TypeError / class-not-found 等属 \Error，
            // 旧 catch(\Exception) 兜不住会直接 500 且逃逸。Log::error 本身也可能因日志通道
            // 故障再抛，套一层吞掉，保证至少能回受控的 fail 响应让网关重投。
            try {
                Log::error($e);
            } catch (\Throwable $ignored) {
            }
            return $this->fail([500, 'fail']);
        }
    }

    private function handle($tradeNo, $callbackNo, $uuid = null)
    {
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            return $this->fail([400202, 'order is not found']);
        }
        // 迟到支付：订单已不是 PENDING（最常见是 PENDING 超 2 小时被 OrderHandleJob 自动取消后，
        // 用户才在网关侧完成真实支付）。此前无条件 return true 静默 ACK——钱到账却不开通、不退款、
        // 也没有任何日志。改为进入迟到支付处理：在严格安全条件下重新开通，否则转人工告警（不再静默）。
        if ($order->status !== Order::STATUS_PENDING) {
            return $this->handleLatePayment($order, $callbackNo, $uuid);
        }

        // 回调网关必须与订单 checkout 时绑定的 payment_id 一致：
        // 防止已知某 PENDING 订单 trade_no（可枚举）后，用攻击者掌握的另一网关 uuid
        // 端点提交「该网关验签通过的回调」来翻转目标订单。
        //
        // 受 payment_gateway_bind 控制，默认 warn（仅记录不拒收）：用户可能对同一 PENDING 订单
        // 先 checkout 网关 A、再改 checkout 网关 B（payment_id 被改写），随后才真正支付 A，
        // 此时 A 的合法回调会与 payment_id 不一致。先观察 PaymentMetrics 计数再切 enforce。
        // payment_id 为空（历史订单 / 免费单）时跳过，保持前向兼容。
        $bindMode = FeatureFlag::mode('payment_gateway_bind');
        if ($bindMode !== 'off' && $uuid !== null && $order->payment_id !== null) {
            $payment = Payment::where('uuid', $uuid)->first();
            if ($payment && (int) $order->payment_id !== (int) $payment->id) {
                PaymentMetrics::warn('webhook.payment_id_mismatch', [
                    'trade_no' => $tradeNo,
                    'order_payment_id' => (int) $order->payment_id,
                    'callback_payment_id' => (int) $payment->id,
                ]);
                if ($bindMode === 'enforce') {
                    return false;
                }
            }
        }

        $orderService = new OrderService($order);
        if (!$orderService->paid($callbackNo)) {
            return false;
        }

        HookManager::call('payment.notify.success', $order);
        return true;
    }

    /**
     * 迟到支付处理：订单已非 PENDING 时进入。
     *
     * - 开通中 / 已完成：真正的重复通知，幂等 ACK。
     * - 已取消：多为超时自动取消后用户才真正付款。在严格安全前提下尝试重新开通
     *   （见 OrderService::reopenFromCancelled 的安全闸门），不满足条件的转人工告警。
     * - 其它状态：记录后 ACK。
     *
     * 所有分支都会 return true 向网关 ACK（回调签名已验过，是真实支付，无需网关重投），
     * 但与旧实现的关键区别是：不再静默——每一种「无法自动开通」都有 error 日志 + 指标可追踪。
     */
    private function handleLatePayment(Order $order, $callbackNo, $uuid)
    {
        $status = (int) $order->status;

        // 已在开通中或已完成：正常的重复回调，幂等确认。
        if (in_array($status, [Order::STATUS_PROCESSING, Order::STATUS_COMPLETED], true)) {
            return true;
        }

        // 非「已取消」的其它终态（如已折抵）：记录后 ACK，不自动处理。
        if ($status !== Order::STATUS_CANCELLED) {
            return $this->flagLatePaymentForReview($order, $callbackNo, 'unexpected_status:' . $status);
        }

        // 已取消订单收到真实支付。重开是敏感操作，这里**强制**校验回调网关与订单
        // checkout 绑定的 payment_id 一致（不依赖全局 payment_gateway_bind 的 warn/enforce 开关）。
        // 结合上游已完成的签名校验与 P0-1 的 method↔uuid 绑定，攻击者无法为他人订单伪造回调，
        // 只能重开自己真实支付过的订单。payment_id 为空（历史/免费单）无法核验来源 → 转人工。
        $payment = $uuid !== null ? Payment::where('uuid', $uuid)->first() : null;
        if (
            $order->payment_id === null
            || !$payment
            || (int) $order->payment_id !== (int) $payment->id
        ) {
            return $this->flagLatePaymentForReview($order, $callbackNo, 'gateway_mismatch');
        }

        $orderService = new OrderService($order);
        $result = $orderService->reopenFromCancelled($callbackNo);

        switch ($result) {
            case 'reopened':
                Log::info('[late-payment] 已取消订单收到真实支付，已在安全条件下重新开通', [
                    'trade_no' => (string) $order->trade_no,
                    'order_id' => (int) $order->id,
                    'user_id' => (int) $order->user_id,
                    'callback_no' => (string) $callbackNo,
                ]);
                PaymentMetrics::inc('order.late_paid.reopened');
                HookManager::call('payment.notify.success', $orderService->order);
                return true;
            case 'duplicate':
                return true;
            default: // 'manual' | 'missing' | 'unexpected' | 'error'
                return $this->flagLatePaymentForReview($order, $callbackNo, $result);
        }
    }

    /**
     * 标记迟到支付需人工处理：记录 error 日志 + 指标，供运营人工退款或手动开通。
     * 仍向网关 ACK（return true）避免无意义重投，但已不再静默。
     */
    private function flagLatePaymentForReview(Order $order, $callbackNo, string $reason)
    {
        $context = [
            'trade_no' => (string) $order->trade_no,
            'order_id' => (int) $order->id,
            'user_id' => (int) $order->user_id,
            'status' => (int) $order->status,
            'callback_no' => (string) $callbackNo,
            'reason' => $reason,
        ];
        PaymentMetrics::warn('order.late_paid.manual_review', $context);
        Log::error('[late-payment] 已取消/非常规状态订单收到真实支付，无法安全自动开通，需人工处理', $context);
        return true;
    }
}
