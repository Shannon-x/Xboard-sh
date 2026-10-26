<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Payg\PaygConfig;
use App\Services\Payg\PaygService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * 上网按量月度保底：结算上个月（或 --month 指定的月份）。有使用、但用量按单价折算不足保底的按量钱包，
 * 从余额里补扣差额；没用的月份不扣。每天跑一次：1 号那次结算上个月，之后几天的重跑对已结算的用户是空操作
 * （v2_payg_log 每人每月一行的唯一键），服务器 1 号恰好停机也能补上。
 */
class PaygSettleFloor extends Command
{
    protected $signature = 'payg:settle-floor {--month= : 结算哪个月（YYYY-MM），默认上个月} {--dry-run : 只算不扣}';
    protected $description = '上网按量：上个月有使用但不足保底的，从按量余额补扣到保底';

    public function handle(PaygService $service): int
    {
        $config = PaygConfig::fromSettings();
        if (!$config->enabled() || $config->monthlyFloor <= 0) {
            $this->line('未配置按量套餐或保底为 0，跳过。');
            return self::SUCCESS;
        }
        $month = $this->option('month');
        if ($month !== null && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $month)) {
            $this->error('--month 格式应为 YYYY-MM');
            return self::FAILURE;
        }
        $target = $month !== null ? Carbon::createFromFormat('Y-m-d', $month . '-01')->startOfDay() : Carbon::now()->startOfMonth()->subMonth();
        $dryRun = (bool) $this->option('dry-run');
        $counts = [];
        $total = 0;
        User::whereIn('plan_id', $config->planIds)->whereNull('expired_at')->orderBy('id')
            ->chunkById(200, function ($users) use ($service, $target, $dryRun, $config, &$counts, &$total) {
                foreach ($users as $user) {
                    $result = $service->settleFloor($user, $target, $dryRun, $config);
                    $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;
                    $total += (int) ($result['amount'] ?? 0);
                }
            });
        ksort($counts);
        $this->info(($dryRun ? '演练 ' : '') . $target->format('Y-m') . '：'
            . ($counts ? implode(' · ', array_map(fn ($k, $v) => "$k $v", array_keys($counts), $counts)) : '没有按量用户')
            . '，补扣合计 ¥' . number_format($total / 100, 2));
        return self::SUCCESS;
    }
}
