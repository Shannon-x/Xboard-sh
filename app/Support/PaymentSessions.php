<?php

namespace App\Support;

use App\Models\Payment;
use Plugin\PaymentAttempt\Support\Attempts;

/**
 * 支付会话（PaymentAttempt 插件）在核心里的唯一入口。
 *
 * 会话把「单据」和「每次发起的支付」拆开：发给网关的 out_trade_no 是 <单号>-<8位随机>，
 * 用户中途换支付方式（含跨网关）重新付款时不会撞上易支付「预下单订单号已存在」，旧收银台链接
 * 付的钱也照样认账。插件没装 / 停用 / 网关没接会话号时，这里一律退回「发单号本身」的老路。
 *
 * 插件是可选的：核心只通过这个类碰它，类不存在时每个方法都有不依赖插件的退路。
 * 内置的 EPay 插件在 notify() 里也是按 class_exists 接的会话认领。
 */
final class PaymentSessions
{
    public const SUBJECT_ORDER = 'order';
    public const SUBJECT_RECHARGE = 'recharge';

    /** 会话状态（与插件的 PaymentAttempt::STATUS_* 一致）：已收款 / 重复到账已退余额 */
    public const ATTEMPT_PAID = 1;
    public const ATTEMPT_REFUNDED = 3;

    public static function installed(): bool
    {
        return class_exists(Attempts::class);
    }

    /**
     * 这次发起支付能否走会话：插件装着且启用、该网关插件的 notify() 已经认会话号。
     * 没接会话号的网关拿到会话号会当单号去查，一律查不到 → 回调被拒，钱进商户号单据不到账。
     */
    public static function available(Payment $payment): bool
    {
        return self::installed()
            && Attempts::enabled()
            && Attempts::refAware((string) $payment->payment);
    }

    /** 这条支付配置的网关插件认不认会话号（不看插件开关：开关只管新建会话，已发出的会话照样认领） */
    public static function refAware(Payment $payment): bool
    {
        return self::installed() && Attempts::refAware((string) $payment->payment);
    }

    /**
     * 本次发给网关的 out_trade_no。复用规则（轮询期间不换号）在插件里，任何异常都退回单号本身。
     */
    public static function begin(
        string $subjectType,
        int $subjectId,
        string $tradeNo,
        int $userId,
        Payment $payment,
        int $expectedAmount
    ): string {
        if (!self::available($payment)) {
            return $tradeNo;
        }
        return Attempts::beginForSubject($subjectType, $subjectId, $tradeNo, $userId, $payment, $expectedAmount);
    }

    /**
     * 把入参里可能出现的会话号归一成真实单号。网关付完跳回的地址、前端拿着它查详情 / 轮询 / 取消，
     * 带的都可能是会话号。认不出就原样返回：调用方随后仍按 user_id 限定归属，不放宽任何权限。
     */
    public static function resolveTradeNo(?string $candidate): ?string
    {
        if ($candidate === null || $candidate === '' || !str_contains($candidate, '-') || !self::installed()) {
            return $candidate;
        }
        $resolved = Attempts::resolveTradeNo($candidate);
        return is_string($resolved) && $resolved !== '' ? $resolved : $candidate;
    }

    /**
     * 回调带回的会话行，且它确实属于这张单据、这类单据、这条支付配置；否则当作没有会话
     * （调用方随后回落到「回调网关与单据绑定网关同源」的老闸门，只会更严不会更松）。
     */
    public static function findFor(?string $ref, string $subjectType, int $subjectId, int $callbackPaymentId): ?object
    {
        if ($ref === null || $ref === '' || !self::installed()) {
            return null;
        }
        $attempt = Attempts::find($ref);
        if (!$attempt
            || (string) ($attempt->subject_type ?? self::SUBJECT_ORDER) !== $subjectType
            || (int) $attempt->order_id !== $subjectId
            || (int) $attempt->payment_id !== $callbackPaymentId) {
            return null;
        }
        return $attempt;
    }

    /** 标记会话已收款（单据已因这条会话入账）。幂等，失败只记日志。 */
    public static function settle(?string $ref, string $callbackNo): void
    {
        if ($ref === null || $ref === '' || !self::installed()) {
            return;
        }
        Attempts::settle($ref, $callbackNo);
    }

    /**
     * 单据早已入账、又有一条会话收到钱：重投判成 noop，真正的第二笔按实付额退回余额并告警。
     *
     * @return string 'noop' | 'refunded' | 'manual' | 'unavailable'（插件不在，调用方自行转人工）
     */
    public static function creditDuplicate(?string $ref, string $callbackNo, ?int $paidMinor, ?string $subjectCallbackNo): string
    {
        if ($ref === null || $ref === '' || !self::installed() || Attempts::find($ref) === null) {
            return 'unavailable';
        }
        return Attempts::creditDuplicate($ref, $callbackNo, $paidMinor, $subjectCallbackNo);
    }
}
