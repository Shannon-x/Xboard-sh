<?php

namespace Plugin\Btcpay;

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
                $methods['BTCPay'] = [
                    'name' => $this->getConfig('display_name', 'BTCPay'),
                    'icon' => $this->getConfig('icon', '₿'),
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
            'btcpay_url' => [
                'label' => 'API接口所在网址',
                'type' => 'string',
                'required' => true,
                'description' => '包含最后的斜杠，例如：https://your-btcpay.com/'
            ],
            'btcpay_storeId' => [
                'label' => 'Store ID',
                'type' => 'string',
                'required' => true,
                'description' => 'BTCPay商店标识符'
            ],
            'btcpay_api_key' => [
                'label' => 'API KEY',
                'type' => 'string',
                'required' => true,
                'description' => '个人设置中的API KEY(非商店设置中的)'
            ],
            'btcpay_webhook_key' => [
                'label' => 'WEBHOOK KEY',
                'type' => 'string',
                'required' => true,
                'description' => 'Webhook通知密钥'
            ],
        ];
    }

    public function pay($order): array
    {
        $params = [
            'jsonResponse' => true,
            'amount' => sprintf('%.2f', $order['total_amount'] / 100),
            'currency' => 'CNY',
            'metadata' => [
                'orderId' => $order['trade_no']
            ]
        ];

        $params_string = @json_encode($params);
        $ret_raw = $this->curlPost($this->getConfig('btcpay_url') . 'api/v1/stores/' . $this->getConfig('btcpay_storeId') . '/invoices', $params_string);
        $ret = @json_decode($ret_raw, true);

        if (empty($ret['checkoutLink'])) {
            throw new ApiException("error!");
        }
        
        return [
            'type' => 1,
            'data' => $ret['checkoutLink'],
        ];
    }

    public function notify($params): array|bool
    {
        $payload = trim(request()->getContent());
        $headers = getallheaders();
        $headerName = 'Btcpay-Sig';
        $signraturHeader = isset($headers[$headerName]) ? $headers[$headerName] : '';
        $json_param = json_decode($payload, true);

        $computedSignature = "sha256=" . \hash_hmac('sha256', $payload, $this->getConfig('btcpay_webhook_key'));

        if (!$this->hashEqual($signraturHeader, $computedSignature)) {
            throw new ApiException('HMAC signature does not match', 400);
        }

        $context = stream_context_create(array(
            'http' => array(
                'method' => 'GET',
                'header' => "Authorization:" . "token " . $this->getConfig('btcpay_api_key') . "\r\n"
            )
        ));

        $invoiceRaw = @file_get_contents($this->getConfig('btcpay_url') . 'api/v1/stores/' . $this->getConfig('btcpay_storeId') . '/invoices/' . $json_param['invoiceId'], false, $context);
        $invoiceDetail = json_decode((string) $invoiceRaw, true);

        // 回查失败（网络/鉴权）时不开通订单：抛错让 BTCPay 重投，真正结算的 webhook 会再来。
        if (!is_array($invoiceDetail)) {
            throw new ApiException('Unable to fetch BTCPay invoice', 400);
        }

        $out_trade_no = $invoiceDetail['metadata']["orderId"] ?? null;
        $pay_trade_no = $json_param['invoiceId'] ?? null;

        // BTCPay 对 InvoiceCreated / ReceivedPayment / Processing / Expired / Invalid / Settled
        // 全部投递合法签名 webhook。只有发票真正结清才可开通：
        //   Greenfield 状态 = Settled；旧版/部分配置 = Complete / Confirmed / Paid。
        // 未结清的事件验签通过但不开通——返回 acknowledge 让 PaymentController 回 200，避免重投风暴。
        $status = strtolower((string) ($invoiceDetail['status'] ?? ''));
        $settled = in_array($status, ['settled', 'complete', 'confirmed', 'paid'], true);
        if (!$settled || !$out_trade_no) {
            return [
                'acknowledge' => true,
                'custom_result' => 'success',
            ];
        }

        // 金额绑定（受 payment_amount_check 控制，默认 warn 仅观测）。
        // pay() 中以 CNY 设定 amount，发票 amount 即为元。
        $mode = PaymentGuard::amountMode();
        if ($mode !== 'off') {
            $amount = $invoiceDetail['amount'] ?? null;
            $actualMinor = $amount !== null ? (int) round(((float) $amount) * 100) : null;
            if (!PaymentGuard::ensureAmount('BTCPay', $out_trade_no, $actualMinor, $mode)) {
                throw new ApiException('Payment amount mismatch', 400);
            }
        }

        return [
            'trade_no' => $out_trade_no,
            'callback_no' => $pay_trade_no
        ];
    }

    private function curlPost($url, $params = false)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            array('Authorization:' . 'token ' . $this->getConfig('btcpay_api_key'), 'Content-Type: application/json')
        );
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    private function hashEqual($str1, $str2)
    {
        if (function_exists('hash_equals')) {
            return \hash_equals($str1, $str2);
        }

        if (strlen($str1) != strlen($str2)) {
            return false;
        } else {
            $res = $str1 ^ $str2;
            $ret = 0;

            for ($i = strlen($res) - 1; $i >= 0; $i--) {
                $ret |= ord($res[$i]);
            }
            return !$ret;
        }
    }
} 