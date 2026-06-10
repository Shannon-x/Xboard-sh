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
            // 已验签但非「可结算」事件（如 Coinbase charge:created、BTCPay 未结清发票）：
            // 网关插件返回 ['acknowledge' => true]，此处回 200 让网关停止重投，但不开通订单。
            if (is_array($verify) && !empty($verify['acknowledge'])) {
                return $verify['custom_result'] ?? 'success';
            }
            HookManager::call('payment.notify.verified', $verify);
            if (!$this->handle($verify['trade_no'], $verify['callback_no'], $uuid)) {
                return $this->fail([400, 'handle error']);
            }
            return (isset($verify['custom_result']) ? $verify['custom_result'] : 'success');
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, 'fail']);
        }
    }

    private function handle($tradeNo, $callbackNo, $uuid = null)
    {
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            return $this->fail([400202, 'order is not found']);
        }
        if ($order->status !== Order::STATUS_PENDING)
            return true;

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
}
