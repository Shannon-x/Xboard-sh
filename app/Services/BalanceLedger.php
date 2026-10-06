<?php

namespace App\Services;

use App\Models\BalanceLog;
use App\Models\Order;
use App\Models\User;

/**
 * 余额流水。
 *
 * 约定：调用方在同一个事务里先把 user.balance 改好并保存，再把改完的用户传进来；
 * before 由 after - amount 反推，不另查库，和锁行里的值天然一致。
 * 写失败直接抛：余额变了却没有流水，比整单回滚更难收拾（跑过 xboard:update 之后表一定在）。
 */
final class BalanceLedger
{
    public static function record(User $user, string $type, int $amount, array $ctx = []): ?BalanceLog
    {
        if ($amount === 0) {
            return null;
        }
        $after = (int) ($user->balance ?? 0);
        return BalanceLog::create([
            'user_id' => (int) $user->id,
            'type' => $type,
            'amount' => $amount,
            'balance_before' => $after - $amount,
            'balance_after' => $after,
            'order_id' => isset($ctx['order_id']) && $ctx['order_id'] ? (int) $ctx['order_id'] : null,
            'ref_type' => isset($ctx['ref_type']) ? (string) $ctx['ref_type'] : null,
            'ref_id' => isset($ctx['ref_id']) ? mb_substr((string) $ctx['ref_id'], 0, 64) : null,
            'remark' => isset($ctx['remark']) && $ctx['remark'] !== '' ? mb_substr((string) $ctx['remark'], 0, 255) : null,
            'operator_id' => isset($ctx['operator_id']) ? (int) $ctx['operator_id'] : null,
            'created_at' => time(),
        ]);
    }

    /** 订单相关变动的 ctx：订单还没落库时 order_id 为空，但订单号一定有。 */
    public static function orderCtx(Order $order, ?string $remark = null): array
    {
        return [
            'order_id' => $order->id ?: null,
            'ref_type' => 'order',
            'ref_id' => (string) $order->trade_no,
            'remark' => $remark,
        ];
    }
}
