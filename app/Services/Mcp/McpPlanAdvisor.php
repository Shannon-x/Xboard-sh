<?php

namespace App\Services\Mcp;

use App\Exceptions\ApiException;
use App\Models\Plan;
use App\Models\Server;
use App\Models\StatUser;
use App\Models\User;
use App\Services\Mcp\McpPresenter as P;
use App\Services\Payg\PaygConfig;
use App\Services\Payg\PaygService;
use App\Services\PlanCustomizationService;
use App\Services\PlanService;
use App\Support\PlanCatalog;

/**
 * AI 助手「帮我选套餐」：按客户自己最近的真实用量（分线路、已按倍率）算出每个选项每月要花多少、够不够用。
 *
 * 用量来自 v2_stat_user 的日记录：u / d 落库时已乘过节点倍率（扣套餐流量的口径），除以倍率就是实际流量。
 * 同一份实际用量，走 ×3 中转要扣 3 倍套餐流量、走 ×10 三网要扣 10 倍 —— 这正是客户自己最算不清的地方。
 *
 * 价格一律走 PlanCustomizationService::quote（与面板报价、下单同一套），这里只负责「挑哪个规格去报价」：
 * 包月看流量够不够（可自选流量的套餐挑够用的最小档）、要用的线路在不在（不在就勾上能补上的可选增值组），
 * 按量看单价 × 倍率与月度保底。只读：不建单、不改任何东西。
 */
final class McpPlanAdvisor
{
    private const GB = P::BYTES_PER_GB;
    /** 看最近多少天的用量 */
    public const HISTORY_DAYS = 90;
    /** 少于这么多天的数据不按历史估（新客户 / 刚开始用），请客户自己说 */
    public const MIN_HISTORY_DAYS = 7;
    /** 可自选流量的套餐按「预计扣量 × 1.2」挑档；余量低于 10% 又不能加购的包月不推荐 */
    public const HEADROOM = 1.2;
    private const TIGHT = 1.1;

    /** 与前端 site-config.lines 同一张表：只用于给 AI 讲解，扣量永远按节点自己的倍率 */
    public const LINES = [
        'direct' => ['rate' => 1, 'name' => '直连线路', 'note' => '电信为主'],
        'relay' => ['rate' => 3, 'name' => '中转线路', 'note' => '联通 / 移动'],
        'dedicated' => ['rate' => 6, 'name' => '透传专线', 'note' => '游戏 / 会议'],
        'triple' => ['rate' => 10, 'name' => '三网优化线路', 'note' => '三网全优，需按教程配置'],
    ];

    /** 客户没有历史用量、要自己估时给 AI 的参考（每小时大约多少 GB，实际流量） */
    public const USAGE_GUIDE = [
        ['activity' => '网页、聊天、邮件', 'gb_per_hour' => '0.05–0.1'],
        ['activity' => '音乐', 'gb_per_hour' => '0.1'],
        ['activity' => '在线游戏（不含下载更新）', 'gb_per_hour' => '0.05–0.15'],
        ['activity' => '短视频（TikTok、Reels 等）', 'gb_per_hour' => '0.5–1'],
        ['activity' => '视频会议', 'gb_per_hour' => '0.5–1.5'],
        ['activity' => '720p 视频', 'gb_per_hour' => '约 1'],
        ['activity' => '1080p 视频 / 直播推流', 'gb_per_hour' => '1.5–3'],
        ['activity' => '4K 视频', 'gb_per_hour' => '7–15'],
    ];

    private const PERIOD_MONTHS = [
        Plan::PERIOD_MONTHLY => 1, Plan::PERIOD_QUARTERLY => 3, Plan::PERIOD_HALF_YEARLY => 6,
        Plan::PERIOD_YEARLY => 12, Plan::PERIOD_TWO_YEARLY => 24, Plan::PERIOD_THREE_YEARLY => 36,
    ];

    public function __construct(private readonly PlanCustomizationService $customizer)
    {
    }

    // ───────────────────────── 用量画像 ─────────────────────────

