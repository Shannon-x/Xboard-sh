<?php

namespace App\Services;

use App\Models\User;
use App\Models\Plan;
use App\Models\TrafficResetLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Services\Plugin\HookManager;

/**
 * Service for handling traffic reset.
 */
class TrafficResetService
{
  /**
   * Check if a user's traffic should be reset and perform the reset.
   */
  public function checkAndReset(User $user, string $triggerSource = TrafficResetLog::SOURCE_AUTO): bool
  {
    if (!$user->shouldResetTraffic()) {
      return false;
    }

    return $this->performReset($user, $triggerSource);
  }

  /**
   * Perform the traffic reset for a user.
   *
   * @param bool $preserveSchedule 「只清零流量、不重排周期」的场景（流量重置包、礼品卡重置包）
   *                               传 true，避免把已排定的重置日往后推。详见
   *                               keepScheduledResetIfEarlier() 的注释。
   */
  public function performReset(
    User $user,
    string $triggerSource = TrafficResetLog::SOURCE_MANUAL,
    bool $preserveSchedule = false
  ): bool {
    try {
      return DB::transaction(function () use ($user, $triggerSource, $preserveSchedule) {
        $oldUpload = $user->u ?? 0;
        $oldDownload = $user->d ?? 0;
        $oldTotal = $oldUpload + $oldDownload;

        $nextResetTime = $this->calculateNextResetTime($user);
        $nextResetAt = $nextResetTime?->timestamp;

        if ($preserveSchedule) {
          $nextResetAt = $this->keepScheduledResetIfEarlier($user, $nextResetAt);
        }

        $user->update([
          'u' => 0,
          'd' => 0,
          'last_reset_at' => time(),
          'reset_count' => (int) $user->reset_count + 1,
          'next_reset_at' => $nextResetAt,
        ]);

        $this->recordResetLog($user, [
          'reset_type' => $this->getResetTypeFromPlan($user->plan),
          'trigger_source' => $triggerSource,
          'old_upload' => $oldUpload,
          'old_download' => $oldDownload,
          'old_total' => $oldTotal,
          'new_upload' => 0,
          'new_download' => 0,
          'new_total' => 0,
        ]);

        $this->clearUserCache($user);
        HookManager::call('traffic.reset.after', $user);
        return true;
      });
    } catch (\Exception $e) {
      Log::error(__('traffic_reset.reset_failed'), [
        'user_id' => $user->id,
        'email' => $user->email,
        'error' => $e->getMessage(),
        'trigger_source' => $triggerSource,
      ]);

      return false;
    }
  }

  /**
   * 「只清零流量」的重置不得把已排定的重置日往后推。
   *
   * 真实事故（user 10100 / 工单 #2444）：8-21 入口 IP 被墙的故障补偿用裸 SQL 只把
   * expired_at +7 天（08-27 → 09-03），刻意没动 next_reset_at，用户面板上仍显示
   * 「3 天后重置」。用户随后花 36.12 元买了流量重置包，performReset 顺手按**新的**
   * expired_at 重新锚定 next_reset_at，把 08-27 那次重置推到了 09-03 —— 付费产品把
   * 用户本就该拿到的一轮 550GB 吃掉了，面板上「3 天后」当场变成「10 天后」。
   *
   * 语义：重置包卖的是「立刻清零」，不是「重排周期」。只有 expired_at 真的变了
   * （续费重开周期 / 套餐变更 / 新购）才该重新锚定，那些路径不传 $preserveSchedule。
   *
   * 仅在「原定时间仍在未来」且「重算结果比它更晚」时保留原值：
   *  - 原定时间已过期必须重算，否则每分钟跑的 reset:traffic 会反复命中同一时间点无限重置；
   *  - 重算结果更早时按重算走，本方法只会保留更早的排期，永远不会把重置日往后拖。
   */
  private function keepScheduledResetIfEarlier(User $user, ?int $recalculated): ?int
  {
    $scheduled = $user->next_reset_at;
    $scheduled = $scheduled instanceof \DateTimeInterface
      ? $scheduled->getTimestamp()
      : ($scheduled !== null ? (int) $scheduled : null);

    if ($scheduled === null || $recalculated === null) {
      return $recalculated;
    }

    return ($scheduled > time() && $scheduled < $recalculated) ? $scheduled : $recalculated;
  }

