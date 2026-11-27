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
    /** 邮件推荐套餐时占位数复用的秒数 */
    public const MAIL_CACHE_SECONDS = 60;

    /** @var array<string, array{until: int, occupied: array<int, int>}> */
    private static array $memo = [];

    /**
     * 各套餐剩余席位，plan_id => 剩余（不小于 0）；不限席位的为 null。
     *
     * @param iterable<Plan> $plans
     * @param int $cacheSeconds >0 时同一组套餐的占位数在本进程内复用这么多秒（只给邮件推荐这类批量场景用，见 MAIL_CACHE_SECONDS）
     * @return array<int, int|null>
     */
    public static function remaining(iterable $plans, int $cacheSeconds = 0): array
    {
        $limits = [];
        foreach ($plans as $plan) {
            $limits[(int) $plan->id] = $plan->capacity_limit === null ? null : (int) $plan->capacity_limit;
        }
        $limited = array_keys(array_filter($limits, fn ($limit) => $limit !== null));
        $occupied = $limited ? self::occupied($limited, $cacheSeconds) : [];

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
     * @param int $cacheSeconds 同 remaining()
     * @return Collection<int, Plan>
     */
    public static function available(Collection $plans, int $cacheSeconds = 0): Collection
    {
        $remaining = self::remaining($plans, $cacheSeconds);
        return $plans->filter(fn (Plan $plan) => ($remaining[(int) $plan->id] ?? null) !== 0)->values();
    }

    /**
     * 各套餐当前占位人数。这条 COUNT 要扫全部用户（2026-11-27 生产 4 万用户约 170 ms），而 11:30 的账单扫描
     * 一次派上千封账单，每封都为「也可以看看」重算一遍、结果完全一样 —— 当天 1,414 封账单光这条就花了约 4 分钟。
     * 批量场景传 $cacheSeconds，同一组套餐在本进程内复用；只影响邮件里推荐哪些套餐，下单校验仍走 PlanService 实时判断。
     *
     * @param int[] $planIds
     * @return array<int, int>
     */
    private static function occupied(array $planIds, int $cacheSeconds): array
    {
        sort($planIds);
        $key = implode(',', $planIds);
        $now = time();
        if ($cacheSeconds > 0 && isset(self::$memo[$key]) && self::$memo[$key]['until'] > $now) {
            return self::$memo[$key]['occupied'];
        }
        $occupied = User::whereIn('plan_id', $planIds)
            ->where(function ($query) {
                $query->where('expired_at', '>=', time())
                    ->orWhereNull('expired_at');
            })
            ->groupBy('plan_id')
            ->selectRaw('plan_id, COUNT(*) AS occupied')
            ->pluck('occupied', 'plan_id')
            ->all();
        if ($cacheSeconds > 0) {
            if (count(self::$memo) >= 32) {
                self::$memo = [];   // 常驻 worker：套餐组合换了就整个丢掉，不让它慢慢长
            }
            self::$memo[$key] = ['until' => $now + $cacheSeconds, 'occupied' => $occupied];
        }
        return $occupied;
    }

    /** 测试用：清掉进程内缓存 */
    public static function flushMemo(): void
    {
        self::$memo = [];
    }
}
