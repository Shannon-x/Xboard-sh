<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

/**
 * 向易支付网关查询一笔交易（只读），按网关交易号（回调里的 trade_no）查。
 * V1 走商户接口 api.php?act=order；支付配置 api_version=v2 时走 /api/pay/query（RSA 签名，见 EpayV2）。
 * 付款事后核验（App\Services\PaymentVerificationService）与回调入账前确认（confirm()）共用。
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

    /** confirm()：网关有这笔、已付、金额与商户单号都对得上 */
    public const CONFIRMED = 'confirmed';
    /** confirm()：网关明确说没有 / 未付 / 对不上 —— 调用方拒收，网关会重投 */
    public const REJECTED = 'rejected';
    /** confirm()：查询本身没成（网络、配置、网关接口故障）—— 调用方放行，由 payment:verify 事后复查 */
    public const UNAVAILABLE = 'unavailable';

    /**
     * @param array $config 支付配置（url / pid / key）
     * @return array{outcome: string, status: ?int, money: ?int, refund_money: ?int, type: ?string, out_trade_no: ?string, raw_code: mixed, msg: string}
     *         money / refund_money 为分（refund_money 只有 V2 部分退款时才有）；msg 已去掉 URL 查询串与密钥，可以直接落库或推送。
     */
    public static function query(array $config, string $gatewayTradeNo): array
    {
        $result = [
            'outcome' => self::ERROR, 'status' => null, 'money' => null, 'refund_money' => null, 'type' => null,
            'out_trade_no' => null, 'raw_code' => null, 'msg' => '',
        ];
        if (EpayV2::enabledFor($config)) {
            return self::queryV2($config, $gatewayTradeNo, $result);
        }

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

        $result['outcome'] = self::classifyRefusal($result['msg']);
        return $result;
    }

    /**
     * 回调入账前向网关确认这笔交易确实存在、已付、金额与商户单号一致。
     *
     * 签名有效只证明「拿着密钥的人」发了这条回调；密钥一旦泄露，伪造的回调签名同样有效，
     * 但网关上不会有这笔交易。入账前多问网关一句（实测 0.4~0.9 秒），伪造的回调就开不了单。
     *
     * @return array{verdict: string, reason: string}
     */
    public static function confirm(array $config, string $callbackNo, string $outTradeNo, ?int $paidMinor): array
    {
        $result = self::query($config, $callbackNo);
        switch ($result['outcome']) {
            case self::FOUND:
                if ($result['status'] !== 1) {
                    return ['verdict' => self::REJECTED, 'reason' => '网关状态 ' . ($result['status'] ?? '未知') . '，不是已支付'];
                }
                if ($paidMinor === null || $result['money'] !== $paidMinor) {
                    return ['verdict' => self::REJECTED, 'reason' => '网关金额与回调金额不一致'];
                }
                // 个别网关查单不回商户单号，这时只能靠状态 + 金额；回了就必须一致（防拿别的真实交易号顶替）
                if ($result['out_trade_no'] !== null && $result['out_trade_no'] !== $outTradeNo) {
                    return ['verdict' => self::REJECTED, 'reason' => '网关商户单号与回调不一致'];
                }
                return ['verdict' => self::CONFIRMED, 'reason' => ''];
            case self::NOT_FOUND:
                return ['verdict' => self::REJECTED, 'reason' => '网关查无此交易'];
            default:
                return ['verdict' => self::UNAVAILABLE, 'reason' => $result['msg'] !== '' ? $result['msg'] : '网关查询失败'];
        }
    }

    private static function queryV2(array $config, string $gatewayTradeNo, array $result): array
    {
        if ($gatewayTradeNo === '') {
            $result['msg'] = '网关交易号缺失';
            return $result;
        }
        try {
            $client = EpayV2::fromConfig($config);
        } catch (\InvalidArgumentException $e) {
            // 密钥填错是配置问题，按 AUTH_ERROR 走告警，不能当成「没有这笔」
            $result['outcome'] = self::AUTH_ERROR;
            $result['msg'] = $e->getMessage();
            return $result;
        }

        $response = $client->query($gatewayTradeNo);
        if ($response['error'] !== null) {
            $result['msg'] = $response['error'];
            if (str_contains($response['error'], '验签')) {
                $result['outcome'] = self::AUTH_ERROR;
            }
            return $result;
        }
        $result['raw_code'] = $response['code'];
        $result['msg'] = $response['msg'];
        if ($response['ok']) {
            $json = $response['data'];
            $money = $json['money'] ?? null;
            $result['outcome'] = self::FOUND;
            $result['status'] = isset($json['status']) && is_numeric($json['status']) ? (int) $json['status'] : null;
            $result['money'] = is_int($money) || is_float($money) || is_string($money)
                ? PaymentGuard::decimalToMinor((string) $money)
                : null;
            $result['type'] = isset($json['type']) ? mb_substr((string) $json['type'], 0, 32) : null;
            $result['out_trade_no'] = isset($json['out_trade_no']) ? (string) $json['out_trade_no'] : null;
            $refundMoney = $json['refundmoney'] ?? null;
            $result['refund_money'] = is_string($refundMoney) || is_int($refundMoney)
                ? PaymentGuard::decimalToMinor((string) $refundMoney)
                : null;
            return $result;
        }
        $result['outcome'] = self::classifyRefusal($result['msg']);
        return $result;
    }

    /** 网关明确拒绝（V1 code≠1、V2 code≠0）时按提示语区分「没有这笔」与「商户 / 签名不对」 */
    private static function classifyRefusal(string $msg): string
    {
        if (str_contains($msg, '不存在') && (str_contains($msg, '订单') || str_contains($msg, '单号'))) {
            return self::NOT_FOUND;
        }
        if (preg_match('/key|密钥|签名|校验失败|商户|pid/iu', $msg)) {
            return self::AUTH_ERROR;
        }
        return self::ERROR;
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
