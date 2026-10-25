<?php

namespace App\Services\Recharge;

/**
 * 余额充值配置快照。
 *
 * 键都在 v2_settings（admin_setting）；后台展示 / 保存、用户端公共配置、下单校验共用这一份定义。
 * 金额在设置里按「元」存（后台表单与前端展示都是元），进出业务逻辑一律换成「分」。
 */
final class RechargeConfig
{
    /** 赠送档位最多几档 */
    public const MAX_TIERS = 20;
    /** 快捷金额最多几个（前端一屏放 6 个，多给几个让站长挑） */
    public const MAX_QUICK_AMOUNTS = 12;
    /** 单笔金额硬上限（元）：pay_amount 是 int 列，再大就溢出了 */
    public const HARD_MAX_AMOUNT = 1000000;

    public const DEFAULTS = [
        'recharge_enable' => 0,
        'recharge_min_amount' => 1,
        'recharge_max_amount' => 99999,
        'recharge_bonus_tiers' => [],
        'recharge_quick_amounts' => [],
    ];

    /**
     * @param list<array{amount:int,bonus:int}> $tiers        按门槛升序，单位分
     * @param list<int>                         $quickAmounts 升序去重，单位分
     */
    public function __construct(
        public readonly bool $enable,
        public readonly int $minAmount,
        public readonly int $maxAmount,
        public readonly array $tiers,
        public readonly array $quickAmounts,
    ) {
    }

    public static function fromSettings(): self
    {
        $get = static fn (string $key) => admin_setting($key, self::DEFAULTS[$key]);

        $min = max(1, self::toCents($get('recharge_min_amount')) ?? 100);
        $max = min(self::HARD_MAX_AMOUNT * 100, self::toCents($get('recharge_max_amount')) ?? 9999900);
        if ($max < $min) {
            $max = $min;
        }

        return new self(
            enable: (bool) (int) $get('recharge_enable'),
            minAmount: $min,
            maxAmount: $max,
            tiers: self::normalizeTiers($get('recharge_bonus_tiers')),
            quickAmounts: self::normalizeQuickAmounts($get('recharge_quick_amounts'), $min, $max),
        );
    }

    /** 赠送额：满足门槛的档位里取赠送最多的一档（与前端预览口径一致） */
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

    /** 后台「系统设置 → 余额充值」的回显，金额换回元 */
    public function toAdminArray(): array
    {
        return [
            'recharge_enable' => $this->enable,
            'recharge_min_amount' => $this->minAmount / 100,
            'recharge_max_amount' => $this->maxAmount / 100,
            'recharge_bonus_tiers' => array_map(fn (array $t) => [
                'amount' => $t['amount'] / 100,
                'bonus' => $t['bonus'] / 100,
            ], $this->tiers),
            'recharge_quick_amounts' => array_map(fn (int $a) => $a / 100, $this->quickAmounts),
        ];
    }

    /**
     * 用户端 /guest/comm/config 里的字段。键名沿用插件时代（前端按这些键判断「站点支持充值」），
     * 金额是元；balance_recharge_min / max 以前被 (int) 截成整数元，这里保留两位小数。
     */
    public function toGuestArray(): array
    {
        return [
            'balance_recharge_enable' => $this->enable,
            'balance_recharge_min' => $this->minAmount / 100,
            'balance_recharge_max' => $this->maxAmount / 100,
            'deposit_bonus_tiers' => array_map(fn (array $t) => [
                'amount' => $t['amount'] / 100,
                'bonus' => $t['bonus'] / 100,
            ], $this->tiers),
            'balance_recharge_quick_amounts' => array_map(fn (int $a) => $a / 100, $this->quickAmounts),
        ];
    }

    /**
     * 「元」→「分」。只认最多两位小数的非负数，其余返回 null。
     * 不用 (int) ($yuan * 100)：0.29 * 100 = 28.999… 会被截成 28。
     */
    public static function toCents(mixed $yuan): ?int
    {
        if (is_int($yuan)) {
            return $yuan >= 0 ? $yuan * 100 : null;
        }
        if (is_float($yuan)) {
            $yuan = rtrim(rtrim(number_format($yuan, 2, '.', ''), '0'), '.');
        }
        if (!is_string($yuan) || !preg_match('/^(0|[1-9]\d{0,9})(?:\.(\d{1,2}))?$/D', trim($yuan), $m)) {
            return null;
        }
        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    /**
     * @return list<array{amount:int,bonus:int}>
     */
    public static function normalizeTiers(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }
        $byAmount = [];
        foreach ($raw as $tier) {
            if (!is_array($tier)) {
                continue;
            }
            $amount = self::toCents($tier['amount'] ?? null);
            $bonus = self::toCents($tier['bonus'] ?? null);
            if (!$amount || !$bonus) {
                continue;
            }
            // 同一门槛写了两档，留赠送多的那档
            $byAmount[$amount] = max($byAmount[$amount] ?? 0, $bonus);
        }
        ksort($byAmount);
        $tiers = [];
        foreach (array_slice($byAmount, 0, self::MAX_TIERS, true) as $amount => $bonus) {
            $tiers[] = ['amount' => (int) $amount, 'bonus' => (int) $bonus];
        }
        return $tiers;
    }

    /**
     * @return list<int>
     */
    public static function normalizeQuickAmounts(mixed $raw, int $min, int $max): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }
        $amounts = [];
        foreach ($raw as $value) {
            $cents = self::toCents($value);
            if ($cents !== null && $cents >= $min && $cents <= $max) {
                $amounts[$cents] = $cents;
            }
        }
        sort($amounts);
        return array_slice(array_values($amounts), 0, self::MAX_QUICK_AMOUNTS);
    }
}
