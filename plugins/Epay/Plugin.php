<?php

namespace Plugin\Epay;

use App\Services\Plugin\AbstractPlugin;
use App\Contracts\PaymentInterface;
use App\Support\FeatureFlag;
use App\Support\PaymentGuard;
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
        $signType = strtoupper((string) ($params['sign_type'] ?? ''));
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
            $verdict = $this->verifyEpayPayload($params, $signType, $mode);
            if ($verdict === false) {
                return false;
            }
        }

        if (empty($params['out_trade_no']) || empty($params['trade_no'])) {
            PaymentMetrics::warn('webhook.identifiers_missing', ['gateway' => 'EPay']);
            return false;
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
     *   warn    → 仅记录指标与日志，不拒收（仅用于紧急兼容）
     *   enforce → 任意一项不一致即拒收
     */
    private function verifyEpayPayload(array $params, string $signType, string $mode): bool
    {
        $tradeNo = $params['out_trade_no'] ?? null;
        $tradeStatus = $params['trade_status'] ?? null;
        $money = $params['money'] ?? null;

        if ($signType !== 'MD5') {
            PaymentMetrics::warn('webhook.sign_type_invalid', [
                'gateway' => 'EPay',
                'out_trade_no' => $tradeNo,
                'sign_type' => $signType,
            ]);
            if ($mode === 'enforce') {
                return false;
            }
        }

        if ($tradeStatus !== 'TRADE_SUCCESS') {
            PaymentMetrics::warn('webhook.trade_status_invalid', [
                'gateway' => 'EPay',
                'out_trade_no' => $tradeNo,
                'trade_status' => $tradeStatus,
            ]);
            if ($mode === 'enforce') {
                return false;
            }
        }

        if (!PaymentGuard::ensureMerchant(
            'EPay',
            'pid',
            isset($params['pid']) ? (string) $params['pid'] : null,
            (string) $this->getConfig('pid'),
            $mode
        )) {
            return false;
        }

        $actualMinor = PaymentGuard::decimalToMinor($money);
        if (!PaymentGuard::ensureAmount('EPay', $tradeNo, $actualMinor, $mode)) {
            return false;
        }

        return true;
    }
}
