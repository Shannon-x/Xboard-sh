<?php

namespace App\Services\Commission;

use App\Models\Order;
use App\Models\User;

/**
 * 被邀请人首单优惠（双向奖励的「被邀请人」一侧）。
 *
 * 后台 invite_first_order_discount 填百分比：12 = 首单 88 折，0 = 关闭。
 * 条件：下单人有邀请人且不是自己、设置 > 0、尚无有效订单（待支付 / 已取消不算）。
 * 与优惠券不叠加，取较大者（见 OrderService::applyInviteFirstOrderDiscount）。
 * 只是减价，不拦下单；佣金照旧按折后实付算。
 */
final class InviteDiscount
{
    public static function percent(): int
    {
        return max(0, min(100, (int) admin_setting('invite_first_order_discount', 0)));
    }

    public static function eligible(?User $user): bool
    {
        if (!$user || self::percent() <= 0) {
            return false;
        }
        $inviterId = (int) ($user->invite_user_id ?? 0);
        if ($inviterId <= 0 || $inviterId === (int) $user->id) {
            return false;
        }
        return !Order::where('user_id', $user->id)
            ->whereNotIn('status', [Order::STATUS_PENDING, Order::STATUS_CANCELLED])
            ->exists();
    }

    /** 以当前应付额为基数的优惠金额（分） */
    public static function amountFor(int $base): int
    {
        if ($base <= 0) {
            return 0;
        }
        return min($base, (int) round($base * self::percent() / 100));
    }
}
