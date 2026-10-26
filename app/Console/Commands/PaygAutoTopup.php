<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Payg\PaygConfig;
use App\Services\Payg\PaygService;
use Illuminate\Console\Command;

/**
 * 上网按量自动补充：每分钟圈出「开了开关、按量余额低于门槛」的用户，逐个交给 PaygService::attemptAutoTopup
 * （账户余额够一份才下单、全额余额抵扣、走 0 元订单开通链路）。门槛按套餐单价换成字节在库里筛，
 * 不必把所有按量用户读出来。
 */
class PaygAutoTopup extends Command
{
    protected $signature = 'payg:auto-topup {--dry-run : 只判断不下单}';
    protected $description = '上网按量：余额低于门槛且开了自动补充的用户，用账户余额买一份';

    public function handle(PaygService $service): int
    {
        $config = PaygConfig::fromSettings();
        if (!$config->enabled() || !$config->autoTopupEnable || $config->autoTopupThreshold <= 0) {
            return self::SUCCESS;
        }
        $dryRun = (bool) $this->option('dry-run');
        $counts = [];
        foreach (\App\Models\Plan::whereIn('id', $config->planIds)->get() as $plan) {
            $unit = PaygConfig::unitPrice($plan);
            if ($unit === null) {
                continue;
            }
            $threshold = PaygConfig::bytesFor($unit, $config->autoTopupThreshold);
            // 剩余 < 门槛 ⇔ u + d + 门槛 > transfer_enable（写成加法，不依赖列是否有符号）
            User::where('plan_id', $plan->id)->whereNull('expired_at')
                ->where('payg_auto_topup', 1)->where('banned', 0)
                ->whereRaw('u + d + ? > transfer_enable', [$threshold])
                ->orderBy('id')
                ->chunkById(100, function ($users) use ($service, $config, $dryRun, &$counts) {
                    foreach ($users as $user) {
                        $result = $service->attemptAutoTopup($user, $dryRun, $config);
                        $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;
                        if (in_array($result['status'], ['topped_up', 'would_topup', 'error'], true)) {
                            $this->line(sprintf('  #%d %s %s', $user->id, $result['status'], json_encode(array_diff_key($result, ['status' => 1]), JSON_UNESCAPED_UNICODE)));
                        }
                    }
                });
        }
        if ($counts) {
            ksort($counts);
            $this->info(($dryRun ? '演练 ' : '') . implode(' · ', array_map(fn ($k, $v) => "$k $v", array_keys($counts), $counts)));
        }
        return self::SUCCESS;
    }
}
