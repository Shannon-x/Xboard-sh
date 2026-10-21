<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\DB;

/**
 * 让队列任务的落库「恰好一次」。
 *
 * 任务的数据写入和一行以任务 uuid 为主键的回执放进同一个事务：
 *   · 第一次执行：插回执成功 → 写数据 → 一起提交；
 *   · 写数据抛异常：整个事务回滚，回执也没了 → 重试时照常再做（死锁 / 锁等待超时就是这种情况）；
 *   · 上次其实已经提交、只是 worker 在确认前被杀或超时（Horizon 按 retry_after 重新投递）：
 *     重试时回执已在（insertOrIgnore 影响 0 行）→ 不再写数据。
 * 两个 worker 同时跑同一个 uuid 时，后来的那个会在回执行锁上等前一个提交 / 回滚，再按上面两种情况走。
 *
 * 没有队列上下文（dispatchSync、测试里直接 handle()）时拿不到 uuid，就直接执行、不记回执。
 * 回执保留几天即可（ResetLog 每天清理）：重新投递最晚发生在 retry_after 之后，Horizon 里手动重试
 * 失败任务也在它的 7 天保留期内。
 */
final class JobReceipt
{
    public const TABLE = 'v2_job_receipt';

    /** 回执保留天数：覆盖 Horizon 失败任务的 7 天保留期 */
    public const RETENTION_DAYS = 8;

    /**
     * @param  Job|null  $job   InteractsWithQueue 的 $this->job；null 表示不在队列里
     * @param  Closure   $apply 真正的落库动作，在事务内执行
     * @return bool true = 这次写入了；false = 之前已经写过，这次跳过
     */
    public static function applyOnce(?Job $job, string $name, Closure $apply): bool
    {
        $uuid = $job?->uuid();
        if ($uuid === null || $uuid === '') {
            $apply();
            return true;
        }

        return (bool) DB::transaction(function () use ($uuid, $name, $apply) {
            $inserted = DB::table(self::TABLE)->insertOrIgnore([
                'job_uuid' => $uuid,
                'job' => mb_substr($name, 0, 64),
                'created_at' => time(),
            ]);
            if ($inserted === 0) {
                return false;
            }
            $apply();
            return true;
        });
    }

    /** 删除过期回执（分批，避免长事务）；返回删除行数 */
    public static function prune(int $retentionDays = self::RETENTION_DAYS): int
    {
        $cutoff = time() - $retentionDays * 86400;
        $total = 0;
        do {
            $deleted = DB::table(self::TABLE)->where('created_at', '<', $cutoff)->limit(5000)->delete();
            $total += $deleted;
        } while ($deleted >= 5000);
        return $total;
    }
}
