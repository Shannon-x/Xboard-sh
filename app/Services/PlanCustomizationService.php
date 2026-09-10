<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Plan;
use App\Models\Order;
use App\Models\User;

/** Quotes and order creation share this calculator. All public amounts are cents. */
class PlanCustomizationService
{
    public const LIMITS = ['transfer_enable' => 1000000, 'device_limit' => 100, 'speed_limit' => 10000];
    public const MAX_AMOUNT = 100000000; // cents; also enforced against the most expensive combination

    public function validateConfiguration(Plan $plan): void
    {
        $config = $plan->customization;
        if ($config === null) {
            return;
        }
        if (!is_array($config) || array_diff(array_keys($config), array_keys(self::LIMITS))) {
            throw new ApiException('自选规格配置包含未知字段');
        }
        foreach (self::LIMITS as $field => $limit) {
            $base = $plan->{$field};
            $rule = $config[$field] ?? null;
            if (!is_numeric($base) || (int) $base != $base || $base < 1 || $base > $limit) {
                throw new ApiException('自选套餐的基础流量、设备数和速度必须设置有限的正整数');
            }
            if (!is_array($rule) || array_diff(array_keys($rule), ['max', 'step', 'price_per_step'])) {
                throw new ApiException('每项自选规格必须设置上限、步长和加价');
            }
            foreach (['max', 'step', 'price_per_step'] as $key) {
                if (!isset($rule[$key]) || !is_int($rule[$key])) {
                    throw new ApiException('规格上限、步长和加价（分）必须为整数');
                }
            }
            if ($rule['max'] < $base || $rule['max'] > $limit || $rule['step'] < 1
                || $rule['step'] > $limit || ($rule['max'] - $base) % $rule['step'] !== 0
                || $rule['price_per_step'] < 0 || $rule['price_per_step'] > self::MAX_AMOUNT) {
                throw new ApiException('规格上限或加价不合理，上限必须能按步长从基础值到达');
            }
        }
        $prices = $plan->prices ?? [];
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
        $maxOptions = array_map(fn ($rule) => $rule['max'], $config);
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
        $current = $user && (int) $user->plan_id === (int) $plan->id ? $user->plan_options : null;
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
        if ($plan->customization === null) {
            if ($options !== null) {
                throw new ApiException('此套餐未开放自选规格，请联系管理员');
            }
            return ['period' => $period, 'amount' => (int) round($plan->prices[$period] * 100),
                'options' => null, 'breakdown' => [], 'snapshot' => null];
        }
        $this->validateConfiguration($plan);
        $selected = $options ?? $this->baseOptions($plan);
        if (array_diff(array_keys($selected), array_keys(self::LIMITS)) || count($selected) !== count(self::LIMITS)) {
            throw new ApiException('请完整选择流量、设备数和速度');
        }
        foreach (self::LIMITS as $field => $limit) {
            $value = $selected[$field] ?? null;
            $rule = $plan->customization[$field];
            $base = (int) $plan->{$field};
            if (!is_int($value) || $value < $base || $value > $rule['max'] || ($value - $base) % $rule['step'] !== 0) {
                throw new ApiException('所选规格超出可售范围或不符合步长，请重新选择');
            }
        }
        $quote = $this->calculate($plan, $period, $selected);
        return $quote + [
            'period' => $period,
            'options' => $selected,
            'snapshot' => [
                'version' => 1, 'name' => $plan->name, 'group_id' => $plan->group_id,
                'reset_traffic_method' => $plan->reset_traffic_method,
                'options' => $selected, 'amount' => $quote['amount'],
                'breakdown' => $quote['breakdown'],
            ],
        ];
    }

    public function baseOptions(Plan $plan): array
    {
        return array_combine(array_keys(self::LIMITS), array_map(fn ($field) => (int) $plan->{$field}, array_keys(self::LIMITS)));
    }

    public function validateExistingSubscribers(Plan $plan): void
    {
        $selections = User::where('plan_id', $plan->id)->whereNotNull('plan_options')->cursor()
            ->map(fn ($user) => $user->plan_options)
            ->concat(Order::where('plan_id', $plan->id)->whereNotNull('plan_snapshot')
                ->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_PROCESSING])->cursor()
                ->map(fn ($order) => $order->plan_snapshot['options']));
        foreach ($selections as $options) {
            if (!$plan->customization) {
                throw new ApiException('已有用户或待处理订单购买自选规格，不能关闭自选配置');
            }
            foreach (self::LIMITS as $field => $limit) {
                $value = $options[$field];
                $rule = $plan->customization[$field];
                $base = (int) $plan->{$field};
                if ($value < $base || $value > $rule['max'] || ($value - $base) % $rule['step'] !== 0) {
                    throw new ApiException('新规格范围会使已有用户无法续费，请保留其规格范围或新建套餐');
                }
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
            $rule = $plan->customization[$field];
            $units = intdiv($options[$field] - (int) $plan->{$field}, $rule['step']);
            // Period add-ons inherit the base plan's actual discounts. Reset buys traffic only.
            $amount = $period === Plan::PERIOD_RESET_TRAFFIC && $field !== 'transfer_enable'
                ? 0 : round($units * $rule['price_per_step'] * ($baseAmount / $reference));
            if (!is_finite((float) $amount) || $amount > self::MAX_AMOUNT) {
                throw new ApiException('所选规格总价超过允许上限');
            }
            $breakdown[$field] = (int) $amount;
        }
        $total = array_sum($breakdown);
        if ($total > self::MAX_AMOUNT) {
            throw new ApiException('所选规格总价超过允许上限');
        }
        return ['amount' => $total, 'breakdown' => $breakdown];
    }
}
