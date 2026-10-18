<?php

namespace App\Services\Commission;

use App\Models\Order;

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
 * 才能把佣金提成现金。
 */
final class InviteeStats
{
    public static function paidInviteeCount(int $inviterId): int
    {
        if ($inviterId <= 0) {
            return 0;
        }
        return (int) Order::where('invite_user_id', $inviterId)
            ->where('status', Order::STATUS_COMPLETED)
            ->where('total_amount', '>', 0)
            ->distinct()
            ->count('user_id');
    }
}
