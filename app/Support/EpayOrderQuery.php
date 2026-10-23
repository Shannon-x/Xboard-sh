<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

/**
 * 向易支付网关查询一笔交易（只读）。走商户接口 api.php?act=order，按网关交易号（回调里的
 * trade_no）查。付款事后核验用，见 App\Services\PaymentVerificationService。
 *
 * 放在核心而不是插件里：生产上 plugins/ 是宿主机挂载，插件目录不一定随镜像更新；核验逻辑
 * 不能依赖插件文件的版本。EPay 插件的 queryOrder() 只是转调这里。
 *
 * 调用方每次传入当前的支付配置，这里不缓存密钥：管理员换了密钥，下一次查询就用新的。
 */
final class EpayOrderQuery
{
    public const FOUND = 'found';
    /** 网关明确回答没有这笔交易 */
    public const NOT_FOUND = 'not_found';
    /** 网关拒绝了查询本身（商户号或密钥不对），是配置问题，不能当成「没有这笔」 */
    public const AUTH_ERROR = 'auth_error';
    /** 网络错误、非 2xx、返回不是 JSON 或无法识别的回答 */
    public const ERROR = 'error';

    /**
     * @param array $config 支付配置（url / pid / key）
     * @return array{outcome: string, status: ?int, money: ?int, type: ?string, out_trade_no: ?string, raw_code: mixed, msg: string}
     *         money 为分；msg 已去掉 URL 查询串与密钥，可以直接落库或推送。
     */
    public static function query(array $config, string $gatewayTradeNo): array
    {
        $result = [
            'outcome' => self::ERROR, 'status' => null, 'money' => null, 'type' => null,
            'out_trade_no' => null, 'raw_code' => null, 'msg' => '',
        ];
        $url = rtrim((string) ($config['url'] ?? ''), '/');
        $pid = (string) ($config['pid'] ?? '');
        $key = (string) ($config['key'] ?? '');
        if ($url === '' || $pid === '' || $key === '' || $gatewayTradeNo === '') {
            $result['msg'] = '支付配置或网关交易号缺失';
            return $result;
        }

        try {
            $response = Http::timeout(10)->acceptJson()->get($url . '/api.php', [
                'act' => 'order',
                'pid' => $pid,
                'key' => $key,
                'trade_no' => $gatewayTradeNo,
            ]);
        } catch (\Throwable $e) {
            $result['msg'] = self::scrub($e->getMessage(), $key);
            return $result;
        }

        if (!$response->successful()) {
            $result['msg'] = 'HTTP ' . $response->status();
            return $result;
        }
        $json = $response->json();
        if (!is_array($json)) {
            $result['msg'] = '网关返回的不是 JSON';
            return $result;
        }

        $result['raw_code'] = $json['code'] ?? null;
        $result['msg'] = self::scrub((string) ($json['msg'] ?? ''), $key);

        if ((string) ($json['code'] ?? '') === '1') {
            $money = $json['money'] ?? null;
            $result['outcome'] = self::FOUND;
            $result['status'] = isset($json['status']) && is_numeric($json['status']) ? (int) $json['status'] : null;
            $result['money'] = is_int($money) || is_float($money) || is_string($money)
                ? PaymentGuard::decimalToMinor((string) $money)
                : null;
            $result['type'] = isset($json['type']) ? mb_substr((string) $json['type'], 0, 32) : null;
            $result['out_trade_no'] = isset($json['out_trade_no']) ? (string) $json['out_trade_no'] : null;
            return $result;
        }

        $msg = $result['msg'];
        if (str_contains($msg, '不存在') && (str_contains($msg, '订单') || str_contains($msg, '单号'))) {
            $result['outcome'] = self::NOT_FOUND;
        } elseif (preg_match('/key|密钥|签名|校验失败|商户|pid/iu', $msg)) {
            $result['outcome'] = self::AUTH_ERROR;
        }
        return $result;
    }

    /** 异常文本里常带完整请求 URL（含商户密钥），落库 / 推送前去掉查询串和密钥本身。 */
    private static function scrub(string $message, string $key): string
    {
        $message = preg_replace('/\?\S*/', '?…', $message) ?? '';
        if ($key !== '') {
            $message = str_replace($key, '***', $message);
        }
        return mb_substr(trim($message), 0, 200);
    }
}
