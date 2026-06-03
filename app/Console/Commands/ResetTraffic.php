<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\TrafficResetLog;
use App\Services\TrafficResetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ResetTraffic extends Command
{
  protected $signature = 'reset:traffic {--fix-null : 修正模式，重新计算next_reset_at为null的用户} {--force : 强制模式，重新计算所有用户的重置时间}';

  protected $description = '流量重置 - 处理所有需要重置的用户';

  public function __construct(
    private readonly TrafficResetService $trafficResetService
  ) {
    parent::__construct();
  }

  public function handle(): int
  {
    $fixNull = $this->option('fix-null');
    $force = $this->option('force');

    $this->info('🚀 开始执行流量重置任务...');

    if ($fixNull) {
      $this->warn('🔧 修正模式 - 将重新计算next_reset_at为null的用户');
    } elseif ($force) {
      $this->warn('⚡ 强制模式 - 将重新计算所有用户的重置时间');
    }

    try {
      $result = $fixNull ? $this->performFix() : ($force ? $this->performForce() : $this->performReset());
      $this->displayResults($result, $fixNull || $force);
      return self::SUCCESS;

    } catch (\Exception $e) {
      $this->error("❌ 任务执行失败: {$e->getMessage()}");

      Log::error('流量重置命令执行失败', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
      ]);

      return self::FAILURE;
    }
  }

  private function displayResults(array $result, bool $isSpecialMode): void
  {
    $this->info("✅ 任务完成！\n");

    if ($isSpecialMode) {
      $this->displayFixResults($result);
    } else {
      $this->displayExecutionResults($result);
    }
  }

  private function displayFixResults(array $result): void
  {
    $this->info("📊 修正结果统计:");
    $this->info("🔍 发现用户总数: {$result['total_found']}");
    $this->info("✅ 成功修正数量: {$result['total_fixed']}");
    $this->info("⏱️  总执行时间: {$result['duration']} 秒");

    if ($result['error_count'] > 0) {
      $this->warn("⚠️  错误数量: {$result['error_count']}");
      $this->warn("详细错误信息请查看日志");
    } else {
      $this->info("✨ 无错误发生");
    }

    if ($result['total_found'] > 0) {
      $avgTime = round($result['duration'] / $result['total_found'], 4);
      $this->info("⚡ 平均处理速度: {$avgTime} 秒/用户");
    }
  }



  private function displayExecutionResults(array $result): void
  {
    $this->info("📊 执行结果统计:");
    $this->info("👥 处理用户总数: {$result['total_processed']}");
    $this->info("🔄 重置用户数量: {$result['total_reset']}");
    $this->info("⏱️  总执行时间: {$result['duration']} 秒");

    if ($result['error_count'] > 0) {
      $this->warn("⚠️  错误数量: {$result['error_count']}");
      $this->warn("详细错误信息请查看日志");
    } else {
      $this->info("✨ 无错误发生");
    }

    if ($result['total_processed'] > 0) {
      $avgTime = round($result['duration'] / $result['total_processed'], 4);
      $this->info("⚡ 平均处理速度: {$avgTime} 秒/用户");
    }
  }

  private function performReset(): array
  {
    $startTime = microtime(true);
    $totalResetCount = 0;
    $totalProcessed = 0;
    $errorCount = 0;

    // 改 chunkById：原 ->get() 会把全表待重置用户一次性拉到内存。
    // 用户表 20w+ 时单次 cron 直接 OOM；withoutOverlapping(10) 还会让下一次 cron 静默跳过。
    // 同时 with('plan:...') 避免 checkAndReset 内部对 plan 的 N+1 懒加载。
    $this->getResetQuery()
      ->with('plan:id,name,reset_traffic_method')
      ->orderBy('id')
      ->chunkById(500, function ($users) use (&$totalResetCount, &$totalProcessed, &$errorCount) {
        foreach ($users as $user) {
          $totalProcessed++;
          try {
            $totalResetCount += (int) $this->trafficResetService->checkAndReset($user, TrafficResetLog::SOURCE_CRON);
          } catch (\Exception $e) {
            $errorCount++;
            Log::error('用户流量重置失败', [
              'user_id' => $user->id,
              'error' => $e->getMessage(),
            ]);
          }
        }
      });

    if ($totalProcessed === 0) {
      $this->info("😴 当前没有需要重置的用户");
    } else {
      $this->info("找到 {$totalProcessed} 个需要重置的用户");
    }

    return [
      'total_processed' => $totalProcessed,
      'total_reset' => $totalResetCount,
      'error_count' => $errorCount,
      'duration' => round(microtime(true) - $startTime, 2),
    ];
  }

  private function performFix(): array
  {
    return $this->chunkedRecalculate(
      query: $this->getNullResetTimeQuery(),
      emptyMsg: '✅ 没有发现next_reset_at为null的用户',
      foundFmtFn: fn(int $n) => "🔧 发现 {$n} 个next_reset_at为null的用户，开始修正...",
      errorFmt: '修正用户next_reset_at失败',
      startTime: microtime(true),
    );
  }

  private function performForce(): array
  {
    return $this->chunkedRecalculate(
      query: $this->getAllUsersQuery(),
      emptyMsg: '✅ 没有发现需要处理的用户',
      foundFmtFn: fn(int $n) => "⚡ 发现 {$n} 个用户，开始重新计算重置时间...",
      errorFmt: '强制重新计算用户next_reset_at失败',
      startTime: microtime(true),
    );
  }

  /**
   * 共享的"重新计算 next_reset_at"主循环。
   * 原 performFix / performForce 各自 ->get() 全表，外加完全重复的 foreach。
   * 这里抽成 chunkById(500)，对 20w+ 用户表也不会 OOM。
   */
  private function chunkedRecalculate(
    \Illuminate\Database\Eloquent\Builder $query,
    string $emptyMsg,
    callable $foundFmtFn,
    string $errorFmt,
    float $startTime,
  ): array {
    $totalFound = 0;
    $fixedCount = 0;
    $errorCount = 0;

    $query->orderBy('id')->chunkById(500, function ($users) use (&$totalFound, &$fixedCount, &$errorCount, $errorFmt) {
      foreach ($users as $user) {
        $totalFound++;
        try {
          $nextResetTime = $this->trafficResetService->calculateNextResetTime($user);
          if ($nextResetTime) {
            $user->next_reset_at = $nextResetTime->timestamp;
            $user->save();
            $fixedCount++;
          }
        } catch (\Exception $e) {
          $errorCount++;
          Log::error($errorFmt, [
            'user_id' => $user->id,
            'error' => $e->getMessage(),
          ]);
        }
      }
    });

    $this->info($totalFound === 0 ? $emptyMsg : $foundFmtFn($totalFound));

    return [
      'total_found' => $totalFound,
      'total_fixed' => $fixedCount,
      'error_count' => $errorCount,
      'duration' => round(microtime(true) - $startTime, 2),
    ];
  }



  private function getResetQuery()
  {
    return User::where('next_reset_at', '<=', time())
      ->whereNotNull('next_reset_at')
      ->where(function ($query) {
        $query->where('expired_at', '>', time())
          ->orWhereNull('expired_at');
      })
      ->where('banned', 0)
      ->whereNotNull('plan_id');
  }



  private function getNullResetTimeQuery(): \Illuminate\Database\Eloquent\Builder
  {
    return User::whereNull('next_reset_at')
      ->whereNotNull('plan_id')
      ->where(function ($query) {
        $query->where('expired_at', '>', time())
          ->orWhereNull('expired_at');
      })
      ->where('banned', 0)
      ->with('plan:id,name,reset_traffic_method');
  }

  private function getAllUsersQuery(): \Illuminate\Database\Eloquent\Builder
  {
    return User::whereNotNull('plan_id')
      ->where(function ($query) {
        $query->where('expired_at', '>', time())
          ->orWhereNull('expired_at');
      })
      ->where('banned', 0)
      ->with('plan:id,name,reset_traffic_method');
  }

}