    /**
     * 最近 90 天：按自然月、按倍率汇总的实际 / 扣量，近 30 天的线路占比，以及据此估的每月实际用量。
     *
     * @return array{days_of_data: int, monthly_gb: float|null, mix: array<string, float>, last_30_days: array, months: list<array>}
     */
    public function profile(User $user): array
    {
        $today = now()->startOfDay();
        $rows = StatUser::where('user_id', $user->id)->where('record_type', 'd')
            ->where('record_at', '>=', $today->copy()->subDays(self::HISTORY_DAYS - 1)->getTimestamp())
            ->groupBy('record_at', 'server_rate')
            ->selectRaw('record_at, server_rate, SUM(u + d) AS billed')
            ->get();

        $cut30 = $today->copy()->subDays(29)->getTimestamp();
        $first = null;
        $months = [];
        $last30 = [];
        $all = [];
        foreach ($rows as $row) {
            $rate = self::rate($row->server_rate);
            $billed = (int) $row->billed;
            if ($billed <= 0 || $rate <= 0) {
                continue;
            }
            $at = (int) $row->record_at;
            $first = $first === null ? $at : min($first, $at);
            $key = self::rateKey($rate);
            $month = date('Y-m', $at);
            $months[$month][$key] = ($months[$month][$key] ?? 0) + $billed;
            $all[$key] = ($all[$key] ?? 0) + $billed;
            if ($at >= $cut30) {
                $last30[$key] = ($last30[$key] ?? 0) + $billed;
            }
        }
        // 从第一条记录到今天都算「有数据」：中间没用的日子就是真的没用
        $days = $first === null ? 0 : (int) floor(($today->getTimestamp() - $first) / 86400) + 1;

        $monthly = null;
        if ($days >= 28) {
            $monthly = self::realGb($last30);
        } elseif ($days >= self::MIN_HISTORY_DAYS) {
            $monthly = round(self::realGb($all) / $days * 30, 2);
        }

        ksort($months);
        $monthList = [];
        foreach ($months as $month => $byRate) {
            $monthList[] = [
                'month' => $month,
                'gb' => self::realGb($byRate),
                'quota_gb' => P::gb(array_sum($byRate)),
                'partial' => $month === $today->format('Y-m') || ($first !== null && $month === date('Y-m', $first) && (int) date('j', $first) > 1),
            ];
        }

        return [
            'days_of_data' => $days,
            'monthly_gb' => $monthly,
            'mix' => self::mix($last30 !== [] ? $last30 : $all),
            'last_30_days' => [
                'gb' => self::realGb($last30),
                'quota_gb' => P::gb(array_sum($last30)),
                'by_line' => self::byLine($last30),
            ],
            'months' => $monthList,
        ];
    }

    // ───────────────────────── 推荐 ─────────────────────────

