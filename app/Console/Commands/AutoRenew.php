<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\RenewService;
use Illuminate\Console\Command;

/**
 * 自动续费：每小时跑一次，处理「开了开关 + 到期前 lead 小时 ~ 到期后 grace 小时」窗口内的用户。
 * 每个用户的判断与下单都在 RenewService::attemptAutoRenew 里；这里只负责圈人和汇总。
 */
class AutoRenew extends Command
{
    protected $signature = 'renew:auto {--dry-run : 只判断不下单、不发通知}';
    protected $description = '为开启了自动续费且临近到期的用户按上次配置用余额续费';

    public function handle(RenewService $service): int
    {
        if (!RenewService::siteEnabled()) {
            $this->line('站点未开启自动续费，跳过。');
            return self::SUCCESS;
        }
        $now = time();
        $from = $now - RenewService::graceHours() * 3600;
        $to = $now + RenewService::leadHours() * 3600;
        $dryRun = (bool) $this->option('dry-run');
        $counts = [];

        User::where('auto_renew', 1)->where('banned', 0)
            ->whereNotNull('plan_id')->whereNotNull('expired_at')
            ->whereBetween('expired_at', [$from, $to])
            ->orderBy('id')
            ->chunkById(200, function ($users) use ($service, $dryRun, &$counts) {
                foreach ($users as $user) {
                    $result = $service->attemptAutoRenew($user, $dryRun);
                    $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;
                    if (in_array($result['status'], ['renewed', 'would_renew', 'error'], true)) {
                        $this->line(sprintf('  #%d %s %s', $user->id, $result['status'], json_encode(array_diff_key($result, ['status' => 1]), JSON_UNESCAPED_UNICODE)));
                    }
                }
            });

        ksort($counts);
        $this->info(($dryRun ? '演练 ' : '') . '窗口 ' . date('m-d H:i', $from) . ' ~ ' . date('m-d H:i', $to) . '：'
            . ($counts ? implode(' · ', array_map(fn ($k, $v) => "$k $v", array_keys($counts), $counts)) : '没有需要处理的用户'));
        return self::SUCCESS;
    }
}
