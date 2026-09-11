<?php

namespace App\Observers;

use App\Models\Plan;
use App\Models\User;
use App\Services\NodeSyncService;
use App\Services\PlanCustomizationService;
use App\Services\TrafficResetService;

class PlanObserver
{
    /**
     * 套餐保存/删除后立即失效增值组缓存：节点端拉名单靠它决定走哪几条分支，
     * 管理员刚把某组配成增值组，下一次 pull 就要能把买家放进名单，不能等 60 秒 TTL。
     *
     * 「包含」组是实时语义：管理员把 VIP 组加进 / 撤出某套餐，该套餐全部订阅者立刻
     * 得到 / 失去它。订阅链接按 plan_id 实时合成不用通知；但节点端名单是节点自己拉的，
     * 这里主动让变化的组所属节点重拉，订阅者不用等下一个 pull 周期。
     */
    public function saved(Plan $plan): void
    {
        if (!$plan->wasChanged('customization') && !$plan->wasRecentlyCreated) {
            return;
        }
        PlanCustomizationService::forgetAddonCaches();
        // saved 事件里 getOriginal 仍是保存前的值（syncOriginal 在事件之后才跑）。
        $before = $plan->wasRecentlyCreated ? [] : self::includedIdsOf($plan->getOriginal('customization'));
        $after = self::includedIdsOf($plan->customization);
        foreach (array_unique(array_merge(array_diff($before, $after), array_diff($after, $before))) as $groupId) {
            NodeSyncService::notifyUsersUpdatedByGroup($groupId);
        }
    }

    public function deleted(Plan $plan): void
    {
        PlanCustomizationService::forgetAddonCaches();
        foreach (self::includedIdsOf($plan->customization) as $groupId) {
            NodeSyncService::notifyUsersUpdatedByGroup($groupId);
        }
    }

    private static function includedIdsOf(mixed $customization): array
    {
        if (is_string($customization)) $customization = json_decode($customization, true);
        $ids = [];
        foreach ((is_array($customization) ? $customization : [])[PlanCustomizationService::ADDON_KEY] ?? [] as $gid => $rule) {
            if (is_numeric($gid) && is_array($rule) && ($rule['mode'] ?? null) === 'included') $ids[] = (int) $gid;
        }
        sort($ids);
        return $ids;
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

