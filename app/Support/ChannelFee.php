<?php

namespace App\Support;

use App\Models\Payment;

/**
 * 支付通道手续费（仅展示）。
 *
 * 有的网关（易支付收银台）会在我方订单金额之上自己再加一笔手续费，钱归通道、不归我们。
 * 这里只负责把费率告诉客户、在账单收据里列出来：
 *   - 不改 total_amount / handling_amount，不进收入统计，也不进佣金基数；
 *   - 与面板自带的 handling_fee_percent 无关 —— 那个会加进订单金额，算成我方收入。
 *
 * 费率存在支付方式 config 的 channel_fee_percent（后台支付方式编辑框里填，见 Epay 插件 form()），
 * 不参与商户身份指纹（PaymentGatewayBinding::NON_IDENTITY_KEYS）。
 */
final class ChannelFee
{
    public const CONFIG_KEY = 'channel_fee_percent';

    /** 某支付方式的通道费率（百分比，0~100）；没配 / 配错一律当 0 */
    public static function percent(?Payment $payment): float
    {
        if (!$payment) return 0.0;
        $config = $payment->config;
        if (!is_array($config)) return 0.0;
        $raw = $config[self::CONFIG_KEY] ?? null;
        if (is_string($raw)) $raw = trim(str_replace('%', '', $raw));
        if (!is_numeric($raw)) return 0.0;
        $pct = (float) $raw;
        if (!is_finite($pct) || $pct <= 0 || $pct > 100) return 0.0;
        return round($pct, 2);
    }

    /** 通道按金额（分）加收的手续费，四舍五入到分（与收银台一致：2640 × 3% = 79） */
    public static function amount(int $cents, float $percent): int
    {
        if ($cents <= 0 || $percent <= 0) return 0;
        return (int) round($cents * $percent / 100);
    }
}
