<?php

namespace App\Observers;

use App\Jobs\NodeUserSyncJob;
use App\Models\User;
use App\Services\TrafficResetService;

class UserObserver
{
  public function __construct(
    private readonly TrafficResetService $trafficResetService
  ) {
  }

  public function updated(User $user): void
  {
    // 当 plan_id 或 expired_at 发生变化时按月度规则重算 next_reset_at —— 但前提是
    // 调用方**没有自己显式设置** next_reset_at。
    //
    // 反例（曾真实发生）：AdvanceCycleService::advance() 在同一次 save() 里
    // forceFill([expired_at=新值, next_reset_at=min(now+30d, 新值)])。若 Observer
    // 检测到 expired_at dirty 就盲目重算，会用月度锚点静默覆盖 advance 算的"30 天后"值。
    // 后果：用户既减了到期日 30 天又能在被覆盖的 next_reset_at 到来时被 cron
    // 重置流量，等价免费获得整月流量配额，构成套利。
    //
    // 守卫语义：「调用方已显式写入 next_reset_at → 尊重调用方意图，不要覆盖」。
    // 当前全仓只有 AdvanceCycleService 同时 dirty expired_at + next_reset_at；
    // 其它路径（OrderService 续费/新购、Admin 改 expired_at、GiftCard 加 expire_days）
    // 都只动 expired_at 不动 next_reset_at，重算行为不受影响。
    //
    // 未来如有新增"合成写 expired_at + next_reset_at + save()"需求，请审视是否真的
    // 需要绕过 Observer，必要时通过 TrafficResetService 的显式 API 推动，不要在此处放宽守卫。
    if ($user->isDirty(['plan_id', 'expired_at']) && !$user->isDirty('next_reset_at')) {
      $this->recalculateNextResetAt($user);
    }

    // NodeUserSyncJob 派发独立分支，不受上面守卫影响 —— 节点端依赖
    // expired_at / transfer_enable / banned 等字段变化做下发，必须照常 dispatch。
    if ($user->isDirty(['group_id', 'uuid', 'speed_limit', 'device_limit', 'banned', 'expired_at', 'transfer_enable', 'u', 'd', 'plan_id'])) {
      $oldGroupId = $user->isDirty('group_id') ? $user->getOriginal('group_id') : null;
      NodeUserSyncJob::dispatch($user->id, 'updated', $oldGroupId);
    }
  }

  public function created(User $user): void
  {
    $this->recalculateNextResetAt($user);
    NodeUserSyncJob::dispatch($user->id, 'created');
  }

  public function deleted(User $user): void
  {
    if ($user->group_id) {
      NodeUserSyncJob::dispatch($user->id, 'deleted', $user->group_id);
    }
  }

  /**
   * 根据当前用户状态重新计算 next_reset_at
   */
  private function recalculateNextResetAt(User $user): void
  {
    $user->refresh();
    User::withoutEvents(function () use ($user) {
      $nextResetTime = $this->trafficResetService->calculateNextResetTime($user);
      $user->next_reset_at = $nextResetTime?->timestamp;
      $user->save();
    });
  }
}