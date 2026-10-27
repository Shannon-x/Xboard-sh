<?php

namespace App\Services\Mcp;

use App\Exceptions\ApiException;
use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use App\Services\RenewService;

/**
 * 把 AI 传来的「买什么」翻译成面板下单用的 (套餐, 周期, 选项)，报价和下单共用。
 *
 *   renew          续费当前套餐：套餐、增值线路沿用续费助手算出的规格（RenewService::resolveSpec），
 *                  周期默认上次的周期，可改。不会把客户已买的线路弄丢。
 *   purchase       新购或换套餐：plan_id + period，选项用套餐默认（要定制流量 / 线路请去面板）。
 *   traffic_topup  当前套餐本周期按 GB 加购流量：topup_gb。
 *   reset_traffic  当前套餐的流量重置包（套餐设了 reset_traffic 价格才能买）：已用流量清零，到期日不变。
 */
final class McpOrderSpec
{
    public const MODES = ['renew', 'purchase', 'traffic_topup', 'reset_traffic'];

    /** 给 AI 看的 mode 说明，quote_plan 与 create_order 共用 */
    public const MODE_HELP = 'renew = 续费当前套餐；purchase = 新购或换成 plan_id 指定的套餐；traffic_topup = 当前套餐本周期加购流量（买流量）；reset_traffic = 买流量重置包，已用流量清零、到期日不变。';

    /**
     * @return array{mode: string, plan: Plan, period: string, options: ?array}
     * @throws ApiException
     */
    public static function resolve(User $user, array $args): array
    {
        $mode = (string) ($args['mode'] ?? 'purchase');
        if (!in_array($mode, self::MODES, true)) {
            throw new ApiException('mode 只能是 renew（续费）、purchase（新购 / 换套餐）、traffic_topup（加购流量）或 reset_traffic（流量重置包）。');
        }

        if ($mode === 'renew') {
            $spec = app(RenewService::class)->resolveSpec($user);
            if (!$spec['available']) {
                throw new ApiException('现在不能续费：' . $spec['message']);
            }
            $plan = Plan::find($spec['plan_id']);
            $period = isset($args['period']) && $args['period'] !== '' ? self::period((string) $args['period']) : (string) $spec['period'];
            if (!$plan) {
                throw new ApiException('套餐已不存在');
            }
            return ['mode' => $mode, 'plan' => $plan, 'period' => $period, 'options' => $spec['options']];
        }

        if ($mode === 'traffic_topup') {
            if (!$user->plan_id || !($plan = Plan::find($user->plan_id))) {
                throw new ApiException('当前没有订阅，不能加购流量。');
            }
            $gb = (int) ($args['topup_gb'] ?? 0);
            if ($gb < 1) {
                throw new ApiException('加购流量需要 topup_gb（要加多少 GB）。');
            }
            return ['mode' => $mode, 'plan' => $plan, 'period' => Plan::PERIOD_TRAFFIC_TOPUP, 'options' => ['topup_gb' => $gb]];
        }

        if ($mode === 'reset_traffic') {
            if (!$user->plan_id || !($plan = Plan::find($user->plan_id))) {
                throw new ApiException('当前没有订阅，不能买流量重置包。');
            }
            // 能不能买（套餐没设重置包价格、订阅已到期……）交给 PlanService::validatePurchase，与面板同一套
            return ['mode' => $mode, 'plan' => $plan, 'period' => Plan::PERIOD_RESET_TRAFFIC, 'options' => null];
        }

        $planId = (int) ($args['plan_id'] ?? 0);
        $plan = $planId ? Plan::find($planId) : null;
        if (!$plan || !(new PlanService($plan))->isPlanAvailableForUser($plan, $user)) {
            throw new ApiException('找不到这个套餐，或它现在不能购买（可能已下架或售罄）。请先用 list_plans 查在售套餐。');
        }
        if (empty($args['period'])) {
            throw new ApiException('需要 period（付款周期），例如 monthly、quarterly、yearly、onetime。');
        }
        return ['mode' => $mode, 'plan' => $plan, 'period' => self::period((string) $args['period']), 'options' => null];
    }

    private static function period(string $period): string
    {
        $key = PlanService::getPeriodKey($period);
        if ($key === Plan::PERIOD_TRAFFIC_TOPUP || $key === Plan::PERIOD_RESET_TRAFFIC) {
            throw new ApiException('加购流量请用 mode=traffic_topup，流量重置包请用 mode=reset_traffic。');
        }
        if (!Plan::isValidPeriod($key)) {
            throw new ApiException("不认识的付款周期：{$period}。可用：monthly、quarterly、half_yearly、yearly、two_yearly、three_yearly、onetime。");
        }
        return $key;
    }
}
