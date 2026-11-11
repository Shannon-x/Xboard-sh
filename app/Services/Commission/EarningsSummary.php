<?php

namespace App\Services\Commission;

use App\Models\BalanceLog;
use App\Models\CommissionLog;
use App\Models\CommissionWithdrawal;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * 邀请页「我的收益」：把一个推广者赚到的佣金拆成去向，外加按月走势。
 *
 * 金额单位全部是分（与 stat 一致），前端自己格式化。
 * - total：累计入账佣金（v2_commission_log.get_amount 之和，与 stat[1] 同口径）
 * - withdrawn / withdrawing：已打款 / 审核中的提现（申请时已从余额冻结）
 * - transferred：划转到账户余额的佣金（有流水之后的才算得到）
 * - available：当前可提现佣金（负数按 0 显示，负数只会是后台扣回的情况）
 * - months：最近 6 个自然月的入账，含本月，旧 → 新
 *
 * 只给推广者本人看自己的数，不含任何被邀请人信息。
 */
final class EarningsSummary
{
    public const MONTHS = 6;

    /**
     * @return array{total:int, pending:int, available:int, withdrawn:int, withdrawing:int, transferred:int, withdraw_count:int, this_month:int, last_month:int, months: list<array{month:string, amount:int}>, first_earned_at: int|null}
     */
    public static function forUser(User $user, int $pendingCents = 0, ?CarbonImmutable $now = null): array
    {
        $userId = (int) $user->id;
        $now ??= CarbonImmutable::now();

        $total = (int) CommissionLog::where('invite_user_id', $userId)->sum('get_amount');
        $firstEarnedAt = CommissionLog::where('invite_user_id', $userId)->min('created_at');

        $withdrawals = CommissionWithdrawal::where('user_id', $userId)
            ->whereIn('status', [CommissionWithdrawal::STATUS_COMPLETED, CommissionWithdrawal::STATUS_PENDING])
            ->selectRaw('status, COUNT(*) as cnt, SUM(amount) as total')
            ->groupBy('status')
            ->get()
            ->keyBy('status');
        $completed = $withdrawals->get(CommissionWithdrawal::STATUS_COMPLETED);
        $pendingWithdrawal = $withdrawals->get(CommissionWithdrawal::STATUS_PENDING);

        $transferred = (int) BalanceLog::where('user_id', $userId)
            ->where('type', BalanceLog::TYPE_COMMISSION_TRANSFER)
            ->sum('amount');

        // 按月走势：一次把窗口内的入账取出来在 PHP 里分桶，避免各数据库的日期函数差异（线上 MySQL、测试 sqlite）
        $monthStart = $now->startOfMonth();
        $windowStart = $monthStart->subMonths(self::MONTHS - 1);
        $buckets = [];
        for ($i = 0; $i < self::MONTHS; $i++) {
            $buckets[$windowStart->addMonths($i)->format('Y-m')] = 0;
        }
        CommissionLog::where('invite_user_id', $userId)
            ->where('created_at', '>=', $windowStart->getTimestamp())
            ->get(['get_amount', 'created_at'])
            ->each(function (CommissionLog $log) use (&$buckets, $now) {
                $key = CarbonImmutable::createFromTimestamp((int) $log->getRawOriginal('created_at'), $now->getTimezone())->format('Y-m');
                if (array_key_exists($key, $buckets)) {
                    $buckets[$key] += (int) $log->get_amount;
                }
            });

        $months = [];
        foreach ($buckets as $month => $amount) {
            $months[] = ['month' => $month, 'amount' => $amount];
        }

        return [
            'total' => $total,
            'pending' => max(0, (int) $pendingCents),
            'available' => max(0, (int) $user->commission_balance),
            'withdrawn' => (int) ($completed->total ?? 0),
            'withdrawing' => (int) ($pendingWithdrawal->total ?? 0),
            'transferred' => max(0, $transferred),
            'withdraw_count' => (int) ($completed->cnt ?? 0),
            'this_month' => $months[self::MONTHS - 1]['amount'],
            'last_month' => $months[self::MONTHS - 2]['amount'],
            'months' => $months,
            'first_earned_at' => $firstEarnedAt !== null ? (int) $firstEarnedAt : null,
        ];
    }
}
