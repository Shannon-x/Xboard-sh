<?php

namespace Plugin\Mgate;

use App\Services\Plugin\AbstractPlugin;
use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Support\PaymentGuard;
use Curl\Curl;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function ($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['MGate'] = [
                    'name' => $this->getConfig('display_name', 'MGate'),
                    'icon' => $this->getConfig('icon', '🏛️'),
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
            'mgate_url' => [
                'label' => 'API地址',
                'type' => 'string',
                'required' => true,
                'description' => 'MGate支付网关API地址'
            ],
            'mgate_app_id' => [
                'label' => 'APP ID',
                'type' => 'string',
                'required' => true,
                'description' => 'MGate应用标识符'
            ],
            'mgate_app_secret' => [
                'label' => 'App Secret',
                'type' => 'string',
                'required' => true,
                'description' => 'MGate应用密钥'
            ],
            'mgate_source_currency' => [
                'label' => '源货币',
                'type' => 'string',
                'description' => '默认CNY，源货币类型'
            ]
        ];
    }

    public function pay($order): array
    {
        $params = [
            'out_trade_no' => $order['trade_no'],
            'total_amount' => $order['total_amount'],
            'notify_url' => $order['notify_url'],
            'return_url' => $order['return_url']
        ];

        if ($this->getConfig('mgate_source_currency')) {
            $params['source_currency'] = $this->getConfig('mgate_source_currency');
        }

        $params['app_id'] = $this->getConfig('mgate_app_id');
        ksort($params);
        $str = http_build_query($params) . $this->getConfig('mgate_app_secret');
        $params['sign'] = md5($str);

        $curl = new Curl();
        $curl->setUserAgent('MGate');
        $curl->setOpt(CURLOPT_SSL_VERIFYPEER, 0);
        $curl->post($this->getConfig('mgate_url') . '/v1/gateway/fetch', http_build_query($params));
        $result = $curl->response;

        if (!$result) {
            throw new ApiException('网络异常');
        }

        if ($curl->error) {
            if (isset($result->errors)) {
                $errors = (array) $result->errors;
                throw new ApiException($errors[array_keys($errors)[0]][0]);
            }
            if (isset($result->message)) {
                throw new ApiException($result->message);
            }
            throw new ApiException('未知错误');
        }

        $curl->close();

        if (!isset($result->data->trade_no)) {
            throw new ApiException('接口请求失败');
        }

        return [
            'type' => 1,
            'data' => $result->data->pay_url
        ];
    }

    public function notify($params): array|bool
    {
        $sign = $params['sign'];
        unset($params['sign']);
        ksort($params);
        reset($params);
        $str = http_build_query($params) . $this->getConfig('mgate_app_secret');

        if ($sign !== md5($str)) {
            return false;
        }

        // 验签只证明回调来自 MGate；绑定金额与 app_id，防止低额支付 / 跨商户回调
        // 开通高价订单（受 payment_amount_check 控制，默认 warn 仅观测）。
        $mode = PaymentGuard::amountMode();
        if ($mode !== 'off') {
            if (!PaymentGuard::ensureMerchant(
                'MGate',
                'app_id',
                isset($params['app_id']) ? (string) $params['app_id'] : null,
                (string) $this->getConfig('mgate_app_id'),
                $mode
            )) {
                return false;
            }
            // pay() 提交的 total_amount 单位为分，回调原样回传，按分比较
            $total = $params['total_amount'] ?? null;
            $actualMinor = $total !== null ? (int) round((float) $total) : null;
            if (!PaymentGuard::ensureAmount('MGate', $params['out_trade_no'] ?? null, $actualMinor, $mode)) {
                return false;
            }
        }

        return [
            'trade_no' => $params['out_trade_no'],
            'callback_no' => $params['trade_no']
        ];
    }
}