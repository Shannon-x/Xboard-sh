<?php

namespace App\Services\Payg;

use App\Models\Plan;
use App\Services\Recharge\RechargeConfig;

/**
 * 上网按量（按量钱包）配置快照。
 *
 * 钱包背后就是 GB：按量套餐是一个一次性周期的普通套餐，买到的流量进 transfer_enable，节点照常按倍率扣；
 * 「按量余额 ¥」只是「剩余 GB × 单价」换算出来给用户看的，没有第二个账本。
 * 单价 = 套餐一次性价 ÷ 套餐基础流量（¥15 / 100 GB = 15 分/GB）：改价只改套餐，显示跟着变。
 *
 * 哪些套餐是按量套餐只由站点设置 payg_plan_ids 认定，不看标签 —— 认定之后再买会叠加流量、
 * 有使用的月份会被收保底；标签谁都能改，误标一个包月套餐就会被收保底。
 * payg_plan_ids 为空 = 整个功能关闭，下单 / 开通 / 套餐列表与上线前逐字相同。
 *
 * 金额设置按「元」存（与余额充值同一习惯），业务逻辑里一律换成「分」。
 */
final class PaygConfig
{
    public const BYTES_PER_GB = 1073741824;
    /** 充值赠送档位最多几档 */
    public const MAX_TIERS = 10;

    public const DEFAULTS = [
        'payg_plan_ids' => [],
        // 有使用的月份最低扣多少（元）；0 = 不设保底
        'payg_monthly_floor' => 5,
        // 首次充值送多少 GB（每个用户一次）；0 = 不送
        'payg_first_bonus_gb' => 10,
        // 充值赠送：本单折前金额满 amount 元送 bonus 元（按单价换成 GB 加进余额），取满足门槛里赠送最多的一档
        'payg_bonus_tiers' => [['amount' => 100, 'bonus' => 10], ['amount' => 300, 'bonus' => 45]],
        // 自动补充：站点总开关 + 余额低于多少元时从账户余额买一份（套餐一次性价）
        'payg_auto_topup_enable' => 1,
        'payg_auto_topup_threshold' => 3,
    ];

    /**
     * @param list<int>                         $planIds
     * @param list<array{amount:int,bonus:int}> $tiers 按门槛升序，单位分
     */
    public function __construct(
        public readonly array $planIds,
        public readonly int $monthlyFloor,
        public readonly int $firstBonusGb,
        public readonly array $tiers,
        public readonly bool $autoTopupEnable,
        public readonly int $autoTopupThreshold,
    ) {
    }

    public static function fromSettings(): self
    {
        $get = static fn (string $key) => admin_setting($key, self::DEFAULTS[$key]);

        return new self(
            planIds: self::normalizePlanIds($get('payg_plan_ids')),
            monthlyFloor: RechargeConfig::toCents($get('payg_monthly_floor')) ?? 0,
            firstBonusGb: max(0, min(10000, (int) $get('payg_first_bonus_gb'))),
            tiers: array_slice(RechargeConfig::normalizeTiers($get('payg_bonus_tiers')), 0, self::MAX_TIERS),
            autoTopupEnable: (bool) (int) $get('payg_auto_topup_enable'),
            autoTopupThreshold: RechargeConfig::toCents($get('payg_auto_topup_threshold')) ?? 0,
        );
    }

    public function enabled(): bool
    {
        return $this->planIds !== [];
    }

    public function isPaygPlanId(int|string|null $planId): bool
    {
        return $planId !== null && in_array((int) $planId, $this->planIds, true);
    }

    /**
     * 每 GB 单价（分，可能带小数）= 一次性价 ÷ 基础流量。套餐没配一次性价或流量为 0 时返回 null：
     * 这种套餐即使被列进 payg_plan_ids 也不按按量处理（没法换算）。
     */
    public static function unitPrice(Plan $plan): ?float
    {
        $price = $plan->prices[Plan::PERIOD_ONETIME] ?? null;
        $gb = (int) $plan->transfer_enable;
        if (!is_numeric($price) || (float) $price <= 0 || $gb < 1) {
            return null;
        }
        return round((float) $price * 100 / $gb, 4);
    }

    /** 一次性价（分）：最低充值额，也是自动补充每次买的那一份。 */
    public static function baseAmount(Plan $plan): int
    {
        return (int) round((float) ($plan->prices[Plan::PERIOD_ONETIME] ?? 0) * 100);
    }

    /** 金额（分）→ 字节，向下取整：赠送 / 补扣都不多给一个字节。 */
    public static function bytesFor(float $unitPrice, int $cents): int
    {
        if ($unitPrice <= 0 || $cents <= 0) {
            return 0;
        }
        return (int) floor($cents * self::BYTES_PER_GB / $unitPrice);
    }

    /** 字节 → 金额（分），四舍五入：只用于展示（本月已扣、保底差额）。 */
    public static function centsFor(float $unitPrice, int $bytes): int
    {
        if ($unitPrice <= 0 || $bytes <= 0) {
            return 0;
        }
        return (int) round($bytes * $unitPrice / self::BYTES_PER_GB);
    }

    /** 本单赠送（分）：满足门槛的档位里取赠送最多的一档（与余额充值同一口径）。 */
    public function bonusFor(int $amount): int
    {
        $best = 0;
        foreach ($this->tiers as $tier) {
            if ($amount >= $tier['amount'] && $tier['bonus'] > $best) {
                $best = $tier['bonus'];
            }
        }
        return $best;
    }

    /** 后台「系统设置 → 按量计费」的回显，金额换回元 */
    public function toAdminArray(): array
    {
        return [
            'payg_plan_ids' => $this->planIds,
            'payg_monthly_floor' => $this->monthlyFloor / 100,
            'payg_first_bonus_gb' => $this->firstBonusGb,
            'payg_bonus_tiers' => array_map(fn (array $t) => [
                'amount' => $t['amount'] / 100,
                'bonus' => $t['bonus'] / 100,
            ], $this->tiers),
            'payg_auto_topup_enable' => $this->autoTopupEnable,
            'payg_auto_topup_threshold' => $this->autoTopupThreshold / 100,
        ];
    }

    /** @return list<int> */
    public static function normalizePlanIds(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : preg_split('/[\s,，]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (is_int($raw)) {
            $raw = [$raw];
        }
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $id) {
            if (is_numeric($id) && (int) $id > 0 && (int) $id == $id) {
                $ids[(int) $id] = (int) $id;
            }
        }
        sort($ids);
        return array_values($ids);
    }
}