    /**
     * @param float                $monthlyGb 每月实际流量（GB，不含倍率）
     * @param array<string, float> $mix       倍率 => 占比（合计 1）
     * @param string|null          $segment   目录分段；null = 客户当前套餐的分段，没有就是普通上网
     */
    public function recommend(User $user, float $monthlyGb, array $mix, ?string $segment = null): array
    {
        $payg = PaygConfig::fromSettings();
        $plans = app(PlanService::class)->getAvailablePlans();
        $current = $user->plan_id ? Plan::find($user->plan_id) : null;
        if ($current && $current->renew && !$plans->contains('id', $current->id)) {
            $plans->push($current);
        }
        $segmentOf = fn (Plan $p) => PlanCatalog::split($p->tags)['catalog']['segment'] ?? null;
        $catalogOn = $plans->contains(fn (Plan $p) => $segmentOf($p) !== null);
        $segment ??= ($current && !$payg->isPaygPlanId($current->id) ? $segmentOf($current) : null) ?? 'basic';

        $need = 0.0;   // 每月要扣的套餐流量（GB）
        foreach ($mix as $rate => $share) {
            $need += $monthlyGb * $share * (float) $rate;
        }
        $need = round($need, 2);
        $groupRates = self::ratesByGroup();

        $options = [];
        foreach ($plans as $plan) {
            $isPayg = $payg->isPaygPlanId($plan->id);
            if ($catalogOn && !($isPayg ? $segment === 'basic' : $segmentOf($plan) === $segment)) {
                continue;
            }
            $option = $isPayg
                ? $this->paygOption($plan, $user, $need, $mix, $groupRates, $payg)
                : $this->monthlyOption($plan, $user, $need, $mix, $groupRates);
            if ($option !== null) {
                $option['is_current_plan'] = (int) $user->plan_id === (int) $plan->id;
                $options[] = $option;
            }
        }

        $viable = array_values(array_filter($options, fn ($o) => $o['viable']));
        usort($viable, fn ($a, $b) => ($a['monthly_cents'] <=> $b['monthly_cents'])
            ?: ($b['is_current_plan'] <=> $a['is_current_plan'])
            ?: (($b['traffic_gb'] ?? 0) <=> ($a['traffic_gb'] ?? 0)));
        // 首选只在「省心」的里面挑：按量，或流量够用、不用每个周期再加购的包月。
        // 余量不到 10% 又不能加购的包月、要靠每月加购才够的包月，报出来当备选，并说清代价。
        $clean = array_values(array_filter($viable, fn ($o) => !$o['tight'] && $o['topup'] === null));
        $pick = $clean[0] ?? $viable[0] ?? null;
        $currentOption = null;
        foreach ($options as $o) {
            if ($o['is_current_plan']) {
                $currentOption = $o;
            }
        }

        $ranked = $pick ? array_merge([$pick], array_values(array_filter($viable, fn ($o) => $o !== $pick))) : $viable;
        $notes = [];
        if ($pick && $currentOption && $currentOption !== $pick && $currentOption['viable']) {
            $diff = $currentOption['monthly_cents'] - $pick['monthly_cents'];
            if ($diff > 0) {
                $notes[] = '比客户现在的「' . $currentOption['plan_name'] . '」每月省约 ¥' . P::yuan($diff) . '。换套餐前先 quote_plan，剩余时间会折抵。';
            }
        } elseif ($pick && $currentOption === $pick) {
            $notes[] = '客户现在的套餐就是最合适的，到期续费即可（quote_plan mode=renew）。';
        }
        foreach ($viable as $o) {
            if ($pick && $o !== $pick && $o['topup'] !== null && $o['monthly_cents'] < $pick['monthly_cents']) {
                $notes[] = '「' . $o['plan_name'] . '」靠每月加购 ' . $o['topup']['gb'] . ' GB 也够用，每月约 ¥' . P::yuan($o['monthly_cents'])
                    . '，比推荐的再省 ¥' . P::yuan($pick['monthly_cents'] - $o['monthly_cents']) . '，但每个周期都要记得加购，适合愿意自己盯用量的客户。';
                break;
            }
        }
        if (array_filter($options, fn ($o) => $o['missing_lines'] !== [])) {
            $notes[] = '有的套餐不含客户在用的线路（见 missing_lines），换过去那部分用量就只能改走别的线路。';
        }

        return [
            'segment' => $catalogOn ? $segment : null,
            'monthly_quota_needed_gb' => $need,
            'recommended' => $pick ? self::present($pick) : null,
            'alternatives' => array_map([self::class, 'present'], array_slice($ranked, 1, 3)),
            'current_plan' => $currentOption ? self::present($currentOption) : null,
            'not_suitable' => array_values(array_map(fn ($o) => [
                'plan_name' => $o['plan_name'],
                'why' => $o['why'],
            ], array_filter($options, fn ($o) => !$o['viable'] && !$o['is_current_plan']))),
            'notes' => $notes,
        ];
    }

