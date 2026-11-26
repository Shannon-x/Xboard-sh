<?php

namespace App\Support;

use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 定时任务心跳：由调度里每分钟一次的事件写入，证明 schedule:run 真的在按分钟派发任务。
 *
 * 以前「计划任务最后检查时间」是在 Kernel::schedule() 里写的，而 schedule() 在任何 artisan 命令
 * 启动时都会被调用（包括 horizon 的子进程、tinker），调度停了它也照样刷新，后台显示「正常」。
 */
final class SchedulerHeartbeat
{
    public const KEY = 'scheduler:heartbeat_at';

    public static function beat(?int $now = null): void
    {
        $now ??= time();
        Cache::forever(self::KEY, $now);
        // 后台「系统状态」页读这个键
        Cache::forever(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null), $now);

        $url = (string) config('scheduler.heartbeat_url', '');
        if ($url !== '') {
            try {
                Http::timeout(3)->connectTimeout(2)->get($url);
            } catch (\Throwable $e) {
                Log::notice('[scheduler] 外部心跳上报失败', ['error' => $e->getMessage()]);
            }
        }
    }

    public static function lastAt(): ?int
    {
        $at = Cache::get(self::KEY);
        return is_numeric($at) ? (int) $at : null;
    }

    /** 距上次心跳多少秒；从没跳过返回 null */
    public static function age(?int $now = null): ?int
    {
        $at = self::lastAt();
        return $at === null ? null : max(0, ($now ?? time()) - $at);
    }

    public static function isStale(int $threshold, ?int $now = null): bool
    {
        $age = self::age($now);
        return $age === null || $age > $threshold;
    }
}