  /**
   * Calculate the next traffic reset time for a user.
   */
  public function calculateNextResetTime(User $user): ?Carbon
  {
    if (
      !$user->plan
      || $user->plan->reset_traffic_method === Plan::RESET_TRAFFIC_NEVER
      || ($user->plan->reset_traffic_method === Plan::RESET_TRAFFIC_FOLLOW_SYSTEM
        && (int) admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY) === Plan::RESET_TRAFFIC_NEVER)
      || $user->expired_at === NULL
    ) {
      return null;
    }

    $resetMethod = $user->plan->reset_traffic_method;

    if ($resetMethod === Plan::RESET_TRAFFIC_FOLLOW_SYSTEM) {
      $resetMethod = (int) admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY);
      // 系统级 reset_traffic_method 也可能是 NEVER（合法值），这种情况就不应该再算 next_reset_at
      if ($resetMethod === Plan::RESET_TRAFFIC_NEVER) {
        return null;
      }
    }

    $now = Carbon::now(config('app.timezone'));

    return match ($resetMethod) {
      Plan::RESET_TRAFFIC_FIRST_DAY_MONTH => $this->getNextMonthFirstDay($now),
      Plan::RESET_TRAFFIC_MONTHLY => $this->getNextMonthlyReset($user, $now),
      Plan::RESET_TRAFFIC_FIRST_DAY_YEAR => $this->getNextYearFirstDay($now),
      Plan::RESET_TRAFFIC_YEARLY => $this->getNextYearlyReset($user, $now),
      // 未知枚举（DB 脏值或新增枚举尚未在此 switch 处理）：当作"按月"兜底，
      // 而不是 null。原 default => null 会让用户被默默从 cron 重置流程中排除，
      // 现象上是"流量永远不重置"，运维侧也看不到任何报错。
      default => $this->getNextMonthlyReset($user, $now),
    };
  }

  /**
   * Get the first day of the next month.
   *
   * 注意：先 startOfMonth 再 addMonthNoOverflow，避免 addMonth() 在 1/31 这种"目标月没这一天"
   * 的场景溢出到 3/2（startOfMonth 后变成 3/1，整月跳过 2 月）。
   */
  private function getNextMonthFirstDay(Carbon $from): Carbon
  {
    return $from->copy()->startOfMonth()->addMonthNoOverflow();
  }

  /**
   * Get the next monthly reset time based on the user's expiration date.
   *
   * 关键约束：当 expiredAt.day = 29/30/31 时，不能简单 Carbon::day(31)，因为 Carbon 在目标月没有
   * 这一天时会**直接溢出**（例：2026-02 + day(31) → 2026-03-03，整月跳过 2 月）。
   * 必须把 resetDay 夹到目标月的最后一天（feb→28/29、apr→30、…）。
   *
   * 之前只修了"每月 1 号"路径（getNextMonthFirstDay），按到期日月重置这条路径上的当月候选
   * 与下月候选都要做 day-clamping，否则 2 月用户仍会被推到 3 月。
   */
  private function getNextMonthlyReset(User $user, Carbon $from): Carbon
  {
    $tz = config('app.timezone');
    $expiredAt = Carbon::createFromTimestamp($user->expired_at, $tz);
    $resetDay = $expiredAt->day;
    [$rh, $rm, $rs] = [$expiredAt->hour, $expiredAt->minute, $expiredAt->second];

    // 当月候选：把 resetDay 夹到当月最后一天，再组装
    $curLast = $from->copy()->endOfMonth()->day;
    $curTargetDay = min($resetDay, $curLast);
    $currentMonthTarget = Carbon::create($from->year, $from->month, $curTargetDay, $rh, $rm, $rs, $tz);
    if ($currentMonthTarget->timestamp > $from->timestamp) {
      return $currentMonthTarget;
    }

    // 下月候选：同样夹到下月最后一天（关键修复点：原实现这里也直接 day($resetDay) 仍会溢出）
    $nextMonth = ($from->month % 12) + 1;
    $nextYear = $from->year + ($from->month === 12 ? 1 : 0);
    $nextLast = Carbon::create($nextYear, $nextMonth, 1, 0, 0, 0, $tz)->endOfMonth()->day;
    $nextTargetDay = min($resetDay, $nextLast);
    return Carbon::create($nextYear, $nextMonth, $nextTargetDay, $rh, $rm, $rs, $tz);
  }

  /**
   * Get the first day of the next year.
   */
  private function getNextYearFirstDay(Carbon $from): Carbon
  {
    return $from->copy()->addYear()->startOfYear();
  }

  /**
   * Get the next yearly reset time based on the user's expiration date.
   *
   * Logic:
   * 1. If the user has no expiration date, reset on January 1st of each year.
   * 2. If the user has an expiration date, use the month and day of that date as the yearly reset date.
   * 3. Prioritize the reset date in the current year if it has not passed yet.
   * 4. Handle the case of February 29th in a leap year.
   */
  private function getNextYearlyReset(User $user, Carbon $from): Carbon
  {
    $expiredAt = Carbon::createFromTimestamp($user->expired_at, config('app.timezone'));
    $resetMonth = $expiredAt->month;
    $resetDay = $expiredAt->day;
    $resetTime = [$expiredAt->hour, $expiredAt->minute, $expiredAt->second];

    $currentYearTarget = $from->copy()->month($resetMonth)->day($resetDay)->setTime(...$resetTime);
    if ($currentYearTarget->timestamp > $from->timestamp) {
      return $currentYearTarget;
    }
    
    $nextYearTarget = $from->copy()->startOfYear()->addYears(1)->month($resetMonth)->day($resetDay)->setTime(...$resetTime);
    
    if ($nextYearTarget->month !== $resetMonth) {
      $nextYear = $from->year + 1;
      $lastDayOfMonth = Carbon::create($nextYear, $resetMonth, 1)->endOfMonth()->day;
      $targetDay = min($resetDay, $lastDayOfMonth);
      $nextYearTarget = Carbon::create($nextYear, $resetMonth, $targetDay)->setTime(...$resetTime);
    }
    
    return $nextYearTarget;
  }


  /**
   * Record the traffic reset log.
   */
  private function recordResetLog(User $user, array $data): void
  {
    TrafficResetLog::create([
      'user_id' => $user->id,
      'reset_type' => $data['reset_type'],
      'reset_time' => now(),
      'old_upload' => $data['old_upload'],
      'old_download' => $data['old_download'],
      'old_total' => $data['old_total'],
      'new_upload' => $data['new_upload'],
      'new_download' => $data['new_download'],
      'new_total' => $data['new_total'],
      'trigger_source' => $data['trigger_source'],
      'metadata' => $data['metadata'] ?? null,
    ]);
  }

  /**
   * Get the reset type from the user's plan.
   */
  private function getResetTypeFromPlan(?Plan $plan): string
  {
    if (!$plan) {
      return TrafficResetLog::TYPE_MANUAL;
    }

    $resetMethod = $plan->reset_traffic_method;

    if ($resetMethod === Plan::RESET_TRAFFIC_FOLLOW_SYSTEM) {
      $resetMethod = (int) admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY);
    }

    return match ($resetMethod) {
      Plan::RESET_TRAFFIC_FIRST_DAY_MONTH => TrafficResetLog::TYPE_FIRST_DAY_MONTH,
      Plan::RESET_TRAFFIC_MONTHLY => TrafficResetLog::TYPE_MONTHLY,
      Plan::RESET_TRAFFIC_FIRST_DAY_YEAR => TrafficResetLog::TYPE_FIRST_DAY_YEAR,
      Plan::RESET_TRAFFIC_YEARLY => TrafficResetLog::TYPE_YEARLY,
      Plan::RESET_TRAFFIC_NEVER => TrafficResetLog::TYPE_MANUAL,
      default => TrafficResetLog::TYPE_MANUAL,
    };
  }

  /**
   * Clear user-related cache.
   */
  private function clearUserCache(User $user): void
  {
    $cacheKeys = [
      "user_traffic_{$user->id}",
      "user_reset_status_{$user->id}",
      "user_subscription_{$user->token}",
    ];

    foreach ($cacheKeys as $key) {
      Cache::forget($key);
    }
  }

  /**
   * Batch check and reset users. Processes all eligible users in batches.
   */
  public function batchCheckReset(int $batchSize = 100, ?callable $progressCallback = null): array
  {
    $startTime = microtime(true);
    $totalResetCount = 0;
    $totalProcessedCount = 0;
    $batchNumber = 1;
    $errors = [];
    $lastProcessedId = 0;

    try {
      do {
        $users = User::where('next_reset_at', '<=', time())
          ->whereNotNull('next_reset_at')
          ->where('id', '>', $lastProcessedId)
          ->where(function ($query) {
            $query->where('expired_at', '>', time())
              ->orWhereNull('expired_at');
          })
          ->where('banned', 0)
          ->whereNotNull('plan_id')
          ->orderBy('id')
          ->limit($batchSize)
          ->get();

        if ($users->isEmpty()) {
          break;
        }

        $batchResetCount = 0;

        if ($progressCallback) {
          $progressCallback([
            'batch_number' => $batchNumber,
            'batch_size' => $users->count(),
            'total_processed' => $totalProcessedCount,
          ]);
        }

        foreach ($users as $user) {
          try {
            if ($this->checkAndReset($user, TrafficResetLog::SOURCE_CRON)) {
              $batchResetCount++;
              $totalResetCount++;
            }
            $totalProcessedCount++;
            $lastProcessedId = $user->id;
          } catch (\Exception $e) {
            $error = [
              'user_id' => $user->id,
              'email' => $user->email,
              'error' => $e->getMessage(),
              'batch' => $batchNumber,
              'timestamp' => now()->toDateTimeString(),
            ];
            $batchErrors[] = $error;
            $errors[] = $error;

            Log::error('User traffic reset failed', $error);

            $totalProcessedCount++;
            $lastProcessedId = $user->id;
          }
        }

        $batchNumber++;

        if ($batchNumber % 10 === 0) {
          gc_collect_cycles();
        }

        if ($batchNumber % 5 === 0) {
          usleep(100000);
        }

      } while (true);

    } catch (\Exception $e) {
      Log::error('Batch traffic reset task failed with an exception', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'total_processed' => $totalProcessedCount,
        'total_reset' => $totalResetCount,
        'last_processed_id' => $lastProcessedId,
      ]);

      $errors[] = [
        'type' => 'system_error',
        'error' => $e->getMessage(),
        'batch' => $batchNumber,
        'last_processed_id' => $lastProcessedId,
        'timestamp' => now()->toDateTimeString(),
      ];
    }

    $totalDuration = round(microtime(true) - $startTime, 2);

    $result = [
      'total_processed' => $totalProcessedCount,
      'total_reset' => $totalResetCount,
      'total_batches' => $batchNumber - 1,
      'error_count' => count($errors),
      'errors' => $errors,
      'duration' => $totalDuration,
      'batch_size' => $batchSize,
      'last_processed_id' => $lastProcessedId,
      'completed_at' => now()->toDateTimeString(),
    ];

    return $result;
  }

  /**
   * Set the initial reset time for a new user.
   */
  public function setInitialResetTime(User $user): void
  {
    if ($user->next_reset_at !== null) {
      return;
    }

    $nextResetTime = $this->calculateNextResetTime($user);

    if ($nextResetTime) {
      $user->update(['next_reset_at' => $nextResetTime->timestamp]);
    }
  }

  /**
   * Get the user's traffic reset history.
   */
  public function getUserResetHistory(User $user, int $limit = 10): \Illuminate\Database\Eloquent\Collection
  {
    return $user->trafficResetLogs()
      ->orderBy('reset_time', 'desc')
      ->limit($limit)
      ->get();
  }

  /**
   * Check if the user is eligible for traffic reset.
   */
  public function canReset(User $user): bool
  {
    return $user->isActive() && $user->plan !== null;
  }

  /**
   * Manually reset a user's traffic (Admin function).
   */
  public function manualReset(User $user, array $metadata = []): bool
  {
    if (!$this->canReset($user)) {
      return false;
    }

    return $this->performReset($user, TrafficResetLog::SOURCE_MANUAL);
  }
}
