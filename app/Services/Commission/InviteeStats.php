<?php

namespace App\Services\Commission;

use App\Models\Order;
use App\Models\User;

/**
 * 「付费被邀请人」统计。
 *
 * 口径：以订单上的 invite_user_id 归属为准（与 CheckCommission 发佣用的是同一个字段），
 * 只算已完成且实付 > 0 的订单，按下单人去重：
 *   · 0 元单（被余额 / 折扣抵光）保留了邀请关系但不算付费，余额本身可能就来自佣金划转，
 *     算进去会让「自己邀请自己」靠划转余额循环凑数；
 *   · 待支付 / 已取消 / 已折抵不算；
 *   · 同一个被邀请人买多次只算 1 人。
 *
 * 用途：提现（可选含佣金划转）的人数门槛，抬高自邀的成本——要先养出 N 个真实付费账号
 * 才能把佣金提成现金；阶梯佣金比例按同一口径取档。
 */
final class InviteeStats
{
    public static function paidInviteeCount(int $inviterId): int
    {
        if ($inviterId <= 0) {
            return 0;
        }
        return (int) self::paidOrders($inviterId)
            ->distinct()
            ->count('user_id');
    }

    /**
     * 「有效付费被邀请人」：在上面的口径之上，再要求该被邀请人**当前订阅有效**
     * （有套餐，且未到期或永久）。这个数会随被邀请人订阅到期而下降，
     * 用于自动周期返佣的门槛——掉到门槛以下时自动恢复为首单返佣。
     */
    public static function activePaidInviteeCount(int $inviterId): int
    {
        if ($inviterId <= 0) {
            return 0;
        }
        $now = time();
        return (int) User::whereNotNull('plan_id')
            ->where(function ($query) use ($now) {
                $query->whereNull('expired_at')->orWhere('expired_at', '>', $now);
            })
            ->whereIn('id', self::paidOrders($inviterId)->select('user_id'))
            ->count();
    }

    /** 已完成且实付 > 0、归属该邀请人的订单（未去重） */
    public static function paidOrders(int $inviterId)
    {
        return Order::where('invite_user_id', $inviterId)
            ->where('status', Order::STATUS_COMPLETED)
            ->where('total_amount', '>', 0);
    }
}
