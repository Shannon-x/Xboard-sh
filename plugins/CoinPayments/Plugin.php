<?php

namespace Plugin\CoinPayments;

use App\Services\Plugin\AbstractPlugin;
use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Support\PaymentGuard;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['CoinPayments'] = [
                    'name' => $this->getConfig('display_name', 'CoinPayments'),
                    'icon' => $this->getConfig('icon', '💰'),
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
            'coinpayments_merchant_id' => [
                'label' => 'Merchant ID',
                'type' => 'string',
                'required' => true,
                'description' => '商户 ID，填写您在 Account Settings 中得到的 ID'
            ],
            'coinpayments_ipn_secret' => [
                'label' => 'IPN Secret',
                'type' => 'string',
                'required' => true,
                'description' => '通知密钥，填写您在 Merchant Settings 中自行设置的值'
            ],
            'coinpayments_currency' => [
                'label' => '货币代码',
                'type' => 'string',
                'required' => true,
                'description' => '填写您的货币代码（大写），建议与 Merchant Settings 中的值相同'
            ]
        ];
    }

    public function pay($order): array
    {
        $parseUrl = parse_url($order['return_url']);
        $port = isset($parseUrl['port']) ? ":{$parseUrl['port']}" : '';
        $successUrl = "{$parseUrl['scheme']}://{$parseUrl['host']}{$port}";

        $params = [
            'cmd' => '_pay_simple',
            'reset' => 1,
            'merchant' => $this->getConfig('coinpayments_merchant_id'),
            'item_name' => $order['trade_no'],
            'item_number' => $order['trade_no'],
            'want_shipping' => 0,
            'currency' => $this->getConfig('coinpayments_currency'),
            'amountf' => sprintf('%.2f', $order['total_amount'] / 100),
            'success_url' => $successUrl,
            'cancel_url' => $order['return_url'],
            'ipn_url' => $order['notify_url']
        ];

        $params_string = http_build_query($params);

        return [
            'type' => 1,
            'data' => 'https://www.coinpayments.net/index.php?' . $params_string
        ];
    }

    public function notify($params): array|string
    {
        if (!isset($params['merchant']) || $params['merchant'] != trim($this->getConfig('coinpayments_merchant_id'))) {
            throw new ApiException('No or incorrect Merchant ID passed');
        }

        $headers = getallheaders();

        ksort($params);
        reset($params);
        $request = stripslashes(http_build_query($params));

        $headerName = 'Hmac';
        $signHeader = isset($headers[$headerName]) ? $headers[$headerName] : '';

        $hmac = hash_hmac("sha512", $request, trim($this->getConfig('coinpayments_ipn_secret')));

        if (!hash_equals($hmac, $signHeader)) {
            throw new ApiException('HMAC signature does not match', 400);
        }

        $status = $params['status'];
        if ($status >= 100 || $status == 2) {
            // 金额绑定（受 payment_amount_check 控制，默认 warn 仅观测）。
            // amount1 = 以商户结算币种计的金额；pay() 中以 coinpayments_currency 提交 amountf。
            $mode = PaymentGuard::amountMode();
            if ($mode !== 'off') {
                $amount1 = $params['amount1'] ?? null;
                $actualMinor = $amount1 !== null ? (int) round(((float) $amount1) * 100) : null;
                if (!PaymentGuard::ensureAmount('CoinPayments', $params['item_number'] ?? null, $actualMinor, $mode)) {
                    throw new ApiException('Payment amount mismatch', 400);
                }
            }

            return [
                'trade_no' => $params['item_number'],
                'callback_no' => $params['txn_id'],
                'custom_result' => 'IPN OK'
            ];
        } else if ($status < 0) {
            throw new ApiException('Payment Timed Out or Error');
        } else {
            // pending（status 0..99，尚未结算）：已验签但不开单。返回 acknowledge 让
            // PaymentController 回 200。绝不能返回裸字符串——controller 会把它当成
            // $verify['trade_no'] 数组解引用，PHP8 下抛 TypeError（属 \Error，
            // 旧 catch(\Exception) 兜不住）→ 500，且 CoinPayments 会无限重投 pending IPN。
            return [
                'acknowledge' => true,
                'custom_result' => 'IPN OK',
            ];
        }
    }
} 