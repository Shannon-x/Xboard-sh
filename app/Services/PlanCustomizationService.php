<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;

/** Quotes and order creation share this calculator. All public amounts are cents. */
class PlanCustomizationService
{
    public const LIMITS = ['transfer_enable' => 1000000, 'device_limit' => 100, 'speed_limit' => 10000];
    public const MAX_AMOUNT = 100000000;
    public const MAX_CHOICES = 100;

    public function mode(Plan $plan, string $field): string
    {
        $rule = $plan->customization[$field] ?? null;
        if (!$rule) return 'fixed';
        // The first version used max == base to express a fixed resource.
        return $rule['mode'] ?? (($rule['max'] ?? null) == $plan->{$field} ? 'fixed' : 'range');
    }

    public function isEnabled(Plan $plan): bool
    {
        foreach (array_keys(self::LIMITS) as $field) {
            $mode = $this->mode($plan, $field);
            if ($mode === 'range' && ($plan->customization[$field]['max'] ?? 0) > $plan->{$field}) return true;
            if ($mode === 'choices' && count($plan->customization[$field]['choices'] ?? []) > 1) return true;
        }
        return false;
    }

    public function validateConfiguration(Plan $plan): void
    {
        $config = $plan->customization;
        if ($config === null) return;
        if (!is_array($config) || array_diff(array_keys($config), array_keys(self::LIMITS)) || count($config) !== count(self::LIMITS)) {
            throw new ApiException('请为流量、设备数和速度分别设置固定值或自选规则');
        }
        foreach (self::LIMITS as $field => $limit) {
            $base = $plan->{$field};
            $rule = $config[$field];
            if (!is_array($rule) || array_diff(array_keys($rule), ['mode', 'max', 'step', 'price_per_step', 'choices'])) {
                throw new ApiException('自选规格配置包含未知字段');
            }
            if (array_key_exists('mode', $rule) && !is_string($rule['mode'])) {
                throw new ApiException('规格模式格式错误');
            }
            $mode = $this->mode($plan, $field);
            if (!in_array($mode, ['fixed', 'range', 'choices'], true)) {
                throw new ApiException('规格模式必须是固定、范围或指定选项');
            }
            // Fixed resources retain the existing plan's values, including null/0 (unlimited).
            if ($mode === 'fixed') continue;
            if (!is_numeric($base) || (int) $base != $base || $base < 1 || $base > $limit) {
                throw new ApiException('开放自选的规格必须设置有限的正整数基础值');
            }
            foreach (['max', 'step', 'price_per_step'] as $key) {
                if (!isset($rule[$key]) || !is_int($rule[$key])) {
                    throw new ApiException('规格上限、计价单位和加价（分）必须为整数');
                }
            }
            if ($rule['max'] < $base || $rule['max'] > $limit || $rule['step'] < 1 || $rule['step'] > $limit
                || $rule['price_per_step'] < 0 || $rule['price_per_step'] > self::MAX_AMOUNT) {
                throw new ApiException('规格上限或加价超出允许范围');
            }
            if ($mode === 'range' && ($rule['max'] - $base) % $rule['step'] !== 0) {
                throw new ApiException('范围上限必须能按步长从基础值到达');
            }
            if ($mode === 'choices') {
                $choices = $rule['choices'] ?? null;
                if (!is_array($choices) || !array_is_list($choices) || count($choices) < 1 || count($choices) > self::MAX_CHOICES) {
                    throw new ApiException('指定选项需要 1 至 100 个容量或规格值');
                }
                $previous = 0;
                foreach ($choices as $value) {
                    if (!is_int($value) || $value < $base || $value > $limit || $value <= $previous) {
                        throw new ApiException('指定选项必须是递增、不重复且不低于基础值的有限正整数');
                    }
                    $previous = $value;
                }
                if ($choices[0] !== (int) $base || end($choices) !== $rule['max']) {
                    throw new ApiException('指定选项必须包含基础规格，上限必须等于最大选项');
                }
            }
        }
        // All fixed: preserve legacy period availability, prices and reset policies.
        if (!$this->isEnabled($plan)) return;
        $prices = array_filter($plan->prices ?? [], fn ($price) => $price !== null && $price !== '');
        if (array_diff(array_keys($prices), array_keys(Plan::getAvailablePeriods()))) {
            throw new ApiException('自选套餐包含不支持的付款周期');
        }
        $reference = $prices[Plan::PERIOD_MONTHLY] ?? $prices[Plan::PERIOD_ONETIME] ?? null;
        if (!is_numeric($reference) || $reference <= 0) {
            throw new ApiException('自选套餐需要正数月付基础价或一次性基础价');
        }
        if (isset($prices[Plan::PERIOD_ONETIME]) && count(array_diff(array_keys($prices), [Plan::PERIOD_ONETIME])) > 0) {
            throw new ApiException('不限时流量包与周期套餐请分开设置');
        }
        if (isset($prices[Plan::PERIOD_ONETIME]) && $plan->reset_traffic_method !== Plan::RESET_TRAFFIC_NEVER) {
            throw new ApiException('不限时自选套餐的流量重置方式必须是不重置');
        }
        $maxOptions = $this->baseOptions($plan);
        foreach ($maxOptions as $field => $base) {
            if ($this->mode($plan, $field) !== 'fixed') $maxOptions[$field] = $config[$field]['max'];
        }
        foreach ($prices as $period => $price) {
            if (!is_numeric($price) || !is_finite((float) $price) || $price <= 0) {
                throw new ApiException('自选套餐的周期基础价必须为有限正数');
            }
            $this->calculate($plan, $period, $maxOptions);
        }
    }

