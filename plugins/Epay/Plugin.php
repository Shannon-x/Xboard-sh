<?php

namespace Plugin\Epay;

use App\Models\Order;
use App\Services\Plugin\AbstractPlugin;
use App\Contracts\PaymentInterface;
use App\Support\FeatureFlag;
use App\Support\PaymentMetrics;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function ($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['EPay'] = [
                    'name' => $this->getConfig('display_name', '易支付'),
                    'icon' => $this->getConfig('icon', '💳'),
                    'plugin_code' => $this->getPluginCode(),
                    'type' => 'plugin'
                ];
            }
            return $methods;
        });
    }

    public function form(): array
    {
        return [
            'url' => [
                'label' => '支付网关地址',
                'type' => 'string',
                'required' => true,
                'description' => '请填写完整的支付网关地址，包括协议（http或https）'
            ],
            'pid' => [
                'label' => '商户ID',
                'type' => 'string',
                'description' => '请填写商户ID',
                'required' => true
            ],
            'key' => [
                'label' => '通信密钥',
                'type' => 'string',
                'required' => true,
                'description' => '请填写通信密钥'
            ],
            'type' => [
                'label' => '支付类型',
                'type' => 'string',
                'description' => '支付类型，如: alipay, wxpay, qqpay 等，可自定义'
            ],
        ];
    }

    public function pay($order): array
    {
        $params = [
            'money' => $order['total_amount'] / 100,
            'name' => $order['trade_no'],
            'notify_url' => $order['notify_url'],
            'return_url' => $order['return_url'],
            'out_trade_no' => $order['trade_no'],
            'pid' => $this->getConfig('pid')
        ];

        if ($paymentType = $this->getConfig('type')) {
            $params['type'] = $paymentType;
        }

        ksort($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $this->getConfig('key');
        $params['sign'] = md5($str);
        $params['sign_type'] = 'MD5';

        return [
            'type' => 1,
            'data' => $this->getConfig('url') . '/submit.php?' . http_build_query($params)
        ];
    }

    public function notify($params): array|bool
    {
        $sign = (string) ($params['sign'] ?? '');
        unset($params['sign'], $params['sign_type']);
        ksort($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $this->getConfig('key');

        if (!hash_equals(md5($str), $sign)) {
            PaymentMetrics::warn('webhook.sign_invalid', [
                'gateway' => 'EPay',
                'out_trade_no' => $params['out_trade_no'] ?? null,
            ]);
            return false;
        }

        $mode = FeatureFlag::mode('payment_amount_check');
        if ($mode !== 'off') {
            $verdict = $this->verifyEpayPayload($params, $mode);
            if ($verdict === false) {
                return false;
            }
        }

        return [
            'trade_no' => $params['out_trade_no'],
            'callback_no' => $params['trade_no']
        ];
    }

    /**
     * 校验 EPay 回调的 trade_status / 金额是否与本地订单一致。
     *
     * 三级 flag：
     *   off     → 跳过校验（旧行为，默认）
     *   warn    → 仅记录指标与日志，不拒收（用于灰度观察）
     *   enforce → 任意一项不一致即拒收
     */
    private function verifyEpayPayload(array $params, string $mode): bool
    {
        $tradeNo = $params['out_trade_no'] ?? null;
        $tradeStatus = $params['trade_status'] ?? null;
        $money = $params['money'] ?? null;

        if ($tradeStatus !== null && $tradeStatus !== 'TRADE_SUCCESS') {
            PaymentMetrics::warn('webhook.trade_status_invalid', [
                'gateway' => 'EPay',
                'out_trade_no' => $tradeNo,
                'trade_status' => $tradeStatus,
            ]);
            if ($mode === 'enforce') {
                return false;
            }
        }

        if ($tradeNo === null || $money === null) {
            return true;
        }

        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            // 订单不存在不在这里拒收（V1\PaymentController 的 handle 会处理 404 路径）
            return true;
        }

        // EPay 的 money 单位为元；本地 total_amount/handling_amount 单位为分。
        // 应付额 = total_amount + handling_amount：checkout 向网关发起的就是这个合计
        // （OrderController::checkout），回调 money 也含手续费。与 PaymentGuard 口径完全一致：
        //   - 用合计作为期望值，避免把「足额含手续费」误判为不一致；
        //   - 只拦「欠额」（实付 < 应付），不拦溢付——溢付不是攻击方向。
        // 原实现用 bccomp(...) !== 0 严格相等 + base-only，对任何有手续费的订单都会误报，
        // warn 下污染 webhook.amount_mismatch 指标、enforce 下直接拒收足额订单。
        $expectedMinor = (int) $order->total_amount + (int) ($order->handling_amount ?? 0);
        $actualMinor = (int) round((float) $money * 100);
        if ($actualMinor < $expectedMinor) {
            PaymentMetrics::warn('webhook.amount_mismatch', [
                'gateway' => 'EPay',
                'out_trade_no' => $tradeNo,
                'expected' => $expectedMinor,
                'actual' => $actualMinor,
            ]);
            if ($mode === 'enforce') {
                return false;
            }
        }

        return true;
    }
}