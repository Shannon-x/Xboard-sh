<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Quotes and order creation share this calculator. All public amounts are cents. */
class PlanCustomizationService
{
    public const LIMITS = ['transfer_enable' => 1000000, 'device_limit' => 100, 'speed_limit' => 10000];
    public const MAX_AMOUNT = 100000000;
    public const MAX_CHOICES = 100;

    /**
     * 增值节点组：customization.addon_groups = { "<group_id>": { mode: included|optional, price: 分/月, label?: 展示名 } }
     *
     * label 是用户看到的名称（可选，≤32 字）。权限组内部名常带「10x」这类倍率字眼，
     * 管理员可以用它包装成「高速通道」；留空则回落到权限组名。下发给用户端时 name
     * 已是解析后的展示名，设了 label 就不再泄露内部组名。
     *
     * 一个增值等级就是一个 ServerGroup —— 复用节点编辑器已有的分组多选，零新表、零新列，
     * 管理员把 10x 节点拉进「10x 高速」分组就完成了打标签。
     *   included → 随套餐赠送，不计价，客户不可取消；
     *   optional → 客户按需勾选，按月加价；长周期随套餐自身折扣比例缩放，
     *              与流量 / 设备 / 速度的加价走同一条规则（见 calculate）；
     *   未列出   → 该套餐不提供该组。
     *
     * 客户侧存两份，语义不同、缺一不可：
     *   plan_options.addon_groups   客户勾选的 optional 组 —— 续费 / 重置时原样带回重新报价；
     *   plan_options.granted_groups included ∪ 已购 —— 节点可见性与节点端拉名单直接读它，
     *                               热路径不回查套餐配置，管理员改配置也不影响已购用户。
     * 两个键都不存在 = 旧用户或套餐未配置增值组，只有基础组，行为与本功能上线前逐字相同。
     */
    public const ADDON_KEY = 'addon_groups';
    public const GRANTED_KEY = 'granted_groups';
    public const MAX_ADDON_GROUPS = 20;

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
        // 配了任何增值组（哪怕只是 included）就是自选套餐：必须走快照，开通时才有 granted_groups 可写。
        return $this->hasAddonConfig($plan);
    }

    public function hasAddonConfig(Plan $plan): bool
    {
        $addons = $plan->customization[self::ADDON_KEY] ?? null;
        return is_array($addons) && $addons !== [];
    }

    /** 增值组规则，键规整为 int group_id。未配置返回空数组。 */
    public function addonRules(Plan $plan): array
    {
        if (!$this->hasAddonConfig($plan)) return [];
        $rules = [];
        foreach ($plan->customization[self::ADDON_KEY] as $groupId => $rule) {
            if (is_numeric($groupId) && is_array($rule)) $rules[(int) $groupId] = $rule;
        }
        return $rules;
    }

    public function includedAddonIds(Plan $plan): array
    {
        return array_keys(array_filter($this->addonRules($plan), fn ($rule) => ($rule['mode'] ?? null) === 'included'));
    }

    public function optionalAddonIds(Plan $plan): array
    {
        return array_keys(array_filter($this->addonRules($plan), fn ($rule) => ($rule['mode'] ?? null) === 'optional'));
    }

    /** included ∪ 已勾选的 optional，升序去重。写进快照与 plan_options.granted_groups。 */
    public function grantedAddonGroups(Plan $plan, array $selected): array
    {
        $chosen = array_intersect($selected[self::ADDON_KEY] ?? [], $this->optionalAddonIds($plan));
        $granted = array_values(array_unique(array_merge($this->includedAddonIds($plan), $chosen)));
        sort($granted);
        return $granted;
    }

    /**
     * 给前端展示用的增值组：规则 + 组名 + 节点数。用户端没有分组接口，只能在套餐里内嵌。
     * 不含 group_id 以外的任何节点细节（名称 / 地址），未购买的客户只看得到「这一组有几个节点」。
     */
    public function addonGroupsForDisplay(Plan $plan): array
    {
        $rules = $this->addonRules($plan);
        if ($rules === []) return [];
        // 套餐列表是每个访客都打的接口，且对整个列表逐套餐调用本方法。组名与节点数
        // 都从 60 秒缓存里取：整页零额外查询。曾经的写法是每套餐一次 whereIn + 每组一次
        // ServerGroup::server_count 访问器（各发一条 COUNT），10 个套餐 × 2 组 = 30 条。
        $names = self::groupNames();
        $counts = self::serverCountsByGroup();
        $display = [];
        foreach ($rules as $groupId => $rule) {
            if (!isset($names[$groupId])) continue; // 组已被删除：不展示也不可选，validateConfiguration 会在下次保存时拦下
            $label = trim((string) ($rule['label'] ?? ''));
            $display[(string) $groupId] = [
                'mode' => $rule['mode'],
                'price' => (int) ($rule['price'] ?? 0),
                // 展示名优先；未设置时才用权限组名。设了展示名就不再把内部组名下发给用户。
                'name' => $label !== '' ? $label : $names[$groupId],
                'server_count' => $counts[$groupId] ?? 0,
            ];
        }
        return $display;
    }

    public const CACHE_GROUP_NAMES = 'plan_addon:group_names';
    public const CACHE_GROUP_SERVER_COUNTS = 'plan_addon:group_server_counts';
    public const CACHE_ADDON_GROUP_IDS = 'plan_addon:group_ids_in_use';
    private const CACHE_TTL = 60;

    /** 权限组 id → 名称。展示用，60 秒内容忍组改名/删除的延迟。 */
    public static function groupNames(): array
    {
        return Cache::remember(self::CACHE_GROUP_NAMES, self::CACHE_TTL, function () {
            return ServerGroup::query()->pluck('name', 'id')->mapWithKeys(fn ($n, $id) => [(int) $id => (string) $n])->all();
        });
    }

    /** 权限组 id → 该组节点数。节点表很小，一次读完在 PHP 里数，而不是每组一条 COUNT。 */
    public static function serverCountsByGroup(): array
    {
        return Cache::remember(self::CACHE_GROUP_SERVER_COUNTS, self::CACHE_TTL, function () {
            $counts = [];
            foreach (Server::query()->select('group_ids')->get() as $server) {
                foreach ((array) ($server->group_ids ?? []) as $gid) {
                    $gid = (int) $gid;
                    $counts[$gid] = ($counts[$gid] ?? 0) + 1;
                }
            }
            return $counts;
        });
    }

    /**
     * 在售或仍被用户持有的增值组 id（含 included / optional）。
     * 节点端拉名单只对这些组才需要多查 granted_groups；其余节点走纯索引查询。
     * 套餐保存 / 删除时由 PlanObserver 主动失效，避免刚买完增值组的用户在下一次 pull 里被漏掉。
     */
    public static function addonGroupIdsInUse(): array
    {
        return Cache::remember(self::CACHE_ADDON_GROUP_IDS, self::CACHE_TTL, function () {
            $ids = [];
            foreach (Plan::query()->whereNotNull('customization')->select('customization')->get() as $plan) {
                foreach (array_keys($plan->customization[self::ADDON_KEY] ?? []) as $gid) {
                    if (is_numeric($gid)) $ids[(int) $gid] = true;
                }
            }
            // Catalog changes must not revoke grants already written by paid orders.
            // Scan once per cache fill, in bounded chunks, not once per node pull.
            User::query()->whereNotNull('plan_options')->select(['id', 'plan_options'])
                ->chunkById(1000, function ($users) use (&$ids) {
                    foreach ($users as $user) {
                        foreach ($user->addonGroupIds() as $gid) {
                            if ($gid > 0) $ids[$gid] = true;
                        }
                    }
                });
            return array_keys($ids);
        });
    }

    public static function forgetAddonMembershipCache(): void
    {
        Cache::forget(self::CACHE_ADDON_GROUP_IDS);
        // A concurrent reader can refill using pre-commit data. Invalidate again
        // after commit; the immediate invalidation also covers reads in this transaction.
        DB::afterCommit(fn () => Cache::forget(self::CACHE_ADDON_GROUP_IDS));
    }

    public static function forgetAddonCaches(): void
    {
        self::forgetAddonMembershipCache();
        Cache::forget(self::CACHE_GROUP_NAMES);
        Cache::forget(self::CACHE_GROUP_SERVER_COUNTS);
    }

    public function validateConfiguration(Plan $plan): void
    {
        $config = $plan->customization;
        if ($config === null) return;
        if (!is_array($config)) {
            throw new ApiException('请为流量、设备数和速度分别设置固定值或自选规则');
        }
        $resources = $config;
        unset($resources[self::ADDON_KEY]);
        if (array_diff(array_keys($resources), array_keys(self::LIMITS)) || count($resources) !== count(self::LIMITS)) {
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
        $this->validateAddonConfiguration($plan, $config[self::ADDON_KEY] ?? null);
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
        // 最贵组合还要把所有可选增值组全部勾上，保证任何合法选择都不会越过 MAX_AMOUNT。
        if ($this->hasAddonConfig($plan)) $maxOptions[self::ADDON_KEY] = $this->optionalAddonIds($plan);
        foreach ($prices as $period => $price) {
            if (!is_numeric($price) || !is_finite((float) $price) || $price <= 0) {
                throw new ApiException('自选套餐的周期基础价必须为有限正数');
            }
            $this->calculate($plan, $period, $maxOptions);
        }
    }

    private function validateAddonConfiguration(Plan $plan, mixed $addons): void
    {
        if ($addons === null || $addons === []) return;
        if (!is_array($addons) || array_is_list($addons)) {
            throw new ApiException('增值节点组必须以权限组 ID 为键配置');
        }
        if (count($addons) > self::MAX_ADDON_GROUPS) {
            throw new ApiException('增值节点组最多 ' . self::MAX_ADDON_GROUPS . ' 个');
        }
        $ids = [];
        foreach ($addons as $groupId => $rule) {
            if (!is_numeric($groupId) || (int) $groupId != $groupId || (int) $groupId < 1) {
                throw new ApiException('增值节点组的键必须是正整数权限组 ID');
            }
            if ((int) $groupId === (int) $plan->group_id) {
                throw new ApiException('套餐的基础权限组不能再作为增值组出售');
            }
            if (!is_array($rule) || array_diff(array_keys($rule), ['mode', 'price', 'label'])) {
                throw new ApiException('增值节点组配置包含未知字段');
            }
            $mode = $rule['mode'] ?? null;
            if (!in_array($mode, ['included', 'optional'], true)) {
                throw new ApiException('增值节点组必须是「包含」或「可选购」');
            }
            $price = $rule['price'] ?? 0;
            if (!is_int($price) || $price < 0 || $price > self::MAX_AMOUNT) {
                throw new ApiException('增值节点组加价（分）必须是 0 至 ' . self::MAX_AMOUNT . ' 的整数');
            }
            if ($mode === 'included' && $price !== 0) {
                throw new ApiException('随套餐包含的增值节点组不能设置加价');
            }
            if (array_key_exists('label', $rule) && $rule['label'] !== null
                && (!is_string($rule['label']) || mb_strlen(trim($rule['label'])) > 32)) {
                throw new ApiException('增值节点组的展示名必须是不超过 32 字的文本');
            }
            $ids[] = (int) $groupId;
        }
        if (ServerGroup::whereIn('id', $ids)->count() !== count($ids)) {
            throw new ApiException('增值节点组引用了不存在的权限组，请刷新后重新选择');
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
        $hasAddons = $this->hasAddonConfig($plan);
        if (!$this->isEnabled($plan)) {
            if ($options !== null) $this->validateSelection($plan, $options);
            if ($current) $this->validateSelection($plan, $current);
            // Keep the legacy conversion exactly; no custom snapshot/payment restrictions.
            return ['period' => $period, 'amount' => (int) ($plan->prices[$period] * 100),
                'options' => null, 'breakdown' => [], 'snapshot' => null];
        }
        // 前向兼容：只认识三项资源键的旧客户端提交选择时不会带 addon_groups。
        // 「键缺席」= 沿用用户已购的增值组，而不是「退掉」；想退掉必须显式传 []。
        // 否则旧前端给买过增值组的用户续费会静默丢掉 10x 节点，买重置包会被判成"更改规格"。
        if ($options !== null && $current && $hasAddons
            && !array_key_exists(self::ADDON_KEY, $options) && array_key_exists(self::ADDON_KEY, $current)) {
            $options[self::ADDON_KEY] = $current[self::ADDON_KEY];
        }
        if ($period === Plan::PERIOD_RESET_TRAFFIC) {
            if (!$user || (int) $user->plan_id !== (int) $plan->id) {
                throw new ApiException('流量重置仅可用于当前订阅');
            }
            if ($options !== null && $this->normalizeSelection($options, $hasAddons)
                != $this->normalizeSelection($current ?? $this->baseOptions($plan), $hasAddons)) {
                throw new ApiException('流量重置不能更改套餐规格');
            }
            $options = $current;
        } elseif ($options === null && $current) {
            $options = $current;
        }
        $selected = $this->normalizeSelection($options ?? $this->baseOptions($plan), $hasAddons);
        $this->validateSelection($plan, $selected);
        $quote = $this->calculate($plan, $period, $selected);
        $snapshot = [
            'version' => 1, 'name' => $plan->name, 'group_id' => $plan->group_id,
            'reset_traffic_method' => $plan->reset_traffic_method,
            'options' => $selected, 'amount' => $quote['amount'], 'breakdown' => $quote['breakdown'],
        ];
        if ($hasAddons) $snapshot[self::GRANTED_KEY] = $this->grantedAddonGroups($plan, $selected);
        return $quote + ['period' => $period, 'options' => $selected, 'snapshot' => $snapshot];
    }

    public function baseOptions(Plan $plan): array
    {
        return array_combine(array_keys(self::LIMITS), array_map(
            fn ($field) => $plan->{$field} === null ? null : (int) $plan->{$field}, array_keys(self::LIMITS)
        ));
    }

    /** Resource-only configurators must explicitly opt in before receiving addon fields. */
    public function forClient(Plan $plan, ?User $user, bool $includeCustomization, bool $includeAddonGroups): Plan
    {
        if ($user && (!$includeCustomization || (!$includeAddonGroups && $this->hasAddonConfig($plan)))) {
            $plan = $this->forLegacyUser($plan, $user);
        }
        if (!$includeAddonGroups && $this->hasAddonConfig($plan)) {
            $plan = clone $plan;
            $config = $plan->customization;
            unset($config[self::ADDON_KEY]);
            $plan->customization = $config;
        }
        return $plan;
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
        // 只回填三项资源字段：addon_groups / granted_groups 不是 Plan 属性，旧客户端也不认识它们。
        $result->forceFill(array_intersect_key($user->plan_options, self::LIMITS) + ['prices' => $prices, 'customization' => null]);
        return $result;
    }

    /**
     * 规整一份选择：去掉派生键 granted_groups；addon_groups 去重升序。
     * 套餐未配置增值组时不引入 addon_groups 键（保持 #26 的快照 / 报价形状逐字不变），
     * 但客户硬塞了非空列表时保留它，让 validateSelection 明确拒绝。
     */
    private function normalizeSelection(array $selected, bool $withAddons): array
    {
        unset($selected[self::GRANTED_KEY]);
        $raw = $selected[self::ADDON_KEY] ?? [];
        if (is_array($raw) && array_is_list($raw)) {
            $raw = array_values(array_unique($raw, SORT_REGULAR));
            if ($raw === array_filter($raw, 'is_int')) sort($raw);
        }
        if ($withAddons || $raw !== []) {
            $selected[self::ADDON_KEY] = $raw;
        } else {
            unset($selected[self::ADDON_KEY]);
        }
        return $selected;
    }

    private function validateSelection(Plan $plan, array $selected): void
    {
        $selected = $this->normalizeSelection($selected, $this->hasAddonConfig($plan));
        $addons = array_key_exists(self::ADDON_KEY, $selected) ? $selected[self::ADDON_KEY] : null;
        unset($selected[self::ADDON_KEY]);
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
        if ($addons === null) return;
        if (!is_array($addons) || !array_is_list($addons) || count($addons) > self::MAX_ADDON_GROUPS) {
            throw new ApiException('增值节点选择格式错误');
        }
        $optional = $this->optionalAddonIds($plan);
        foreach ($addons as $groupId) {
            // included 的组随套餐自动生效，客户不能也不需要「选」它；不在 optional 里的一律拒绝。
            if (!is_int($groupId) || !in_array($groupId, $optional, true)) {
                throw new ApiException('所选增值节点不在可售选项内，请重新选择');
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
        if ($this->hasAddonConfig($plan)) {
            // 增值组按月定价，长周期按 baseAmount / reference 缩放 —— 年付折扣自动传导到增值组；
            // 流量重置包不重复计费：已购的增值组随订阅存在，重置只买流量。
            $addonAmount = 0;
            if ($period !== Plan::PERIOD_RESET_TRAFFIC) {
                $rules = $this->addonRules($plan);
                foreach ($options[self::ADDON_KEY] ?? [] as $groupId) {
                    $addonAmount += round((int) ($rules[$groupId]['price'] ?? 0) * ($baseAmount / $reference));
                }
            }
            if (!is_finite((float) $addonAmount) || $addonAmount > self::MAX_AMOUNT) {
                throw new ApiException('所选规格总价超过允许上限');
            }
            $breakdown[self::ADDON_KEY] = (int) $addonAmount;
        }
        $total = array_sum($breakdown);
        if ($total > self::MAX_AMOUNT) throw new ApiException('所选规格总价超过允许上限');
        return ['amount' => $total, 'breakdown' => $breakdown];
    }
}
