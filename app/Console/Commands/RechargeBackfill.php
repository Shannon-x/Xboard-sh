<?php

namespace App\Console\Commands;

use App\Models\BalanceLog;
use App\Models\RechargeOrder;
use App\Models\Stat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 余额充值并入核心后的一次性历史修正（可重复跑，结果不变）：
 *
 * --ledger  插件时代充值入账走 addBalance 的默认类型，余额流水里记成「后台调整」且没有单号，
 *           用户在余额明细里看不到自己的充值。按「同一用户、金额 = 入账总额、时间紧挨到账时间」
 *           一一对上的才改标成「充值」并补单号，对不上的不动。
 * --stats   每日统计（v2_stat）的收款金额 / 笔数此前不含充值：给旧代码生成的那些天补上当天到账的充值，
 *           订单部分不动；只补一次（系统设置里记截止日）。
 */
class RechargeBackfill extends Command
{
    protected $signature = 'recharge:backfill
        {--ledger : 历史充值入账流水改标为「充值」}
        {--stats : 给旧代码生成的每日统计补上充值（只补一次）}
        {--before= : --stats 的截止日（不含），默认今天；上线当天跑就不用填}
        {--dry-run : 只统计会改多少，不写库}';

    protected $description = '余额充值历史数据修正：流水类型、每日统计口径';

    /** 每日统计补过充值的标记：值是截止日（这天之前的日统计已含充值） */
    private const STATS_MARKER = 'recharge_stats_backfilled_before';

    /** 流水时间与到账时间的容差（秒）：入账与改状态在同一事务里，实际差 0–1 秒 */
    private const LEDGER_WINDOW = 120;

    public function handle(): int
    {
        if (!$this->option('ledger') && !$this->option('stats')) {
            $this->error('请指定 --ledger 和 / 或 --stats');
            return self::INVALID;
        }
        $dryRun = (bool) $this->option('dry-run');
        if ($this->option('ledger')) {
            $this->backfillLedger($dryRun);
        }
        if ($this->option('stats')) {
            $this->backfillStats($dryRun);
        }
        return self::SUCCESS;
    }

    private function backfillLedger(bool $dryRun): void
    {
        $matched = 0;
        $ambiguous = 0;
        $missing = 0;
        $claimed = [];

        RechargeOrder::where('status', RechargeOrder::STATUS_COMPLETED)
            ->whereNotNull('paid_at')
            ->orderBy('id')
            ->chunkById(500, function ($recharges) use ($dryRun, &$matched, &$ambiguous, &$missing, &$claimed) {
                foreach ($recharges as $recharge) {
                    if (BalanceLog::where('ref_type', 'recharge')->where('ref_id', $recharge->trade_no)->exists()) {
                        continue; // 已经是新口径（新代码入账，或上次回填过）
                    }
                    $candidates = BalanceLog::where('user_id', $recharge->user_id)
                        ->where('type', BalanceLog::TYPE_ADMIN_ADJUST)
                        ->whereNull('ref_type')
                        ->whereNull('operator_id')
                        ->where('amount', (int) $recharge->total_credit)
                        ->whereBetween('created_at', [(int) $recharge->paid_at - 5, (int) $recharge->paid_at + self::LEDGER_WINDOW])
                        ->whereNotIn('id', array_keys($claimed))
                        ->orderBy('id')
                        ->get(['id', 'remark']);
                    if ($candidates->isEmpty()) {
                        $missing++;
                        continue;
                    }
                    if ($candidates->count() > 1) {
                        $ambiguous++;
                        continue;
                    }
                    $log = $candidates->first();
                    $claimed[(int) $log->id] = true;
                    $matched++;
                    if ($dryRun) {
                        continue;
                    }
                    BalanceLog::where('id', $log->id)->update([
                        'type' => BalanceLog::TYPE_RECHARGE,
                        'ref_type' => 'recharge',
                        'ref_id' => (string) $recharge->trade_no,
                        'remark' => $log->remark ?: '充值 ¥' . number_format($recharge->deposit_amount / 100, 2, '.', '')
                            . ((int) $recharge->bonus_amount > 0 ? '，赠送 ¥' . number_format($recharge->bonus_amount / 100, 2, '.', '') : ''),
                    ]);
                }
            });

        $this->info(sprintf(
            '%s流水改标：对上 %d 笔%s；多条候选未改 %d 笔；找不到流水 %d 笔（流水功能上线前的充值没有流水，属正常）',
            $dryRun ? '[dry-run] ' : '',
            $matched,
            $dryRun ? '（未写库）' : '',
            $ambiguous,
            $missing
        ));
    }

    /**
     * 只给旧代码生成的日统计补上当天到账的充值（金额与笔数），订单部分保持当时记下的数字不动：
     * 按现在的订单数据整天重算，会把之后删号、收回的订单也一并从历史收入里减掉，那不是这次要改的东西。
     *
     * 只能补一次：做过就在系统设置里记下截止日，再跑直接跳过，避免同一天的充值被加两遍。
     * 截止日默认是今天 0 点——要在上线当天跑：上线之后生成的日统计本来就含充值。
     */
    private function backfillStats(bool $dryRun): void
    {
        $done = admin_setting(self::STATS_MARKER);
        if ($done) {
            $this->info('每日统计已经补过充值（' . date('Y-m-d', (int) $done) . ' 之前的日统计已含充值），跳过');
            return;
        }

        $before = $this->option('before') ? strtotime((string) $this->option('before')) : strtotime(date('Y-m-d'));
        if (!$before) {
            $this->error('--before 不是有效日期');
            return;
        }

        $updates = [];
        $amountTotal = 0;
        $countTotal = 0;
        Stat::where('record_type', 'd')
            ->where('record_at', '<', $before)
            ->orderBy('record_at')
            ->each(function (Stat $stat) use (&$updates, &$amountTotal, &$countTotal) {
                $from = (int) $stat->record_at;
                $to = strtotime('+1 day', $from);
                $recharges = RechargeOrder::where('status', RechargeOrder::STATUS_COMPLETED)
                    ->where('paid_at', '>=', $from)
                    ->where('paid_at', '<', $to);
                $count = (clone $recharges)->count();
                if ($count === 0) {
                    return;
                }
                $amount = (int) (clone $recharges)->sum('deposit_amount');
                $updates[(int) $stat->id] = [
                    'paid_total' => (int) $stat->paid_total + $amount,
                    'paid_count' => (int) $stat->paid_count + $count,
                ];
                $amountTotal += $amount;
                $countTotal += $count;
            });

        if (!$dryRun) {
            DB::transaction(function () use ($updates, $before) {
                foreach ($updates as $id => $values) {
                    Stat::where('id', $id)->update($values);
                }
                admin_setting([self::STATS_MARKER => $before]);
            });
        }

        $this->info(sprintf(
            '%s每日统计：%d 天补上充值 %d 笔、¥%s（%s 之前；订单部分不动）%s',
            $dryRun ? '[dry-run] ' : '',
            count($updates),
            $countTotal,
            number_format($amountTotal / 100, 2, '.', ''),
            date('Y-m-d', $before),
            $dryRun ? '（未写库）' : ''
        ));
    }
}
