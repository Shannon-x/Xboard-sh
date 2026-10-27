<?php

namespace App\Mcp\Tools;

use App\Exceptions\ApiException;
use App\Models\Plan;
use App\Models\StatUser;
use App\Models\User;
use App\Services\AdvanceCycleService;
use App\Services\Mcp\McpPlanAdvisor;
use App\Services\Mcp\McpPresenter as P;
use App\Services\PlanCustomizationService;
use App\Services\PlanService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_traffic_options')]
#[Title('流量不够怎么办')]
#[Description('流量快用完或已用完时：先照最近 7 天的速度算出到重置日还差多少、建议加购多少 GB（projection），再列出客户现在能用的三种办法和各自的代价：加购流量（traffic_topup，按 GB 买，本周期有效）、流量重置包（reset_traffic，花钱把已用流量清零、到期日不变）、免费提前进入下个周期（advance_cycle，流量重置但到期日提前 30 天）。客户问「流量用完了怎么办」「能不能重置流量」时先调它。')]
#[IsReadOnly]
#[IsIdempotent]
class GetTrafficOptionsTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $advance = app(AdvanceCycleService::class)->preview(User::findOrFail($user->id));
        $user = User::with('plan')->findOrFail($user->id);
        $plan = $user->plan;

        $topup = null;
        $reset = null;
        $rule = null;
        if ($plan) {
            $rule = app(PlanCustomizationService::class)->topupRule($plan, $user);
            $topup = [
                'available' => $rule !== null && ($user->expired_at === null || (int) $user->expired_at > time()),
                'price_per_gb_yuan' => $rule ? P::yuan($rule['price_per_gb']) : null,
                'min_gb' => $rule['min_gb'] ?? null,
                'max_gb' => $rule['max_gb'] ?? null,
                'valid_until' => P::time(app(PlanCustomizationService::class)->topupValidUntil($user)),
                'how' => '先 quote_plan(mode=traffic_topup, topup_gb=…) 报价，客户确认后 create_order 同样参数。',
            ];
            $reset = self::resetPack($plan, $user);
        }

        $resetAt = $user->next_reset_at instanceof \DateTimeInterface ? $user->next_reset_at->getTimestamp() : $user->next_reset_at;

        return self::result([
            'projection' => self::projection($user, (int) $advance['remaining'], $resetAt ? (int) $resetAt : null, $rule, $reset),
            'remaining_gb' => P::gb($advance['remaining']),
            'used_gb' => P::gb($advance['used']),
            'total_gb' => P::gb($advance['transfer_enable']),
            'next_reset_at' => P::time($resetAt ? (int) $resetAt : null),
            'traffic_topup' => $topup,
            'reset_traffic_pack' => $reset,
            'advance_cycle' => [
                'available' => (bool) ($advance['eligible'] ?? false),
                'reason' => ($advance['eligible'] ?? false) ? null : $advance['message'],
                'cost' => '免费，但到期日提前 ' . $advance['consume_days'] . ' 天',
                'expires_at_now' => P::time($advance['old_expired_at']),
                'expires_at_after' => P::time($advance['new_expired_at']),
                'needs_used_percent' => (int) round($advance['used_ratio'] * 100),
                'how' => '向客户讲清到期日会提前，客户同意后调用 advance_traffic_cycle(confirm=true)。',
            ],
        ]);
    }

    /**
     * 照最近 7 天的速度，到下次重置（不重置的套餐看到期日）还差多少，建议加购多少、要多少钱。
     * 7 天按扣量算（已含倍率），与剩余流量同一口径；今天还没过完，不算进去。
     */
    private static function projection(User $user, int $remaining, ?int $resetAt, ?array $rule, ?array $reset): ?array
    {
        $until = $resetAt && $resetAt > time() ? $resetAt
            : ($user->expired_at && (int) $user->expired_at > time() ? (int) $user->expired_at : null);
        if ($until === null) {
            return null;
        }
        $today = now()->startOfDay()->getTimestamp();
        $week = (int) StatUser::where('user_id', $user->id)->where('record_type', 'd')
            ->where('record_at', '>=', $today - 7 * 86400)->where('record_at', '<', $today)
            ->sum(DB::raw('u + d'));
        $daily = $week / 7;
        $days = ($until - time()) / 86400;
        $need = $daily * $days;
        $short = max(0, $need - $remaining);

        $suggest = null;
        if ($short > 0 && $rule !== null) {
            // 多留 10%：用量每天有起伏，刚好够的量最后一两天容易断；最大一档也不够就先买最大一档
            $pick = McpPlanAdvisor::topupFor($rule, $short * 1.1 / P::BYTES_PER_GB)
                ?? McpPlanAdvisor::topupFor($rule, (float) $rule['max_gb']);
            $suggest = $pick ? ['gb' => $pick['gb'], 'cost_yuan' => P::yuan($pick['cents'])] : null;
        }

        $advice = match (true) {
            $daily <= 0 => '最近 7 天几乎没有用量，剩余流量够用。',
            $short <= 0 => '照最近 7 天的速度，剩余流量够用到 ' . P::date($until) . '。',
            $suggest !== null => '照最近 7 天的速度，到 ' . P::date($until) . ' 还差约 ' . round($short / P::BYTES_PER_GB, 1) . " GB，建议加购 {$suggest['gb']} GB（约 ¥{$suggest['cost_yuan']}）"
                . (($reset['available'] ?? false) ? "；也可以买流量重置包 ¥{$reset['price_yuan']}，哪个划算按差多少来定" : '') . '。',
            default => '照最近 7 天的速度，到 ' . P::date($until) . ' 还差约 ' . round($short / P::BYTES_PER_GB, 1) . ' GB；这个套餐不能按 GB 加购，看看重置包或提前进入下个周期。',
        };

        return [
            'avg_daily_gb_last_7_days' => P::gb($daily),
            'days_until' => round($days, 1),
            'until' => P::date($until),
            'projected_need_gb' => P::gb($need),
            'projected_shortfall_gb' => P::gb($short),
            'suggested_topup' => $suggest,
            'advice' => $advice,
        ];
    }

    private static function resetPack(Plan $plan, User $user): array
    {
        $price = $plan->prices[Plan::PERIOD_RESET_TRAFFIC] ?? null;
        if ($price === null || !is_numeric($price) || (float) $price <= 0) {
            return ['available' => false, 'reason' => '当前套餐没有流量重置包。'];
        }
        try {
            (new PlanService($plan))->validatePurchase($user, Plan::PERIOD_RESET_TRAFFIC);
        } catch (ApiException $e) {
            return ['available' => false, 'reason' => $e->getMessage(), 'price_yuan' => number_format((float) $price, 2, '.', '')];
        }
        return [
            'available' => true,
            'price_yuan' => number_format((float) $price, 2, '.', ''),
            'how' => '先 quote_plan(mode=reset_traffic) 报价，客户确认后 create_order(mode=reset_traffic)，付款后立刻清零。',
        ];
    }
}
