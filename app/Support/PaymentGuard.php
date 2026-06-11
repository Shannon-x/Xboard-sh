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
 *   warn    → 默认。校验金额，欠额仅记 PaymentMetrics + Log，不拒收（灰度观察）
 *   enforce → 欠额直接判定失败（由各插件按自身惯例 return false / throw 拒收）
 *
 * ⚠️ 默认 warn 是刻意选择：金额单位/币种在不同网关下可能有歧义（元 vs 分、法币 vs 加密），
 *    先观察 PaymentMetrics `webhook.amount_mismatch` 计数确认无误报，再切 enforce。
 */
class PaymentGuard
{
    /** 当前金额校验模式（off / warn / enforce）。 */
    public static function amountMode(): string
    {
        return FeatureFlag::mode('payment_amount_check');
    }

    /**
     * 校验网关回传实付额是否 >= 订单应付额（均以「分」为单位比较）。
     *
     * 设计为「欠额检测」而非严格相等：合法的溢付 / 加密币四舍五入向上不应误杀，
     * 真正的攻击方向是「付得更少」。
     *
     * 边界处理（一律不在此拒收，交由上层 handle 处理）：
     *   - mode === off：直接放行
     *   - 缺 tradeNo / 缺金额：放行（无法判断，避免误杀）
     *   - 订单不存在：放行（404 由 PaymentController::handle 统一处理）
     *
     * @param string                  $gateway     网关标识，仅用于指标
     * @param string|null             $tradeNo     本地订单号
     * @param int|float|string|null   $actualMinor 网关实付额（分）
     * @param string                  $mode        off / warn / enforce
     * @return bool  true=放行；false=仅在 enforce 且确实欠额时
     */
    public static function ensureAmount(string $gateway, ?string $tradeNo, $actualMinor, string $mode): bool
    {
        if ($mode === 'off') {
            return true;
        }
        if ($tradeNo === null || $tradeNo === '' || $actualMinor === null || $actualMinor === '') {
            return true;
        }

        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            return true;
        }

        // 应付额 = total_amount + handling_amount。结账时实际向网关发起的就是这个合计
        // （OrderController::checkout：total_amount + handling_amount），网关回传的实付额也含手续费，
        // 因此用合计作为期望值：既不会把「足额含手续费」误判为欠额，也能拦住「只付 total、漏付手续费」。
        $expected = (int) $order->total_amount + (int) ($order->handling_amount ?? 0);
        $actual = (int) round((float) $actualMinor);

        if ($actual < $expected) {
            PaymentMetrics::warn('webhook.amount_mismatch', [
                'gateway' => $gateway,
                'out_trade_no' => $tradeNo,
                'expected' => $expected,
                'actual' => $actual,
            ]);
            if ($mode === 'enforce') {
                return false;
            }
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
        // 回调缺该字段或本地未配置：放行（无法判断，避免误杀）
        if ($actual === null || $actual === '' || $expected === null || $expected === '') {
            return true;
        }

        if (!hash_equals($expected, $actual)) {
            PaymentMetrics::warn('webhook.merchant_mismatch', [
                'gateway' => $gateway,
                'field' => $field,
            ]);
            if ($mode === 'enforce') {
                return false;
            }
        }

        return true;
    }
}
