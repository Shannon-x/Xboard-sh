<?php

namespace App\Support;

use App\Models\Payment;

/**
 * 支付回调「网关绑定」的同源判定。
 *
 * ── 为什么需要它 ────────────────────────────────────────────────────────────
 * 同一个易支付/聚合网关商户在面板里经常配成多条 v2_payment 记录 —— 典型是
 * 「支付宝支付」「微信支付」两个按钮，url / pid / key 完全相同，区别只是给用户看的
 * 名字，以及可选的收银台预选通道。用户在收银台来回切按钮时，订单绑定的 payment_id
 * 指向其中一条，而他真正付款的那个收款会话可能属于另一条：回调于是被「payment_id
 * 必须精确相等」的闸门拒收 —— 钱已到账却不开通，只能靠用户再点一次支付自愈。
 *
 * ── 为什么不能只比插件类 ────────────────────────────────────────────────────
 * PR #13 曾放宽为「同插件类即等价」，#15 又收回：同一个 EPay 插件下的两条配置完全
 * 可能属于两个不同商户、两套不同密钥。仅凭插件类相同就放行，等于把「用自己掌握的
 * 网关伪造一个验签通过的回调、去翻转别人的待支付订单」这个口子重新打开
 * （trade_no 可枚举）。
 *
 * ── 本类的折中 ──────────────────────────────────────────────────────────────
 * 比对「插件类 + 商户凭证指纹」。指纹取整份 config（仅剔除少数纯展示/通道预选字段），
 * 所以只有真正共用同一套密钥的配置才被视为同源；密钥、商户号或回调域名任一不同，
 * 指纹即不同，照旧拒收。判不出来时一律按「不同源」处理（失败闭合）。
 */
class PaymentGatewayBinding
{
    /**
     * 不参与商户身份判定的字段。
     *
     * 只允许放入「既不参与回调验签、也不改变应付金额」的纯展示 / 通道预选字段。
     * 任何可能影响密钥、商户号、回调地址或金额的键都必须留在指纹里 —— config 默认
     * 全量参与，未知的新字段一律计入，宁可判成不同源也不放行。
     */
    private const NON_IDENTITY_KEYS = [
        'type',       // EPay：收银台预选通道（alipay / wxpay），商户与密钥不变
        'trade_type', // BEpusdt：预选链（trc20 / erc20），商户与密钥不变
    ];

    /**
     * 回调网关是否可视为订单绑定网关的同源通道。
     *
     * @param int|null $orderPaymentId    订单 checkout 时绑定的 payment_id
     * @param int|null $callbackPaymentId 本次回调所用 uuid 对应的 payment_id
     */
    public static function equivalent(?int $orderPaymentId, ?int $callbackPaymentId): bool
    {
        if ($orderPaymentId === null || $callbackPaymentId === null) {
            return false;
        }
        // 绝大多数回调走这里：同一条配置，零额外查询。
        if ($orderPaymentId === $callbackPaymentId) {
            return true;
        }

        $bound = Payment::find($orderPaymentId);
        $callback = Payment::find($callbackPaymentId);
        if (!$bound || !$callback) {
            return false;
        }

        return self::sameMerchant($bound, $callback);
    }

    /** 两条支付配置是否属于同一插件下的同一商户（同一套凭证）。 */
    public static function sameMerchant(Payment $a, Payment $b): bool
    {
        if ((int) $a->id === (int) $b->id) {
            return true;
        }
        // 插件类必须一致：不同插件的验签算法与密钥语义完全不同，指纹相等也没有意义。
        if ($a->payment === null || $b->payment === null) {
            return false;
        }
        if ((string) $a->payment !== (string) $b->payment) {
            return false;
        }

        $fingerprintA = self::fingerprint($a);
        $fingerprintB = self::fingerprint($b);
        if ($fingerprintA === null || $fingerprintB === null) {
            return false;
        }

        return hash_equals($fingerprintA, $fingerprintB);
    }

    /**
     * 商户凭证指纹。
     *
     * config 缺失 / 非数组 / 剔除展示字段后为空时返回 null —— 这种配置无从判断商户
     * 归属，调用方必须按「不同源」处理，绝不能退化成「都为空所以相等」。
     */
    public static function fingerprint(Payment $payment): ?string
    {
        $config = $payment->config;
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            $config = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($config)) {
            return null;
        }

        foreach (self::NON_IDENTITY_KEYS as $key) {
            unset($config[$key]);
        }
        if ($config === []) {
            return null;
        }

        self::ksortRecursive($config);
        $canonical = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($canonical === false) {
            return null;
        }

        return hash('sha256', (string) $payment->payment . "\0" . $canonical);
    }

    /** 递归按键排序，保证同一份 config 的不同书写顺序得到同一指纹。 */
    private static function ksortRecursive(array &$array): void
    {
        ksort($array);
        foreach ($array as &$value) {
            if (is_array($value)) {
                self::ksortRecursive($value);
            }
        }
        unset($value);
    }
}