    private function monthlyOption(Plan $plan, User $user, float $need, array $mix, array $groupRates): ?array
    {
        // 每年重置 / 不重置的周期套餐流量不是按月给的，放在一起比会误导
        if (in_array($plan->reset_traffic_method, [Plan::RESET_TRAFFIC_NEVER, Plan::RESET_TRAFFIC_FIRST_DAY_YEAR, Plan::RESET_TRAFFIC_YEARLY], true)) {
            return null;
        }
        $periods = array_filter(self::PERIOD_MONTHS, fn ($m, $period) => is_numeric($plan->prices[$period] ?? null), ARRAY_FILTER_USE_BOTH);
        if ($periods === []) {
            return null;
        }
        $access = $this->lineAccess($plan, array_keys($mix), $groupRates, true);
        $traffic = $this->trafficFor($plan, $need * self::HEADROOM);
        $options = $this->customizer->isEnabled($plan)
            ? ['transfer_enable' => $traffic] + $this->customizer->baseOptions($plan)
                + ($this->customizer->hasAddonConfig($plan) ? [PlanCustomizationService::ADDON_KEY => $access['addon_ids']] : [])
            : null;

        $perMonth = [];
        foreach ($periods as $period => $months) {
            $amount = $this->quote($plan, $period, $options, $user);
            if ($amount !== null) {
                $perMonth[$period] = ['total' => $amount, 'per_month' => (int) round($amount / $months)];
            }
        }
        if ($perMonth === []) {
            return null;
        }
        $base = $perMonth[array_key_first($perMonth)];
        $best = array_key_first($perMonth);
        foreach ($perMonth as $period => $p) {
            if ($p['per_month'] < $perMonth[$best]['per_month']) {
                $best = $period;
            }
        }

        $shortfall = max(0, $need - $traffic);
        $rule = $this->customizer->topupRule($plan);
        $topup = $shortfall > 0 && $rule ? self::topupFor($rule, $shortfall) : null;
        $addonFees = [];
        $first = array_key_first($perMonth);
        $addonPrices = $this->customizer->optionalAddonPricesForPeriod($plan, $first);
        foreach ($access['addon_ids'] as $gid) {
            $addonFees[] = ['group_id' => $gid, 'name' => $this->groupLabel($plan, $gid),
                'cents' => (int) round(($addonPrices[$gid] ?? 0) / self::PERIOD_MONTHS[$first])];
        }

        $viable = $access['missing'] === [] && ($shortfall <= 0 || $topup !== null);
        $tight = $shortfall <= 0 && $need > 0 && $traffic < $need * self::TIGHT && !$rule;
        $monthly = $base['per_month'] + ($topup['cents'] ?? 0);
        $headroom = $need > 0 && $shortfall <= 0 ? (int) floor(($traffic / $need - 1) * 100) : null;

        $why = '每月 ¥' . P::yuan($base['per_month']) . "，{$traffic} GB 套餐流量";
        if ($addonFees !== []) {
            $why .= '（含' . implode('、', array_map(fn ($f) => $f['name'] . ' ¥' . P::yuan($f['cents']), $addonFees)) . '）';
        }
        if ($access['missing'] !== []) {
            $why .= '；不含' . implode('、', array_map([self::class, 'lineName'], $access['missing']));
        } elseif ($shortfall > 0) {
            $why .= $topup
                ? '；按客户每月约 ' . self::fmt($need) . " GB 的扣量还差 " . self::fmt($shortfall) . " GB，加购 {$topup['gb']} GB 约 ¥" . P::yuan($topup['cents'])
                : '；不够客户每月约 ' . self::fmt($need) . ' GB 的扣量，也不能加购';
        } else {
            $why .= '，按客户每月约 ' . self::fmt($need) . " GB 的扣量够用" . ($headroom !== null ? "（余量约 {$headroom}%）" : '');
        }

        $args = ['mode' => 'purchase', 'plan_id' => (int) $plan->id, 'period' => array_key_first($perMonth)];
        if ($options !== null && $traffic !== (int) $plan->transfer_enable) {
            $args['traffic_gb'] = $traffic;
        }
        if ($access['addon_ids'] !== []) {
            $args['addon_group_ids'] = $access['addon_ids'];
        }

        return [
            'kind' => 'monthly',
            'plan_id' => (int) $plan->id,
            'plan_name' => (string) $plan->name,
            'viable' => $viable,
            'tight' => $tight,
            'monthly_cents' => $monthly,
            'traffic_gb' => $traffic,
            'headroom_percent' => $headroom,
            'addon_lines' => $addonFees,
            'missing_lines' => $access['missing'],
            'topup' => $topup,
            'longer_period' => $best !== array_key_first($perMonth) ? [
                'period' => $best,
                'period_name' => P::periodName($best),
                'total_yuan' => P::yuan($perMonth[$best]['total']),
                'per_month_yuan' => P::yuan($perMonth[$best]['per_month']),
                'saving_percent' => (int) round((1 - $perMonth[$best]['per_month'] / max(1, $base['per_month'])) * 100),
            ] : null,
            'why' => $why,
            'quote_args' => $args,
        ];
    }

