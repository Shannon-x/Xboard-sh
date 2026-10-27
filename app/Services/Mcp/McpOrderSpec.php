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
 */
final class McpOrderSpec
{
    public const MODES = ['renew', 'purchase', 'traffic_topup'];

    /**
     * @return array{mode: string, plan: Plan, period: string, options: ?array}
     * @throws ApiException
     */
    public static function resolve(User $user, array $args): array
    {
        $mode = (string) ($args['mode'] ?? 'purchase');
        if (!in_array($mode, self::MODES, true)) {
            throw new ApiException('mode 只能是 renew（续费）、purchase（新购 / 换套餐）或 traffic_topup（加购流量）。');
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
        if ($key === Plan::PERIOD_TRAFFIC_TOPUP || !Plan::isValidPeriod($key)) {
            throw new ApiException("不认识的付款周期：{$period}。可用：monthly、quarterly、half_yearly、yearly、two_yearly、three_yearly、onetime、reset_traffic。");
        }
        return $key;
    }
}
