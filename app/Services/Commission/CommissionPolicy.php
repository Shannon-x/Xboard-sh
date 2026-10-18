<?php

namespace App\Services\Commission;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * 某个邀请人**当前生效**的返佣规则：比例 + 返佣方式（首单 / 周期）+ 各自来源。
 * 发佣（OrderService::setInvite）与邀请页展示（InviteController::fetch）都从这里取，口径只有一份。
 *
 * 比例：
 *   user    管理员在用户编辑里单独设了比例（非空非 0，沿用老的 truthy 语义）
 *   tier    阶梯比例开着，且付费被邀请人数够到某一档
 *   default 站点默认 invite_commission
 * 方式：
 *   user    管理员单独设了「周期 / 一次性」
 *   applied 用户申请了周期返佣且站长已通过，有效付费被邀请人仍 ≥ 门槛（掉下去自动取消资格，见 PeriodCommission）
 *   default commission_first_time_enable 决定
 */
final class CommissionPolicy
{
    public const MODE_FIRST_ORDER = 'first_order';
    public const MODE_PERIOD = 'period';

    /** @return array{rate:int, rate_source:string, mode:string, mode_source:string, paid_invitee_count:int, active_paid_invitee_count:int} */
    public static function forInviter(User $inviter): array
    {
        $paid = InviteeStats::paidInviteeCount((int) $inviter->id);
        $active = InviteeStats::activePaidInviteeCount((int) $inviter->id);

        [$rate, $rateSource] = self::resolveRate($inviter, $paid);
        [$mode, $modeSource] = self::resolveMode($inviter, $active);

        return [
            'rate' => $rate,
            'rate_source' => $rateSource,
            'mode' => $mode,
            'mode_source' => $modeSource,
            'paid_invitee_count' => $paid,
            'active_paid_invitee_count' => $active,
        ];
    }

    /** 邀请页用的完整视图：生效规则 + 阶梯表 + 周期返佣门槛与申请状态 */
    public static function view(User $inviter): array
    {
        $policy = self::forInviter($inviter);
        $tiers = self::tiers();
        $next = null;
        foreach ($tiers['levels'] as $level) {
            if ($level['min'] > $policy['paid_invitee_count']) {
                $next = $level;
                break;
            }
        }
        $auto = self::periodAuto();

        return [
            'rate' => $policy['rate'],
            'rate_source' => $policy['rate_source'],
            'mode' => $policy['mode'],
            'mode_source' => $policy['mode_source'],
            'tiers' => [
                'enable' => $tiers['enable'],
                'base_rate' => $tiers['base_rate'],
                'levels' => $tiers['levels'],
                'next' => $tiers['enable'] && $policy['rate_source'] !== 'user' ? $next : null,
            ],
            // 键名沿用上一版（前端按它判断有没有这块）；enable 现在的意思是「开放申请」
            'period_auto' => [
                'enable' => $auto['enable'],
                'min_invitees' => $auto['min_invitees'],
                'active_paid_invitee_count' => $policy['active_paid_invitee_count'],
                'application' => PeriodCommission::state($inviter, $policy['active_paid_invitee_count']),
            ],
        ];
    }

    // ---------------------------------------------------------------- 比例

    /** @return array{0:int,1:string} */
    private static function resolveRate(User $inviter, int $paidInvitees): array
    {
        // 保持原 truthy 语义（commission_rate 为 0/null 都回落默认），理由见 OrderService::setInvite 的历史注释
        if ($inviter->commission_rate) {
            return [self::clamp($inviter->commission_rate), 'user'];
        }
        $tiers = self::tiers();
        if ($tiers['enable']) {
            $matched = null;
            foreach ($tiers['levels'] as $level) {
                if ($paidInvitees >= $level['min']) {
                    $matched = $level;
                }
            }
            if ($matched !== null) {
                return [$matched['rate'], 'tier'];
            }
        }
        return [$tiers['base_rate'], 'default'];
    }

    /** @return array{enable:bool, base_rate:int, levels:list<array{min:int, rate:int}>} */
    public static function tiers(): array
    {
        $enable = (bool) (int) admin_setting('commission_tier_enable', 0);
        $base = self::clamp(admin_setting('invite_commission', 10));
        $levels = [];
        foreach ([2 => [10, 15], 3 => [30, 20]] as $n => [$defaultMin, $defaultRate]) {
            $min = (int) admin_setting("commission_tier_{$n}_min", $defaultMin);
            $rate = self::clamp(admin_setting("commission_tier_{$n}_rate", $defaultRate));
            if ($min > 0) {
                $levels[] = ['min' => $min, 'rate' => $rate];
            }
        }
        usort($levels, fn ($a, $b) => $a['min'] <=> $b['min']);
        return ['enable' => $enable, 'base_rate' => $base, 'levels' => $enable ? $levels : []];
    }

    // ---------------------------------------------------------------- 方式

    /** @return array{0:string,1:string} */
    private static function resolveMode(User $inviter, int $activePaidInvitees): array
    {
        $type = (int) $inviter->commission_type;
        if ($type === User::COMMISSION_TYPE_PERIOD) {
            return [self::MODE_PERIOD, 'user'];
        }
        if ($type === User::COMMISSION_TYPE_ONETIME) {
            return [self::MODE_FIRST_ORDER, 'user'];
        }
        // 申请制：通过且仍达标才是周期返佣。这条路径在下单时（OrderService::setInvite）也会走到，
        // 查询或取消资格出任何错都只当「没开通」处理，绝不让它拦下单
        try {
            if (PeriodCommission::grants($inviter, $activePaidInvitees)) {
                return [self::MODE_PERIOD, 'applied'];
            }
        } catch (\Throwable $e) {
            Log::warning('[commission] 周期返佣资格判断失败，按未开通处理', ['user_id' => $inviter->id, 'msg' => $e->getMessage()]);
        }
        return [(bool) admin_setting('commission_first_time_enable', true) ? self::MODE_FIRST_ORDER : self::MODE_PERIOD, 'default'];
    }

    /**
     * 周期返佣申请的开关与门槛。键名是上一版「达标自动开启」留下的，语义已改成「达标可申请」。
     *
     * @return array{enable:bool, min_invitees:int}
     */
    public static function periodAuto(): array
    {
        return [
            'enable' => (bool) (int) admin_setting('commission_period_auto_enable', 0),
            'min_invitees' => max(1, (int) admin_setting('commission_period_auto_min_invitees', 50)),
        ];
    }

    private static function clamp(mixed $rate): int
    {
        return (int) max(0, min(100, (float) $rate));
    }
}