    private function paygOption(Plan $plan, User $user, float $need, array $mix, array $groupRates, PaygConfig $config): ?array
    {
        $unit = PaygConfig::unitPrice($plan);
        if ($unit === null) {
            return null;
        }
        // 按量是一次性套餐：可选增值组按单收一次费，和「按 GB 扣」对不上，只认基础组和随套餐包含的组
        $access = $this->lineAccess($plan, array_keys($mix), $groupRates, false);
        $raw = (int) round($need * $unit);
        $monthly = $need > 0 ? max($config->monthlyFloor, $raw) : 0;

        $wallet = PaygService::isWallet($user, $config) && (int) $user->plan_id === (int) $plan->id
            ? max(0, (int) $user->transfer_enable - (int) $user->u - (int) $user->d) / self::GB : 0.0;

        $suggestions = [];
        $targets = [1 => $need, 3 => $need * 3];
        foreach ($config->tiers as $tier) {
            $targets['tier' . $tier['amount']] = ceil($tier['amount'] / $unit);
        }
        foreach ($targets as $label => $gb) {
            if ($gb <= 0) {
                continue;
            }
            $traffic = $this->trafficFor($plan, $gb);
            $options = $this->customizer->isEnabled($plan) ? ['transfer_enable' => $traffic] + $this->customizer->baseOptions($plan) : null;
            $amount = $this->quote($plan, Plan::PERIOD_ONETIME, $options, $user);
            if ($amount === null || isset($suggestions[$traffic])) {
                continue;
            }
            $bonus = app(PaygService::class)->quoteBonus($plan, $amount, $user, $config);
            $total = $traffic + ($bonus['bonus_bytes'] + $bonus['first_bonus_bytes']) / self::GB;
            $lasts = $need > 0 ? ($total + $wallet) / $need : null;
            // 赠送档位只在一年内用得完时才值得提
            if (is_string($label) && ($lasts === null || $lasts > 12)) {
                continue;
            }
            $suggestions[$traffic] = [
                'traffic_gb' => $traffic,
                'price_yuan' => P::yuan($amount),
                'bonus_gb' => self::fmt(($bonus['bonus_bytes'] + $bonus['first_bonus_bytes']) / self::GB),
                'lasts_months' => $lasts !== null ? round($lasts, 1) : null,
                'quote_args' => ['mode' => 'purchase', 'plan_id' => (int) $plan->id, 'period' => Plan::PERIOD_ONETIME]
                    + ($options !== null && $traffic !== (int) $plan->transfer_enable ? ['traffic_gb' => $traffic] : []),
            ];
        }
        ksort($suggestions);
        $suggestions = array_slice(array_values($suggestions), 0, 4);

        $why = '用多少扣多少，每 GB ¥' . P::yuan($unit);
        $rates = array_filter(array_keys($mix), fn ($r) => (float) $r != 1.0);
        if ($rates !== []) {
            $why .= '（' . implode('、', array_map(fn ($r) => self::lineName($r) . ' ¥' . P::yuan($unit * (float) $r), $rates)) . '）';
        }
        $why .= '，按客户的用量每月约 ¥' . P::yuan($monthly);
        if ($need > 0 && $raw < $config->monthlyFloor) {
            $why .= '（用得少的月份按保底 ¥' . P::yuan($config->monthlyFloor) . ' 收）';
        }
        if ($access['missing'] !== []) {
            $why .= '；不含' . implode('、', array_map([self::class, 'lineName'], $access['missing']));
        }

        return [
            'kind' => 'payg',
            'plan_id' => (int) $plan->id,
            'plan_name' => (string) $plan->name,
            'viable' => $access['missing'] === [],
            'tight' => false,
            'monthly_cents' => $monthly,
            'traffic_gb' => null,
            'headroom_percent' => null,
            'addon_lines' => [],
            'missing_lines' => $access['missing'],
            'topup' => null,
            'longer_period' => null,
            'price_per_gb_yuan' => P::yuan($unit),
            'monthly_floor_yuan' => P::yuan($config->monthlyFloor),
            'wallet_remaining_gb' => $wallet > 0 ? self::fmt($wallet) : null,
            'wallet_lasts_months' => $wallet > 0 && $need > 0 ? round($wallet / $need, 1) : null,
            'recharge_suggestions' => $suggestions,
            'auto_topup_available' => $config->autoTopupEnable,
            'why' => $why,
            'quote_args' => $suggestions[0]['quote_args'] ?? ['mode' => 'purchase', 'plan_id' => (int) $plan->id, 'period' => Plan::PERIOD_ONETIME],
        ];
    }

