<?php

namespace Plugin\AlipayF2f;

use App\Services\Plugin\AbstractPlugin;
use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Support\PaymentGuard;
use Illuminate\Support\Facades\Log;
use Plugin\AlipayF2f\library\AlipayF2F;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function ($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['AlipayF2F'] = [
                    'name' => $this->getConfig('display_name', '支付宝当面付'),
                    'icon' => $this->getConfig('icon', '💙'),
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
            'app_id' => [
                'label' => '支付宝APPID',
                'type' => 'string',
                'required' => true,
                'description' => '支付宝开放平台应用的APPID'
            ],
            'private_key' => [
                'label' => '支付宝私钥',
                'type' => 'text',
                'required' => true,
                'description' => '应用私钥，用于签名'
            ],
            'public_key' => [
                'label' => '支付宝公钥',
                'type' => 'text',
                'required' => true,
                'description' => '支付宝公钥，用于验签'
            ],
            'product_name' => [
                'label' => '自定义商品名称',
                'type' => 'string',
                'description' => '将会体现在支付宝账单中'
            ]
        ];
    }

    public function pay($order): array
    {
        try {
            $gateway = new AlipayF2F();
            $gateway->setMethod('alipay.trade.precreate');
            $gateway->setAppId($this->getConfig('app_id'));
            $gateway->setPrivateKey($this->getConfig('private_key'));
            $gateway->setAlipayPublicKey($this->getConfig('public_key'));
            $gateway->setNotifyUrl($order['notify_url']);
            $gateway->setBizContent([
                'subject' => $this->getConfig('product_name') ?? (admin_setting('app_name', 'XBoard') . ' - 订阅'),
                'out_trade_no' => $order['trade_no'],
                'total_amount' => $order['total_amount'] / 100
            ]);
            $gateway->send();
            return [
                'type' => 0,
                'data' => $gateway->getQrCodeUrl()
            ];
        } catch (\Exception $e) {
            Log::error($e);
            throw new ApiException($e->getMessage());
        }
    }

    public function notify($params): array|bool
    {
        if ($params['trade_status'] !== 'TRADE_SUCCESS')
            return false;

        $gateway = new AlipayF2F();
        $gateway->setAppId($this->getConfig('app_id'));
        $gateway->setPrivateKey($this->getConfig('private_key'));
        $gateway->setAlipayPublicKey($this->getConfig('public_key'));

        try {
            if ($gateway->verify($params)) {
                // 验签只证明回调来自支付宝；还需绑定金额与商户，防止用 0.01 当面付
                // 或另一商户账号的合法 TRADE_SUCCESS 开通高价订单（受 payment_amount_check 控制）。
                $mode = PaymentGuard::amountMode();
                if ($mode !== 'off') {
                    // app_id 绑定
                    if (!PaymentGuard::ensureMerchant(
                        'AlipayF2F',
                        'app_id',
                        isset($params['app_id']) ? (string) $params['app_id'] : null,
                        (string) $this->getConfig('app_id'),
                        $mode
                    )) {
                        return false;
                    }
                    // 金额绑定：当面付回调 total_amount 单位为元
                    $total = $params['total_amount'] ?? null;
                    $actualMinor = $total !== null ? (int) round(((float) $total) * 100) : null;
                    if (!PaymentGuard::ensureAmount('AlipayF2F', $params['out_trade_no'] ?? null, $actualMinor, $mode)) {
                        return false;
                    }
                }

                return [
                    'trade_no' => $params['out_trade_no'],
                    'callback_no' => $params['trade_no']
                ];
            } else {
                return false;
            }
        } catch (\Exception $e) {
            return false;
        }
    }
}