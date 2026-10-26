<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * 套餐席位：剩余 = capacity_limit − 该套餐当前占位的用户（未到期或不限期），capacity_limit 为空 = 不限席位。
 *
 * 规则与 PlanService::hasCapacity 完全一致（前台套餐列表、下单校验都用那一个，PlanCapacityTest 对拍），
 * 这里是给邮件推荐、到货提醒这类一次要看很多套餐的地方用的批量版：只查一次库。
 * 下单 / 续费的判断仍走 PlanService，这个类不参与。
 *
 * 剩余席位只能用于内部判断，不能出现在邮件、页面等任何用户看得到的地方：对外只说有货 / 售罄。
 */
final class PlanCapacity
{
    /**
     * 各套餐剩余席位，plan_id => 剩余（不小于 0）；不限席位的为 null。
     *
     * @param iterable<Plan> $plans
     * @return array<int, int|null>
     */
    public static function remaining(iterable $plans): array
    {
        $limits = [];
        foreach ($plans as $plan) {
            $limits[(int) $plan->id] = $plan->capacity_limit === null ? null : (int) $plan->capacity_limit;
        }
        $limited = array_keys(array_filter($limits, fn ($limit) => $limit !== null));
        $occupied = $limited ? User::whereIn('plan_id', $limited)
            ->where(function ($query) {
                $query->where('expired_at', '>=', time())
                    ->orWhereNull('expired_at');
            })
            ->groupBy('plan_id')
            ->selectRaw('plan_id, COUNT(*) AS occupied')
            ->pluck('occupied', 'plan_id')
            ->all() : [];

        $remaining = [];
        foreach ($limits as $id => $limit) {
            $remaining[$id] = $limit === null ? null : max(0, $limit - (int) ($occupied[$id] ?? 0));
        }
        return $remaining;
    }

    /**
     * 只留还买得到的套餐（不限席位，或还有空位），保持原顺序、重排键。
     *
     * @param Collection<int, Plan> $plans
     * @return Collection<int, Plan>
     */
    public static function available(Collection $plans): Collection
    {
        $remaining = self::remaining($plans);
        return $plans->filter(fn (Plan $plan) => ($remaining[(int) $plan->id] ?? null) !== 0)->values();
    }
}