    /** 给 AI 的形状：去掉内部排序字段，金额换成元 */
    private static function present(array $o): array
    {
        $out = [
            'kind' => $o['kind'] === 'payg' ? '按量' : '包月',
            'plan_id' => $o['plan_id'],
            'plan_name' => $o['plan_name'],
            'is_current_plan' => $o['is_current_plan'],
            'monthly_cost_yuan' => P::yuan($o['monthly_cents']),
            'why' => $o['why'],
            'quote_args' => $o['quote_args'],
        ];
        if ($o['kind'] === 'monthly') {
            $out += [
                'traffic_gb' => $o['traffic_gb'],
                'headroom_percent' => $o['headroom_percent'],
                'addon_lines' => array_map(fn ($f) => ['group_id' => $f['group_id'], 'name' => $f['name'], 'monthly_fee_yuan' => P::yuan($f['cents'])], $o['addon_lines']),
                'topup_needed' => $o['topup'] ? ['gb' => $o['topup']['gb'], 'cost_yuan' => P::yuan($o['topup']['cents'])] : null,
                'longer_period' => $o['longer_period'],
            ];
        } else {
            $out += [
                'price_per_gb_yuan' => $o['price_per_gb_yuan'],
                'monthly_floor_yuan' => $o['monthly_floor_yuan'],
                'wallet_remaining_gb' => $o['wallet_remaining_gb'],
                'wallet_lasts_months' => $o['wallet_lasts_months'],
                'recharge_suggestions' => $o['recharge_suggestions'],
                'auto_topup_available' => $o['auto_topup_available'],
            ];
        }
        if ($o['missing_lines'] !== []) {
            $out['missing_lines'] = array_map([self::class, 'lineName'], $o['missing_lines']);
        }
        return $out;
    }

    // ───────────────────────── 规格 ─────────────────────────

    /**
     * 客户要用的倍率在这个套餐里有没有：基础组 + 随套餐包含的组；不够的话（包月）勾上能补上的最便宜的可选增值组。
     *
     * @param list<string|int> $rates
     * @return array{addon_ids: list<int>, missing: list<string>}
     */
    private function lineAccess(Plan $plan, array $rates, array $groupRates, bool $allowOptional): array
    {
        $have = [];
        foreach (array_merge([(int) $plan->group_id], $this->customizer->includedAddonIds($plan)) as $gid) {
            $have += $groupRates[$gid] ?? [];
        }
        $rules = $this->customizer->addonRules($plan);
        $add = [];
        $missing = [];
        foreach ($rates as $rate) {
            if (isset($have[$rate])) {
                continue;
            }
            $pick = null;
            foreach ($allowOptional ? $this->customizer->sellableAddonIds($plan) : [] as $gid) {
                $price = (int) ($rules[$gid]['price'] ?? 0);
                if (isset($groupRates[$gid][$rate]) && ($pick === null || $price < $pick[1])) {
                    $pick = [$gid, $price];
                }
            }
            if ($pick === null) {
                $missing[] = (string) $rate;
                continue;
            }
            $add[$pick[0]] = true;
            $have += $groupRates[$pick[0]];
        }
        $ids = array_keys($add);
        sort($ids);
        return ['addon_ids' => $ids, 'missing' => $missing];
    }

    /** 可自选流量的套餐：够用的最小一档（GB），不够就给最大档；固定流量就是套餐流量。 */
    private function trafficFor(Plan $plan, float $needGb): int
    {
        $base = (int) $plan->transfer_enable;
        $mode = $this->customizer->mode($plan, 'transfer_enable');
        if ($mode === 'fixed' || $needGb <= $base) {
            return $base;
        }
        $rule = $plan->customization['transfer_enable'];
        if ($mode === 'choices') {
            foreach ($rule['choices'] as $value) {
                if ($value >= $needGb) {
                    return (int) $value;
                }
            }
            return (int) end($rule['choices']);
        }
        $step = max(1, (int) $rule['step']);
        return min((int) $rule['max'], $base + (int) ceil(($needGb - $base) / $step) * $step);
    }

