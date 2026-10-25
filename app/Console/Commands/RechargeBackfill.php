<?php

namespace App\Console\Commands;

use App\Models\BalanceLog;
use App\Models\RechargeOrder;
use App\Models\Stat;
use App\Services\StatisticalService;
use Illuminate\Console\Command;

/**
 * 余额充值并入核心后的一次性历史修正（可重复跑，结果不变）：
 *
 * --ledger  插件时代充值入账走 addBalance 的默认类型，余额流水里记成「后台调整」且没有单号，
 *           用户在余额明细里看不到自己的充值。按「同一用户、金额 = 入账总额、时间紧挨到账时间」
 *           一一对上的才改标成「充值」并补单号，对不上的不动。
 * --stats   每日统计（v2_stat）的收款金额 / 笔数此前不含充值，按新口径重算有充值的那几天。
 */
class RechargeBackfill extends Command
{
    protected $signature = 'recharge:backfill
        {--ledger : 历史充值入账流水改标为「充值」}
        {--stats : 按含充值的口径重算历史每日统计}
        {--dry-run : 只统计会改多少，不写库}';

    protected $description = '余额充值历史数据修正：流水类型、每日统计口径';

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

    private function backfillStats(bool $dryRun): void
    {
        $today = strtotime(date('Y-m-d'));
        $days = RechargeOrder::where('status', RechargeOrder::STATUS_COMPLETED)
            ->where('paid_at', '<', $today)
            ->pluck('paid_at')
            ->map(fn ($paidAt) => strtotime(date('Y-m-d', (int) $paidAt)))
            ->unique()
            ->sort()
            ->values();

        $changed = 0;
        $delta = 0;
        $rechargeTotal = 0;
        foreach ($days as $dayStart) {
            $stat = Stat::where('record_at', $dayStart)->where('record_type', 'd')->first();
            if (!$stat) {
                continue; // 那天没有日统计（统计任务没跑过），不凭空造一行
            }
            $service = new StatisticalService();
            $service->setStartAt($dayStart);
            $service->setEndAt(strtotime('+1 day', $dayStart));
            $data = $service->generateStatData();
            if ((int) $stat->paid_total === (int) $data['paid_total'] && (int) $stat->paid_count === (int) $data['paid_count']) {
                continue;
            }
            $changed++;
            $delta += (int) $data['paid_total'] - (int) $stat->paid_total;
            $rechargeTotal += RechargeOrder::incomeBetween($dayStart, strtotime('+1 day', $dayStart));
            if (!$dryRun) {
                $stat->update(['paid_total' => $data['paid_total'], 'paid_count' => $data['paid_count']]);
            }
        }

        $this->info(sprintf(
            '%s每日统计：%d 天的收款按含充值口径重算，收款合计变化 ¥%s（其中充值 ¥%s，差额是这些天订单状态的后续变化）%s',
            $dryRun ? '[dry-run] ' : '',
            $changed,
            number_format($delta / 100, 2, '.', ''),
            number_format($rechargeTotal / 100, 2, '.', ''),
            $dryRun ? '（未写库）' : ''
        ));
    }
}
