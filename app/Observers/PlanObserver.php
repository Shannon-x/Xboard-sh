<?php

namespace App\Observers;

use App\Models\Plan;
use App\Models\User;
use App\Services\PlanCustomizationService;
use App\Services\TrafficResetService;

class PlanObserver
{
    /**
     * 套餐保存/删除后立即失效「在售增值组」缓存：节点端拉名单靠它决定是否查 granted_groups，
     * 管理员刚把某组配成增值组，下一次 pull 就要能把买家放进名单，不能等 60 秒 TTL。
     */
    public function saved(Plan $plan): void
    {
        if ($plan->wasChanged('customization') || $plan->wasRecentlyCreated) {
            PlanCustomizationService::forgetAddonCaches();
        }
    }

    public function deleted(Plan $plan): void
    {
        PlanCustomizationService::forgetAddonCaches();
    }

    /**
     * reset user  next_reset_at
     */
    public function updated(Plan $plan): void
    {
        if (!$plan->isDirty('reset_traffic_method')) {
            return;
        }
        $trafficResetService = app(TrafficResetService::class);
        User::where('plan_id', $plan->id)
            ->where('banned', 0)
            ->where(function ($query) {
                $query->where('expired_at', '>', time())
                    ->orWhereNull('expired_at');
            })
            ->lazyById(500)
            ->each(function (User $user) use ($trafficResetService) {
                $nextResetTime = $trafficResetService->calculateNextResetTime($user);
                $user->update([
                    'next_reset_at' => $nextResetTime?->timestamp,
                ]);
            });
    }
}

