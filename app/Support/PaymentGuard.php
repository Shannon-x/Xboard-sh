<?php

namespace App\Support;

use App\Models\Order;

/**
 * 支付回调「金额绑定」统一校验。
 *
 * 背景：各网关 notify() 验签只能证明回调来自网关，无法证明「实付额 == 订单应付额」。
 * 缺了这层校验，攻击者可对一笔高价订单的 trade_no 真实支付极小额，网关签发合法回调，
 * 订单即被全额开通（参见 EPay 已落地的 verifyEpayPayload）。
 *
 * 本类把这套逻辑抽出来给其余网关复用，受 config/feature_flags.php 的
 * `payment_amount_check` 三档 flag 控制：
 *   off     → 完全跳过（仅验签，旧行为）
 *   warn    → 校验异常仅记 PaymentMetrics + Log，不拒收（仅用于临时兼容）
 *   enforce → 默认。缺字段、格式异常或与订单不一致均拒收
 */
class PaymentGuard
{
    /** 当前金额校验模式（off / warn / enforce）。 */
    public static function amountMode(): string
    {
        return FeatureFlag::mode('payment_amount_check');
    }

    /**
     * 校验网关回传实付额是否与订单应付额严格相等（均以「分」为单位比较）。
     *
     * mode=warn 只用于紧急回滚；默认 enforce 下缺订单号、缺金额、异常金额和
     * 非正订单应付额全部 fail closed，不能把「无法判断」当成支付成功。
     *
     * @param string                  $gateway     网关标识，仅用于指标
     * @param string|null             $tradeNo     本地订单号
     * @param int|float|string|null   $actualMinor 网关实付额（分）
     * @param string                  $mode        off / warn / enforce
     * @return bool  true=放行；false=仅在 enforce 且校验异常时
     */
    public static function ensureAmount(string $gateway, ?string $tradeNo, $actualMinor, string $mode): bool
    {
        if ($mode === 'off') {
            return true;
        }
        if ($tradeNo === null || $tradeNo === '' || !is_int($actualMinor)) {
            return self::mismatch('webhook.amount_invalid', [
                'gateway' => $gateway,
                'out_trade_no' => $tradeNo,
            ], $mode);
        }

        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            return self::mismatch('webhook.order_missing', [
                'gateway' => $gateway,
                'out_trade_no' => $tradeNo,
            ], $mode);
        }

        // 应付额 = total_amount + handling_amount。结账时实际向网关发起的就是这个合计
        // （OrderController::checkout：total_amount + handling_amount），网关回传的实付额也含手续费，
        // 因此用合计作为期望值：既不会把「足额含手续费」误判为欠额，也能拦住「只付 total、漏付手续费」。
        $expected = (int) $order->total_amount + (int) ($order->handling_amount ?? 0);
        $actual = $actualMinor;

        if ($expected <= 0 || $actual <= 0 || $actual !== $expected) {
            return self::mismatch('webhook.amount_mismatch', [
                'gateway' => $gateway,
                'out_trade_no' => $tradeNo,
                'expected' => $expected,
                'actual' => $actual,
            ], $mode);
        }

        return true;
    }

    /**
     * 校验回调里的商户标识（app_id / merchant 等）是否与本网关配置一致，
     * 防止「用另一个合法商户账号的成功回调」翻转本站订单（跨商户攻击）。
     *
     * @return bool  true=放行；false=仅在 enforce 且确实不一致时
     */
    public static function ensureMerchant(string $gateway, string $field, ?string $actual, ?string $expected, string $mode): bool
    {
        if ($mode === 'off') {
            return true;
        }
        // 缺失商户标识同样无法证明回调属于当前支付配置，默认必须拒收。
        if ($actual === null || $actual === '' || $expected === null || $expected === '') {
            return self::mismatch('webhook.merchant_missing', [
                'gateway' => $gateway,
                'field' => $field,
            ], $mode);
        }

        if (!hash_equals($expected, $actual)) {
            return self::mismatch('webhook.merchant_mismatch', [
                'gateway' => $gateway,
                'field' => $field,
            ], $mode);
        }

        return true;
    }

    /** 严格解析十进制主货币单位（例如 "12.34" 元）为整数分。 */
    public static function decimalToMinor($value): ?int
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $value = (string) $value;
        if (!preg_match('/^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/D', $value, $matches)) {
            return null;
        }

        $major = $matches[1];
        $fraction = str_pad($matches[2] ?? '', 2, '0');
        if (strlen($major) > 16) {
            return null;
        }

        $minor = ((int) $major * 100) + (int) $fraction;
        return $minor > 0 ? $minor : null;
    }

    /** 严格解析已经以分表示的正整数金额。 */
    public static function integerMinor($value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || !preg_match('/^[1-9]\d*$/D', $value) || strlen($value) > 18) {
            return null;
        }

        $minor = (int) $value;
        return $minor > 0 ? $minor : null;
    }

    public static function ensureCurrency(
        string $gateway,
        ?string $actual,
        ?string $expected,
        string $mode
    ): bool {
        if ($mode === 'off') {
            return true;
        }
        if ($actual === null || $actual === '' || $expected === null || $expected === '') {
            return self::mismatch('webhook.currency_missing', ['gateway' => $gateway], $mode);
        }
        if (strcasecmp($actual, $expected) !== 0) {
            return self::mismatch('webhook.currency_mismatch', [
                'gateway' => $gateway,
                'expected' => strtoupper($expected),
                'actual' => strtoupper($actual),
            ], $mode);
        }

        return true;
    }

    private static function mismatch(string $metric, array $context, string $mode): bool
    {
        PaymentMetrics::warn($metric, $context);
        return $mode !== 'enforce';
    }
}