    /**
     * 按套餐的加购规则凑够 $gb：按 GB 买的取起买量、按步长向上取整；只卖几档的取够用的最小一档。
     * 最大一档也不够返回 null。
     *
     * @param array $rule PlanCustomizationService::topupRule() 的结果
     * @return array{gb: int, cents: int}|null
     */
    public static function topupFor(array $rule, float $gb): ?array
    {
        if ($gb <= 0) {
            return null;
        }
        if ($rule['selection'] === 'choices') {
            foreach ($rule['choices'] as $choice) {
                if ($choice['gb'] >= $gb) {
                    return ['gb' => (int) $choice['gb'], 'cents' => (int) $choice['amount']];
                }
            }
            return null;
        }
        $min = (int) $rule['min_gb'];
        $step = max(1, (int) $rule['step_gb']);
        $want = max($min, (int) ceil($gb));
        $want = $min + (int) ceil(($want - $min) / $step) * $step;
        return $want <= (int) $rule['max_gb'] ? ['gb' => $want, 'cents' => $want * (int) $rule['price_per_gb']] : null;
    }

    private function quote(Plan $plan, string $period, ?array $options, User $user): ?int
    {
        try {
            return (int) $this->customizer->quote($plan, $period, $options, $user)['amount'];
        } catch (ApiException) {
            return null;
        }
    }

    private function groupLabel(Plan $plan, int $groupId): string
    {
        $label = trim((string) ($this->customizer->addonRules($plan)[$groupId]['label'] ?? ''));
        return $label !== '' ? $label : (PlanCustomizationService::groupNames()[$groupId] ?? '增值线路');
    }

    /** 权限组 → 组内显示中节点的倍率集合（键是 rateKey） */
    private static function ratesByGroup(): array
    {
        $out = [];
        foreach (Server::query()->where('show', true)->get(['group_ids', 'rate']) as $server) {
            $key = self::rateKey(self::rate($server->rate));
            foreach ((array) ($server->group_ids ?? []) as $gid) {
                $out[(int) $gid][$key] = true;
            }
        }
        return $out;
    }

    // ───────────────────────── 小工具 ─────────────────────────

    public static function rate(mixed $rate): float
    {
        return round((float) $rate, 2);
    }

    /** 倍率做数组键：1.0 → "1"，1.5 → "1.5"（PHP 会把 "1" 存成 int 1，比较一律用 isset） */
    public static function rateKey(float $rate): string
    {
        return $rate == (int) $rate ? (string) (int) $rate : rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
    }

    public static function lineName(string|int|float $rate): string
    {
        foreach (self::LINES as $line) {
            if ((float) $line['rate'] == (float) $rate) {
                return $line['name'] . "（×{$line['rate']}）";
            }
        }
        return '×' . self::rateKey((float) $rate) . ' 线路';
    }

    /** @param array<string, int> $billedByRate */
    private static function realGb(array $billedByRate): float
    {
        $real = 0.0;
        foreach ($billedByRate as $rate => $billed) {
            $real += $billed / (float) $rate;
        }
        return P::gb($real);
    }

    /** @return array<string, float> 倍率 => 实际流量占比 */
    private static function mix(array $billedByRate): array
    {
        $real = [];
        foreach ($billedByRate as $rate => $billed) {
            $real[(string) $rate] = $billed / (float) $rate;
        }
        $total = array_sum($real);
        if ($total <= 0) {
            return [];
        }
        arsort($real);
        // 不取整：扣量 = 实际 × 占比 × 倍率，取整后乘回去会差出零头
        return array_map(fn ($v) => $v / $total, $real);
    }

    private static function byLine(array $billedByRate): array
    {
        $out = [];
        foreach (self::mix($billedByRate) as $rate => $share) {
            $out[] = [
                'line' => self::lineName($rate),
                'share_percent' => (int) round($share * 100),
                'gb' => P::gb($billedByRate[$rate] / (float) $rate),
                'quota_gb' => P::gb($billedByRate[$rate]),
            ];
        }
        return $out;
    }

    private static function fmt(float $gb): float|int
    {
        $gb = round($gb, 1);
        return $gb == (int) $gb ? (int) $gb : $gb;
    }
}
