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
