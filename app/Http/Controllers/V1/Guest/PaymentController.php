<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\TelegramService;
use App\Support\FeatureFlag;
use App\Support\PaymentGatewayBinding;
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
        if (!is_string($tradeNo) || $tradeNo === '' || !is_string($callbackNo) || trim($callbackNo) === '') {
            return false;
        }
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            PaymentMetrics::warn('webhook.order_missing', ['trade_no' => $tradeNo]);
            return false;
        }
        $payment = $uuid !== null ? Payment::where('uuid', $uuid)->first() : null;
        if (!$payment) {
            return false;
        }
        // 迟到支付：订单已不是 PENDING（最常见是 PENDING 超 2 小时被 OrderHandleJob 自动取消后，
        // 用户才在网关侧完成真实支付）。此前无条件 return true 静默 ACK——钱到账却不开通、不退款、
        // 也没有任何日志。改为进入迟到支付处理：在严格安全条件下重新开通，否则转人工告警（不再静默）。
        if ($order->status !== Order::STATUS_PENDING) {
            return $this->handleLatePayment($order, $callbackNo, $payment);
        }

        // 回调网关必须与订单 checkout 时绑定的网关同源：
        // 防止已知某 PENDING 订单 trade_no（可枚举）后，用攻击者掌握的另一网关 uuid
        // 端点提交「该网关验签通过的回调」来翻转目标订单。
        //
        // 受 payment_gateway_bind 控制，默认 enforce。「同源」不是 payment_id 精确相等：
        // 同一个商户常被配成多条记录（「支付宝支付」「微信支付」两个按钮，url+pid+key
        // 完全相同），用户在收银台切换按钮会让订单绑定的 payment_id 与他实际付款的收款
        // 会话错位，合法回调因此被拒、钱到账却不开通。改为比对「插件类 + 商户凭证指纹」：
        // 共用同一套密钥的配置放行，密钥/商户号不同的一律照拒（见 PaymentGatewayBinding）。
        $bindMode = FeatureFlag::mode('payment_gateway_bind');
        $orderPaymentId = $order->payment_id === null ? null : (int) $order->payment_id;
        $callbackPaymentId = (int) $payment->id;
        if ($bindMode !== 'off' && $orderPaymentId !== $callbackPaymentId) {
            if (PaymentGatewayBinding::equivalent($orderPaymentId, $callbackPaymentId)) {
                // 同商户别名通道收款：合法，但仍留一条指标，便于发现「一个商户配了多条
                // 记录」这类面板配置问题。只记指标不打 warning，避免把正常支付刷成告警。
                PaymentMetrics::inc('webhook.gateway_alias_accepted');
            } else {
                PaymentMetrics::warn('webhook.payment_id_mismatch', [
                    'trade_no' => $tradeNo,
                    'order_payment_id' => $order->payment_id,
                    'callback_payment_id' => $callbackPaymentId,
                ]);
                if ($bindMode === 'enforce') {
                    return false;
                }
            }
        }

        $orderService = new OrderService($order);
        // payment_id 在 paid() 持有订单行锁后再次复核，关闭上述预读与状态翻转间的 TOCTOU。
        if (!$orderService->paid($callbackNo, (int) $payment->id, $bindMode === 'enforce')) {
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
    private function handleLatePayment(Order $order, string $callbackNo, Payment $payment)
    {
        $status = (int) $order->status;

        // 已在开通中或已完成：正常的重复回调，幂等确认。
        if (in_array($status, [Order::STATUS_PROCESSING, Order::STATUS_COMPLETED], true)) {
            if (
                PaymentGatewayBinding::equivalent(
                    $order->payment_id === null ? null : (int) $order->payment_id,
                    (int) $payment->id
                )
                && hash_equals((string) ($order->callback_no ?? ''), $callbackNo)
            ) {
                return true;
            }
            return $this->flagLatePaymentForReview($order, $callbackNo, 'duplicate_callback_conflict');
        }

        // 非「已取消」的其它终态（如已折抵）：记录后 ACK，不自动处理。
        if ($status !== Order::STATUS_CANCELLED) {
            return $this->flagLatePaymentForReview($order, $callbackNo, 'unexpected_status:' . $status);
        }

        // 已取消订单收到真实支付。重开是敏感操作，这里**强制**校验回调网关与订单
        // checkout 绑定的网关同源（不依赖全局 warn/off 开关）。同一插件的两条配置可能
        // 属于不同商户/密钥，所以不能只比插件类；但共用同一套凭证的多通道配置必须放行，
        // 否则「切过按钮的用户」迟到支付会被一律转人工——这正是 2026-07-14 事故的形态。
        $boundPayment = $order->payment_id !== null ? Payment::find($order->payment_id) : null;
        if (!$boundPayment || !PaymentGatewayBinding::sameMerchant($boundPayment, $payment)) {
            return $this->flagLatePaymentForReview($order, $callbackNo, 'gateway_mismatch');
        }

        $orderService = new OrderService($order);
        $result = $orderService->reopenFromCancelled($callbackNo, (int) $payment->id);

        switch ($result) {
            case 'reopened':
                Log::info('[late-payment] 已取消订单收到真实支付，已在安全条件下重新开通', [
                    'trade_no' => (string) $order->trade_no,
                    'order_id' => (int) $order->id,
                    'user_id' => (int) $order->user_id,
                    'callback_no' => (string) $callbackNo,
                ]);
                PaymentMetrics::inc('order.late_paid.reopened');
                $this->notifyAdminsLatePayment($orderService->order, $callbackNo, 'reopened', true);
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
        $this->notifyAdminsLatePayment($order, $callbackNo, $reason);
        return true;
    }

    /**
     * 迟到支付事件推送 Telegram 管理员。sendMessageWithAdmin 只查库并派发队列任务，
     * 真正的 HTTP 发送在队列 worker 中完成；本方法整体 try/catch，任何失败（含 Bot
     * 未配置、队列不可用）都不影响向网关 ACK。
     */
    private function notifyAdminsLatePayment(Order $order, $callbackNo, string $reason, bool $reopened = false): void
    {
        try {
            $amount = number_format(((int) $order->total_amount) / 100, 2);
            $message = ($reopened
                ? "✅ 已取消订单收到真实支付，已自动恢复开通\n"
                : "⚠️ 迟到支付需人工处理\n")
                . "订单号：{$order->trade_no}\n"
                . "用户ID：{$order->user_id}\n"
                . "金额：¥{$amount}\n"
                . "回调流水：{$callbackNo}"
                . ($reopened ? '' : "\n原因：{$reason}");
            (new TelegramService())->sendMessageWithAdmin($message);
        } catch (\Throwable $e) {
            try {
                Log::warning('[late-payment] Telegram 通知发送失败', ['message' => $e->getMessage()]);
            } catch (\Throwable $ignored) {
            }
        }
    }
}
