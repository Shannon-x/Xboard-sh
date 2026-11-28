<?php

namespace App\Console\Commands;

use App\Services\EnforcedUserPurge;
use Illuminate\Console\Command;

/**
 * 付款异常自动封号满 180 天的用户，自动删号（见 EnforcedUserPurge）。Kernel 每天 4:30 跑一次，
 * 结果进 9:00 的支付日报。
 */
class PurgeEnforcedUsers extends Command
{
    protected $signature = 'payment:purge-banned
        {--limit=200 : 这一次最多删多少个}
        {--dry-run : 只列出到期该删的，不删}';

    protected $description = '付款异常自动封号满 180 天的用户自动删号（删前把邮箱、单号写进核验记录以便追溯）';

    public function handle(EnforcedUserPurge $purge): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $purge->run(time(), (int) $this->option('limit'), $dryRun);

        foreach ($result['skipped'] as $userId => $reason) {
            $this->line("跳过 #{$userId}：{$reason}");
        }
        $ids = $result['deleted'] === [] ? '' : '：#' . implode(', #', $result['deleted']);
        $this->info(($dryRun ? '到期该删 ' : '已删除 ') . count($result['deleted']) . ' 个' . $ids);
        return self::SUCCESS;
    }
}