    public function quote(Plan $plan, string $period, ?array $options = null, ?User $user = null): array
    {
        $period = PlanService::getPeriodKey($period);
        if (!isset(($plan->prices ?? [])[$period])) {
            throw new ApiException('此套餐不支持所选付款周期');
        }
        $this->validateConfiguration($plan);
        $current = $user && (int) $user->plan_id === (int) $plan->id ? $user->plan_options : null;
        if (!$this->isEnabled($plan)) {
            if ($options !== null) $this->validateSelection($plan, $options);
            if ($current) $this->validateSelection($plan, $current);
            // Keep the legacy conversion exactly; no custom snapshot/payment restrictions.
            return ['period' => $period, 'amount' => (int) ($plan->prices[$period] * 100),
                'options' => null, 'breakdown' => [], 'snapshot' => null];
        }
        if ($period === Plan::PERIOD_RESET_TRAFFIC) {
            if (!$user || (int) $user->plan_id !== (int) $plan->id) {
                throw new ApiException('流量重置仅可用于当前订阅');
            }
            if ($options !== null && $options != ($current ?? $this->baseOptions($plan))) {
                throw new ApiException('流量重置不能更改套餐规格');
            }
            $options = $current;
        } elseif ($options === null && $current) {
            $options = $current;
        }
        $selected = $options ?? $this->baseOptions($plan);
        $this->validateSelection($plan, $selected);
        $quote = $this->calculate($plan, $period, $selected);
        return $quote + [
            'period' => $period, 'options' => $selected,
            'snapshot' => [
                'version' => 1, 'name' => $plan->name, 'group_id' => $plan->group_id,
                'reset_traffic_method' => $plan->reset_traffic_method,
                'options' => $selected, 'amount' => $quote['amount'], 'breakdown' => $quote['breakdown'],
            ],
        ];
    }

    public function baseOptions(Plan $plan): array
    {
        return array_combine(array_keys(self::LIMITS), array_map(
            fn ($field) => $plan->{$field} === null ? null : (int) $plan->{$field}, array_keys(self::LIMITS)
        ));
    }

    /** Older clients show and renew the purchased configuration using the existing plan fields. */
    public function forLegacyUser(Plan $plan, User $user): Plan
    {
        if ((int) $user->plan_id !== (int) $plan->id || !$user->plan_options) return $plan;
        $result = clone $plan;
        $prices = $plan->prices ?? [];
        foreach ($prices as $period => $price) {
            if ($price !== null) $prices[$period] = $this->quote($plan, $period, null, $user)['amount'] / 100;
        }
        $result->forceFill($user->plan_options + ['prices' => $prices, 'customization' => null]);
        return $result;
    }

    private function validateSelection(Plan $plan, array $selected): void
    {
        if (array_diff(array_keys($selected), array_keys(self::LIMITS)) || count($selected) !== count(self::LIMITS)) {
            throw new ApiException('请完整选择流量、设备数和速度');
        }
        foreach ($this->baseOptions($plan) as $field => $base) {
            $value = $selected[$field];
            $mode = $this->mode($plan, $field);
            if ($mode === 'fixed') {
                if ($value !== $base) throw new ApiException('固定规格不可更改，请重新选择套餐');
                continue;
            }
            $rule = $plan->customization[$field];
            if (!is_int($value) || $value < $base || $value > $rule['max']
                || ($mode === 'range' && ($value - $base) % $rule['step'] !== 0)
                || ($mode === 'choices' && !in_array($value, $rule['choices'], true))) {
                throw new ApiException('所选规格不在可售选项内，请重新选择');
            }
        }
    }

    public function validateExistingSubscribers(Plan $plan): void
    {
        $selections = User::where('plan_id', $plan->id)->whereNotNull('plan_options')->cursor()
            ->map(fn ($user) => $user->plan_options)
            ->concat(Order::where('plan_id', $plan->id)->whereNotNull('plan_snapshot')
                ->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_PROCESSING])->cursor()
                ->map(fn ($order) => $order->plan_snapshot['options']));
        foreach ($selections as $options) {
            try {
                $this->validateSelection($plan, $options);
            } catch (ApiException $e) {
                throw new ApiException('新规格会使已有用户或待处理订单无法续费，请保留其规格或新建套餐');
            }
        }
    }

    private function calculate(Plan $plan, string $period, array $options): array
    {
        $baseAmount = (int) round($plan->prices[$period] * 100);
        $reference = (int) round(($plan->prices[Plan::PERIOD_MONTHLY] ?? $plan->prices[Plan::PERIOD_ONETIME]) * 100);
        if ($reference < 1 || $baseAmount < 1 || $baseAmount > self::MAX_AMOUNT) {
            throw new ApiException('套餐价格超出允许范围');
        }
        $breakdown = ['base' => $baseAmount];
        foreach (self::LIMITS as $field => $limit) {
            if ($this->mode($plan, $field) === 'fixed' || ($period === Plan::PERIOD_RESET_TRAFFIC && $field !== 'transfer_enable')) {
                $breakdown[$field] = 0;
                continue;
            }
            $rule = $plan->customization[$field];
            // Discrete choices can use fractional pricing units, e.g. 250 GB at a per-100-GB rate.
            $units = ($options[$field] - (int) $plan->{$field}) / $rule['step'];
            $amount = round($units * $rule['price_per_step'] * ($baseAmount / $reference));
            if (!is_finite((float) $amount) || $amount > self::MAX_AMOUNT) {
                throw new ApiException('所选规格总价超过允许上限');
            }
            $breakdown[$field] = (int) $amount;
        }
        $total = array_sum($breakdown);
        if ($total > self::MAX_AMOUNT) throw new ApiException('所选规格总价超过允许上限');
        return ['amount' => $total, 'breakdown' => $breakdown];
    }
}
