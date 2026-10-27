<?php

namespace App\Mcp\Tools;

use App\Exceptions\ApiException;
use App\Models\Plan;
use App\Models\User;
use App\Services\AdvanceCycleService;
use App\Services\Mcp\McpPresenter as P;
use App\Services\PlanCustomizationService;
use App\Services\PlanService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
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
#[Description('流量快用完或已用完时，列出客户现在能用的三种办法和各自的代价：加购流量（traffic_topup，按 GB 买，本周期有效）、流量重置包（reset_traffic，花钱把已用流量清零、到期日不变）、免费提前进入下个周期（advance_cycle，流量重置但到期日提前 30 天）。客户问「流量用完了怎么办」「能不能重置流量」时先调它。')]
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

        return self::result([
            'remaining_gb' => P::gb($advance['remaining']),
            'used_gb' => P::gb($advance['used']),
            'total_gb' => P::gb($advance['transfer_enable']),
            'next_reset_at' => P::time($user->next_reset_at instanceof \DateTimeInterface ? $user->next_reset_at->getTimestamp() : $user->next_reset_at),
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
