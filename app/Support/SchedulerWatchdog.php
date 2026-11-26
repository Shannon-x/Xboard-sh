<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 网页进程（Octane tick，每 30 秒）里的调度看门狗。
 *
 * - 心跳过期（默认 150 秒）：在独立子进程里代跑一次 schedule:run，并提醒「专用调度进程没在跑」；
 * - 心跳过期很久（默认 5 分钟，代跑也没救回来）：紧急告警，30 分钟重复一次；
 * - 告警过之后心跳恢复：发一条恢复通知。
 *
 * 不再在 Octane worker 里 Artisan::call('schedule:run')：那样整轮调度都跑在网页进程里，
 * 任何一个前台任务卡住，tick 所在的 worker 就一直被占着，之后的调度全部停摆（2026-11-23）。
 * 现在只派一个脱离的子进程，tick 本身几毫秒就返回。
 */
final class SchedulerWatchdog
{
    private const SPAWN_LOCK = 'scheduler:fallback_spawn';
    private const FALLBACK_ALERT = 'scheduler:fallback_alerted';
    private const STALL_ALERT = 'scheduler:stall_alerted';
    private const ALERTED_FLAG = 'scheduler:alert_open';

    /** @var callable(): void|null 测试替身：派子进程 */
    private static $spawner = null;

    public static function useSpawner(?callable $spawner): void
    {
        self::$spawner = $spawner;
    }

    /**
     * @return string 本次做了什么：ok / fallback / skipped
     */
    public static function tick(?string $mode = null, ?int $now = null): string
    {
        $mode ??= (string) config('scheduler.octane_tick', 'auto');
        $now ??= time();
        $staleAfter = max(60, (int) config('scheduler.stale_after', 150));
        $alertAfter = max($staleAfter, (int) config('scheduler.alert_after', 300));
        $age = SchedulerHeartbeat::age($now);

        try {
            if ($mode === 'on') {
                self::spawnOncePerMinute();
                return 'fallback';
            }

            $stale = $age === null || $age > $staleAfter;
            if (!$stale) {
                self::announceRecovery($age);
                return 'ok';
            }

            if ($mode === 'auto') {
                self::spawnOncePerMinute();
                if ($age !== null && Cache::add(self::FALLBACK_ALERT, 1, 3600)) {
                    Cache::forever(self::ALERTED_FLAG, 1);
                    AdminAlert::send("⚠️ 定时任务：专用调度进程已 {$age} 秒没有心跳\n"
                        . "网页进程已临时代跑定时任务，业务不受影响；请检查 scheduler 容器（docker compose ps / logs scheduler）。\n"
                        . '1 小时内不再重复提醒。');
                }
            }

            if ($age !== null && $age > $alertAfter && Cache::add(self::STALL_ALERT, 1, 1800)) {
                Cache::forever(self::ALERTED_FLAG, 1);
                $minutes = intdiv($age, 60);
                AdminAlert::send("🚨 定时任务已停摆 {$minutes} 分钟\n"
                    . "退款回查封号、付款核验、流量重置、佣金结算、每日邮件都不会跑。\n"
                    . ($mode === 'auto' ? '网页进程代跑也没能恢复，' : '')
                    . '请立即检查 scheduler 与 web 容器。30 分钟后仍未恢复会再提醒。', 'markdown', true);
            }
            return $mode === 'auto' ? 'fallback' : 'skipped';
        } catch (\Throwable $e) {
            try {
                Log::warning('[scheduler] 看门狗出错', ['error' => $e->getMessage()]);
            } catch (\Throwable) {
            }
            return 'skipped';
        }
    }

    private static function announceRecovery(?int $age): void
    {
        if (!Cache::pull(self::ALERTED_FLAG)) {
            return;
        }
        Cache::forget(self::FALLBACK_ALERT);
        Cache::forget(self::STALL_ALERT);
        AdminAlert::send('✅ 定时任务已恢复，最近一次心跳在 ' . (int) $age . ' 秒前。积压的任务会分批补跑。');
    }

    /** 每分钟最多派一个 schedule:run 子进程（同一分钟内的重复派发由事件自身的 onOneServer 再兜一层） */
    private static function spawnOncePerMinute(): void
    {
        if (!Cache::add(self::SPAWN_LOCK, 1, 55)) {
            return;
        }
        if (self::$spawner !== null) {
            (self::$spawner)();
            return;
        }
        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        // 脱离的子进程：标准输入输出都重定向，不继承网页进程的管道，exec 立即返回
        $command = 'cd ' . escapeshellarg(base_path())
            . ' && nohup ' . escapeshellarg($php) . ' artisan schedule:run'
            . ' >> ' . escapeshellarg(storage_path('logs/scheduler-fallback.log')) . ' 2>&1 < /dev/null &';
        exec($command);
    }
}
