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
     * 用户此刻生效的增值组（effectiveAddonGroupIds）由三部分实时合成：
     *   ① 套餐当前的 included 组 —— 按 plan_id 查缓存表。管理员把某组设成「包含」，
     *      该套餐的全部订阅者立即拿到；撤掉则立即失去。不再依赖开通时的快照。
     *   ② plan_options.addon_groups —— 客户勾选并付费的 optional 组。开通时快照写入，
     *      续费 / 重置时原样带回重新报价；管理员事后改价不影响已购用户。
     *   ③ v2_user.admin_group_ids —— 管理员在用户编辑里手动授予的组（赔偿 / 工单），
     *      与套餐无关、不参与报价、换套餐也保留，只能由管理员撤销。
     * plan_options.granted_groups 仍会在开通时写入（included ∪ 已购），但只作为兼容字段：
     * 热路径不再读它；getSubscribe 下发时会用「此刻生效」的集合覆盖它，旧前端照常工作。
     * 三个来源都为空 = 旧用户或套餐未配置增值组，只有基础组，行为与本功能上线前逐字相同。
     */
    public const ADDON_KEY = 'addon_groups';
    public const GRANTED_KEY = 'granted_groups';
    public const MAX_ADDON_GROUPS = 20;

    /**
     * 流量加购包：customization.traffic_topup =
     *   { mode: off|on, price_per_gb: 分, selection: range|choices, min_gb?, max_gb?, step_gb?, choices?: [{gb, price?}] }
     *
     * 加购是**套餐级**配置、默认关闭，没有站点级默认：每个套餐的每 GB 成本不同，
     * 一个全局单价要么让便宜套餐套利、要么让贵套餐卖不动。
     * 单价有硬下限 = 套餐自身每 GB 到手价（topupPriceFloor），低于它用户会买最低档再加购。
     * 这个键不会让套餐变成「自选套餐」（isEnabled 不看它），旧套餐的报价 / 快照形状逐字不变。
     */
    public const TOPUP_KEY = 'traffic_topup';
    public const TOPUP_MAX_GB = 100000;
    public const TOPUP_DEFAULT_PRESETS = [10, 50, 100, 200];

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
                'topup_price_per_gb' => (int) ($rule['topup_price_per_gb'] ?? 0),
            ];
        }
        return $display;
    }

    /** 用户此刻生效的增值组列表（getSubscribe 下发）：id、展示名、节点数、来源。 */
    public function addonGroupsForUser(?Plan $plan, User $user): array
    {
        $ids = $user->addonGroupIds();
        if ($ids === []) return [];
        $rules = $plan ? $this->addonRules($plan) : [];
        $included = $plan ? (self::includedGroupsByPlan()[(int) $plan->id] ?? []) : [];
        $purchased = $user->purchasedAddonGroupIds();
        $names = self::groupNames();
        $counts = self::serverCountsByGroup();
        $out = [];
        foreach ($ids as $id) {
            if (!isset($names[$id])) continue;
            $label = trim((string) ($rules[$id]['label'] ?? ''));
            $out[] = [
                'id' => $id,
                'name' => $label !== '' ? $label : $names[$id],
                'server_count' => $counts[$id] ?? 0,
                'source' => in_array($id, $included, true) ? 'included'
                    : (in_array($id, $purchased, true) ? 'purchased' : 'admin'),
            ];
        }
        return $out;
    }

    // ───────────────────────── 流量加购包 ─────────────────────────

    /**
     * 套餐自身每 GB 的到手价（分）—— 加购单价的硬下限。
     * 低于它，用户买最低档再加购就比直接买大档便宜，等于把套餐定价拆穿。
     * 取两者较大：月付价 ÷ 套餐 GB（无月付按其它周期折算到月，一次性按原价），
     * 以及购买时自选流量的每 GB 加价（customization.transfer_enable 的 price_per_step ÷ step）。
     */
    public function topupPriceFloor(Plan $plan): int
    {
        $gb = (int) $plan->transfer_enable;
        if ($gb < 1) return 0;
        $prices = (array) ($plan->prices ?? []);
        $monthly = null;
        foreach ([Plan::PERIOD_MONTHLY => 1, Plan::PERIOD_QUARTERLY => 3, Plan::PERIOD_HALF_YEARLY => 6, Plan::PERIOD_YEARLY => 12,
                  Plan::PERIOD_TWO_YEARLY => 24, Plan::PERIOD_THREE_YEARLY => 36, Plan::PERIOD_ONETIME => 1] as $period => $months) {
            if (isset($prices[$period]) && is_numeric($prices[$period]) && $prices[$period] > 0) {
                $monthly = (float) $prices[$period] / $months;
                break;
            }
        }
        if ($monthly === null) return 0;
        $floor = (int) ceil($monthly * 100 / $gb);
        $rule = $plan->customization['transfer_enable'] ?? null;
        if (is_array($rule) && ($rule['mode'] ?? 'fixed') !== 'fixed' && (int) ($rule['step'] ?? 0) > 0 && isset($rule['price_per_step'])) {
            $floor = max($floor, (int) ceil((int) $rule['price_per_step'] / (int) $rule['step']));
        }
        return $floor;
    }

    /**
     * 解析某套餐的加购规则。返回 null = 该套餐不卖加购（默认）。
     * 两种选购方式，与套餐资源的「范围 / 指定选项」同一套语言：
     *   range   → 滑杆：min ~ max 按 step 递增，金额 = GB × 单价；choices 只作快捷按钮
     *   choices → 指定档位：只能买列出的几档，每档可单独定价（大包更便宜），不填则按单价算
     */
    public function topupRule(Plan $plan, ?User $user = null): ?array
    {
        $rule = $plan->customization[self::TOPUP_KEY] ?? null;
        // 'custom' 是早期草案里「自定义」的写法，与 'on' 同义；'inherit' 已无站点默认可继承，视为关闭
        if (!is_array($rule) || !in_array($rule['mode'] ?? 'off', ['on', 'custom'], true)) return null;
        $base = (int) ($rule['price_per_gb'] ?? 0);
        if ($base < 1) return null;
        // 持有增值组的用户加购更贵：每个增值组规则可设 topup_price_per_gb（分/GB），
        // 同一套餐里买了 10x 组的人与没买的人，加购单价就此拉开。按此刻生效的组算，
        // 管理员手动授予的组若也在本套餐规则里，同样计入。
        $surcharge = 0;
        if ($user) {
            $rules = $this->addonRules($plan);
            foreach ($user->addonGroupIds() as $gid) {
                $surcharge += (int) ($rules[$gid]['topup_price_per_gb'] ?? 0);
            }
        }
        $price = $base + $surcharge;
        $selection = ($rule['selection'] ?? 'range') === 'choices' ? 'choices' : 'range';
        $tiers = [];
        foreach ((array) ($rule['choices'] ?? []) as $c) {
            $gb = is_array($c) ? (int) ($c['gb'] ?? 0) : 0;
            if ($gb >= 1 && $gb <= self::TOPUP_MAX_GB) {
                $tiers[$gb] = ['gb' => $gb, 'price' => isset($c['price']) && $c['price'] !== null ? (int) $c['price'] : null];
            }
        }
        ksort($tiers);
        $tiers = array_values($tiers);

        if ($selection === 'choices') {
            if ($tiers === []) return null;   // 没档位就没得买
            $choices = [];
            foreach ($tiers as $t) {
                $amount = ($t['price'] ?? $t['gb'] * $base) + $surcharge * $t['gb'];
                $choices[] = ['gb' => $t['gb'], 'amount' => $amount, 'unit' => (int) round($amount / $t['gb'])];
            }
            return [
                'selection' => 'choices',
                'price_per_gb' => $price, 'base_price_per_gb' => $base, 'addon_surcharge_per_gb' => $surcharge,
                'min_gb' => $choices[0]['gb'], 'max_gb' => end($choices)['gb'], 'step_gb' => 1,
                'choices' => $choices, 'presets' => array_column($choices, 'gb'),
            ];
        }

        $min = max(1, min((int) ($rule['min_gb'] ?? 1), self::TOPUP_MAX_GB));
        $max = max($min, min((int) ($rule['max_gb'] ?? 1000), self::TOPUP_MAX_GB));
        $step = max(1, min((int) ($rule['step_gb'] ?? 1), $max));
        $candidates = $tiers !== [] ? array_column($tiers, 'gb') : self::TOPUP_DEFAULT_PRESETS;
        $presets = array_values(array_filter($candidates, fn (int $gb) => $gb >= $min && $gb <= $max && ($gb - $min) % $step === 0));
        return [
            'selection' => 'range',
            'price_per_gb' => $price, 'base_price_per_gb' => $base, 'addon_surcharge_per_gb' => $surcharge,
            'min_gb' => $min, 'max_gb' => $max, 'step_gb' => $step,
            'choices' => [], 'presets' => $presets,
        ];
    }

    /** 加购流量的失效时刻：下次重置日；不重置的套餐到到期日；永久套餐为 null（随下一次清零动作失效）。 */
    public function topupValidUntil(User $user): ?int
    {
        $next = $user->next_reset_at;
        $next = $next instanceof \DateTimeInterface ? $next->getTimestamp() : ($next !== null ? (int) $next : null);
        if ($next !== null && $next > time()) return $next;
        return $user->expired_at !== null ? (int) $user->expired_at : null;
    }

    /** getSubscribe 下发的加购摘要：规则 + 本周期已加购量 + 失效时刻。 */
    public function topupSummary(?Plan $plan, User $user): array
    {
        $rule = $plan ? $this->topupRule($plan, $user) : null;
        $active = ($user->expired_at === null || (int) $user->expired_at > time()) && !$user->banned;
        return [
            'enabled' => $rule !== null && $active,
            'price_per_gb' => $rule['price_per_gb'] ?? 0,
            'base_price_per_gb' => $rule['base_price_per_gb'] ?? 0,
            'addon_surcharge_per_gb' => $rule['addon_surcharge_per_gb'] ?? 0,
            'selection' => $rule['selection'] ?? 'range',
            'min_gb' => $rule['min_gb'] ?? 0,
            'max_gb' => $rule['max_gb'] ?? 0,
            'step_gb' => $rule['step_gb'] ?? 1,
            'choices' => $rule['choices'] ?? [],
            'presets' => $rule['presets'] ?? [],
            'active_bytes' => (int) ($user->transfer_topup ?? 0),
            'valid_until' => $this->topupValidUntil($user),
        ];
    }

    /**
     * 加购报价：金额 = GB × 单价，服务端算，前端只回传 expected_amount 做守卫。
     * 快照不带 options / group_id：开通时不会碰套餐规格、限速、设备数、增值组。
     */
    public function quoteTopup(Plan $plan, ?User $user, ?array $options): array
    {
        if (!$user || (int) $user->plan_id !== (int) $plan->id) {
            throw new ApiException('流量加购仅可用于当前订阅');
        }
        if ($user->banned || ($user->expired_at !== null && (int) $user->expired_at <= time())) {
            throw new ApiException('订阅已到期，请先续费再加购流量');
        }
        $rule = $this->topupRule($plan, $user);
        if ($rule === null) {
            throw new ApiException('当前套餐不支持加购流量');
        }
        $gb = $options['topup_gb'] ?? null;
        if ($rule['selection'] === 'choices') {
            $chosen = null;
            foreach ($rule['choices'] as $choice) {
                if (is_int($gb) && $choice['gb'] === $gb) $chosen = $choice;
            }
            if ($chosen === null) {
                throw new ApiException('请选择列出的加购档位：' . implode(' / ', array_map(fn ($c) => $c['gb'] . ' GB', $rule['choices'])));
            }
            $amount = (int) $chosen['amount'];
        } else {
            if (!is_int($gb) || $gb < $rule['min_gb'] || $gb > $rule['max_gb'] || ($gb - $rule['min_gb']) % $rule['step_gb'] !== 0) {
                throw new ApiException($rule['step_gb'] > 1
                    ? "加购流量需在 {$rule['min_gb']} 至 {$rule['max_gb']} GB 之间，按 {$rule['step_gb']} GB 递增"
                    : "加购流量需在 {$rule['min_gb']} 至 {$rule['max_gb']} GB 之间");
            }
            $amount = $gb * $rule['price_per_gb'];
        }
        if ($amount > self::MAX_AMOUNT) {
            throw new ApiException('加购金额超过允许上限');
        }
        $snapshot = [
            'version' => 1, 'kind' => self::TOPUP_KEY, 'name' => $plan->name,
            'topup_gb' => $gb, 'price_per_gb' => (int) round($amount / $gb), 'amount' => $amount,
            'selection' => $rule['selection'],
            'valid_until' => $this->topupValidUntil($user),
        ];
        return [
            'period' => Plan::PERIOD_TRAFFIC_TOPUP, 'amount' => $amount,
            'options' => ['topup_gb' => $gb], 'breakdown' => [self::TOPUP_KEY => $amount], 'snapshot' => $snapshot,
        ];
    }

    private function validateTopupConfiguration(Plan $plan, mixed $rule): void
    {
        if ($rule === null) return;
        if (!is_array($rule) || array_is_list($rule)
            || array_diff(array_keys($rule), ['mode', 'price_per_gb', 'min_gb', 'max_gb', 'selection', 'step_gb', 'choices'])) {
            throw new ApiException('流量加购配置包含未知字段');
        }
        $mode = $rule['mode'] ?? 'off';
        if (!in_array($mode, ['off', 'on', 'custom', 'inherit'], true)) {
            throw new ApiException('流量加购模式必须是开启或关闭');
        }
        if (!in_array($mode, ['on', 'custom'], true)) return;
        foreach (['price_per_gb', 'min_gb', 'max_gb', 'step_gb'] as $key) {
            if (array_key_exists($key, $rule) && $rule[$key] !== null && !is_int($rule[$key])) {
                throw new ApiException('流量加购的单价（分）、GB 上下限与步长必须为整数');
            }
        }
        $price = (int) ($rule['price_per_gb'] ?? 0);
        if ($price < 1 || $price > self::MAX_AMOUNT) {
            throw new ApiException('开启加购必须设置单价（分/GB），范围 1 至 ' . self::MAX_AMOUNT);
        }
        // 反套利：单价不得低于套餐自身每 GB 到手价，否则「买最低档 + 加购」比直接买大档便宜
        $floor = $this->topupPriceFloor($plan);
        if ($floor > 0 && $price < $floor) {
            throw new ApiException(sprintf('加购单价 ¥%.2f/GB 低于本套餐每 GB 到手价 ¥%.2f/GB：用户买最低档再加购会比直接买大档便宜。请不低于 ¥%.2f',
                $price / 100, $floor / 100, $floor / 100));
        }
        $selection = $rule['selection'] ?? 'range';
        if (!in_array($selection, ['range', 'choices'], true)) {
            throw new ApiException('流量加购的选购方式必须是「范围」或「指定档位」');
        }
        $min = $rule['min_gb'] ?? 1;
        $max = $rule['max_gb'] ?? 1000;
        $step = $rule['step_gb'] ?? 1;
        if ($min < 1 || $max > self::TOPUP_MAX_GB || $min > $max || $step < 1 || $step > self::TOPUP_MAX_GB) {
            throw new ApiException('流量加购的 GB 上下限与步长必须在 1 至 ' . self::TOPUP_MAX_GB . ' 之间且下限不大于上限');
        }
        $choices = $rule['choices'] ?? null;
        if ($choices !== null) {
            if (!is_array($choices) || !array_is_list($choices) || count($choices) > self::MAX_CHOICES) {
                throw new ApiException('加购档位需要 1 至 ' . self::MAX_CHOICES . ' 个');
            }
            $previous = 0;
            foreach ($choices as $choice) {
                if (!is_array($choice) || array_diff(array_keys($choice), ['gb', 'price'])
                    || !isset($choice['gb']) || !is_int($choice['gb']) || $choice['gb'] < 1 || $choice['gb'] > self::TOPUP_MAX_GB
                    || $choice['gb'] <= $previous
                    || (array_key_exists('price', $choice) && $choice['price'] !== null && (!is_int($choice['price']) || $choice['price'] < 0 || $choice['price'] > self::MAX_AMOUNT))) {
                    throw new ApiException('加购档位必须是递增、不重复的 GB 数，档位价（分）可选且不能为负');
                }
                // 档位专价同样不得低于到手价下限
                if ($floor > 0 && isset($choice['price']) && $choice['price'] !== null && $choice['price'] < $floor * $choice['gb']) {
                    throw new ApiException(sprintf('%d GB 档位价 ¥%.2f 折合 ¥%.2f/GB，低于本套餐每 GB 到手价 ¥%.2f/GB，会被套利；该档至少 ¥%.2f',
                        $choice['gb'], $choice['price'] / 100, $choice['price'] / 100 / $choice['gb'], $floor / 100, $floor * $choice['gb'] / 100));
                }
                $previous = $choice['gb'];
            }
        }
        if ($selection === 'choices' && empty($choices)) {
            throw new ApiException('指定档位模式至少要填一档');
        }
    }

    public const CACHE_GROUP_NAMES = 'plan_addon:group_names';
    public const CACHE_GROUP_SERVER_COUNTS = 'plan_addon:group_server_counts';
    public const CACHE_ADDON_GROUP_IDS = 'plan_addon:group_ids_in_use';
    public const CACHE_INCLUDED_BY_PLAN = 'plan_addon:included_by_plan';
    public const CACHE_ADMIN_GROUP_IDS = 'plan_addon:admin_group_ids';
    private const CACHE_TTL = 60;

    /**
     * plan_id → 该套餐此刻「包含」的增值组 id。实时语义的数据源：
     * 订阅出口、节点可见性、节点端名单都按它合成，管理员改套餐即刻影响全部订阅者。
     */
    public static function includedGroupsByPlan(): array
    {
        return Cache::remember(self::CACHE_INCLUDED_BY_PLAN, self::CACHE_TTL, function () {
            $map = [];
            foreach (Plan::query()->whereNotNull('customization')->select(['id', 'customization'])->get() as $plan) {
                $ids = [];
                foreach ($plan->customization[self::ADDON_KEY] ?? [] as $gid => $rule) {
                    if (is_numeric($gid) && is_array($rule) && ($rule['mode'] ?? null) === 'included') $ids[] = (int) $gid;
                }
                if ($ids !== []) {
                    sort($ids);
                    $map[(int) $plan->id] = $ids;
                }
            }
            return $map;
        });
    }

    /** 哪些套餐把给定组中的任一组设为「包含」。节点端名单用它走 plan_id 索引分支。 */
    public static function plansIncludingGroups(array $groupIds): array
    {
        $planIds = [];
        foreach (self::includedGroupsByPlan() as $planId => $ids) {
            if (array_intersect($ids, $groupIds) !== []) $planIds[] = $planId;
        }
        return $planIds;
    }

    /** 管理员手动授予过的组 id（全站去重）。管理端写入时主动失效。 */
    public static function adminGrantedGroupIds(): array
    {
        return Cache::remember(self::CACHE_ADMIN_GROUP_IDS, self::CACHE_TTL, function () {
            $ids = [];
            foreach (User::query()->whereNotNull('admin_group_ids')->select('admin_group_ids')->cursor() as $user) {
                foreach ((array) ($user->admin_group_ids ?? []) as $gid) {
                    if (is_numeric($gid)) $ids[(int) $gid] = true;
                }
            }
            return array_keys($ids);
        });
    }

    /**
     * 用户此刻生效的增值组（不含基础组）：套餐实时包含 ∪ 已购 optional ∪ 管理员授予。
     * 接受原始值（数组或 JSON 字符串），Observer 拿 getOriginal() 也能算「变更前」的集合。
     */
    public static function effectiveAddonGroupIds(?int $planId, mixed $planOptions, mixed $adminGroupIds): array
    {
        if (is_string($planOptions)) $planOptions = json_decode($planOptions, true);
        if (is_string($adminGroupIds)) $adminGroupIds = json_decode($adminGroupIds, true);
        $ids = $planId ? (self::includedGroupsByPlan()[$planId] ?? []) : [];
        $purchased = is_array($planOptions) ? ($planOptions[self::ADDON_KEY] ?? []) : [];
        foreach ([$purchased, $adminGroupIds] as $list) {
            if (!is_array($list)) continue;
            foreach ($list as $gid) {
                if (is_numeric($gid) && (int) $gid > 0) $ids[] = (int) $gid;
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        return $ids;
    }

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
     * 需要按用户 JSON 字段筛名单的组：任一套餐里在售的 optional 组 ∪ 用户仍持有的已购组
     * ∪ 管理员手动授予过的组。included 组不在这里 —— 它们走 plan_id 索引分支。
     * 节点端拉名单只对这些组才多发一条 JSON 查询；其余节点走纯索引查询。
     * 套餐保存 / 删除、用户授予写入时主动失效（含事务提交后再失效一次）。
     */
    public static function addonGroupIdsInUse(): array
    {
        $optional = Cache::remember(self::CACHE_ADDON_GROUP_IDS, self::CACHE_TTL, function () {
            $ids = [];
            foreach (Plan::query()->whereNotNull('customization')->select('customization')->get() as $plan) {
                foreach ($plan->customization[self::ADDON_KEY] ?? [] as $gid => $rule) {
                    if (is_numeric($gid) && is_array($rule) && ($rule['mode'] ?? null) === 'optional') $ids[(int) $gid] = true;
                }
            }
            // 目录变更不能撤掉付费订单已写下的权益（导入 / 历史数据也一样）。
            // 每次填缓存扫一遍，分块、有界，而不是每次节点 pull 都扫。
            User::query()->whereNotNull('plan_options')->select(['id', 'plan_options'])
                ->chunkById(1000, function ($users) use (&$ids) {
                    foreach ($users as $user) {
                        foreach ($user->purchasedAddonGroupIds() as $gid) {
                            if ($gid > 0) $ids[$gid] = true;
                        }
                    }
                });
            return array_keys($ids);
        });
        return array_values(array_unique(array_merge($optional, self::adminGrantedGroupIds())));
    }

    public static function forgetAddonMembershipCache(): void
    {
        $keys = [self::CACHE_ADDON_GROUP_IDS, self::CACHE_INCLUDED_BY_PLAN, self::CACHE_ADMIN_GROUP_IDS];
        foreach ($keys as $key) Cache::forget($key);
        // 并发读者可能用提交前的数据回填。提交后再失效一次；立即失效覆盖本事务内的读取。
        DB::afterCommit(function () use ($keys) {
            foreach ($keys as $key) Cache::forget($key);
        });
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
        unset($resources[self::ADDON_KEY], $resources[self::TOPUP_KEY]);
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
        $this->validateTopupConfiguration($plan, $config[self::TOPUP_KEY] ?? null);
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
            if (!is_array($rule) || array_diff(array_keys($rule), ['mode', 'price', 'label', 'topup_price_per_gb'])) {
                throw new ApiException('增值节点组配置包含未知字段');
            }
            $topupSurcharge = $rule['topup_price_per_gb'] ?? 0;
            if (!is_int($topupSurcharge) || $topupSurcharge < 0 || $topupSurcharge > self::MAX_AMOUNT) {
                throw new ApiException('增值节点组的加购流量加价（分/GB）必须是 0 至 ' . self::MAX_AMOUNT . ' 的整数');
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
        if ($period === Plan::PERIOD_TRAFFIC_TOPUP) {
            return $this->quoteTopup($plan, $user, $options);
        }
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

    /** public：批量迁移命令（xboard:grandfather-addon-group）要在写库前先验一遍将要写入的选择。 */
    public function validateSelection(Plan $plan, array $selected): void
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
