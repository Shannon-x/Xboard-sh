<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Jobs\SendEmailJob;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 续费助手：快捷续费（按上次配置一键下单）与自动续费（余额足够时后台代下单）。
 *
 * 两者共用同一份「续费规格」：当前套餐 + 上一笔已完成订单的周期 + 用户此刻的 plan_options
 * （含增值线路），用现有 quote 报价、扣专属折扣，不用优惠券。金额是服务端算的，
 * 前端一键续费把它当 expected_amount 回传做守卫。
 *
 * 自动续费的硬规则：余额 ≥ 整笔应付才下单；下单后金额没归零就整体回滚，
 * 绝不扣一部分再留一个待付订单。失败通知同一到期日只发一次。
 */
class RenewService
{
    public static function siteEnabled(): bool
    {
        return (bool) admin_setting('auto_renew_enable', 1);
    }

    public static function leadHours(): int
    {
        return max(1, min(168, (int) admin_setting('auto_renew_lead_hours', 24)));
    }

    public static function graceHours(): int
    {
        return max(0, min(720, (int) admin_setting('auto_renew_grace_hours', 72)));
    }

    public static function promptDays(): int
    {
        return max(0, min(60, (int) admin_setting('renew_prompt_days', 7)));
    }

    /** getSubscribe 下发：提醒天数 + 续费规格 + 自动续费状态。纯新增键，旧前端不读。 */
    public function payload(User $user): array
    {
        $spec = $this->resolveSpec($user);
        return ['prompt_days' => self::promptDays(), 'spec' => $spec, 'auto' => $this->autoState($user, $spec)];
    }

    /**
     * 上次的订阅规格，以及现在按它续一次要付多少。
     * 不可续时 available=false 并给出原因（套餐下架 / 不允许续费 / 周期已下架 / 不限时套餐…）。
     */
    public function resolveSpec(User $user): array
    {
        $no = fn (string $reason, string $message) => ['available' => false, 'reason' => $reason, 'message' => $message];
        if (!$user->plan_id) return $no('no_plan', '当前没有订阅');
        if ($user->expired_at === null) return $no('lifetime', '不限时套餐无需续费');
        $plan = Plan::find($user->plan_id);
        if (!$plan) return $no('plan_missing', '套餐已不存在');

        $priced = [];
        foreach ((array) ($plan->prices ?? []) as $period => $price) {
            if (is_numeric($price) && $price > 0 && $period !== Plan::PERIOD_RESET_TRAFFIC && $period !== Plan::PERIOD_ONETIME) {
                $priced[] = (string) $period;
            }
        }
        $last = Order::where('user_id', $user->id)->where('status', Order::STATUS_COMPLETED)
            ->whereIn('type', [Order::TYPE_NEW_PURCHASE, Order::TYPE_RENEWAL, Order::TYPE_UPGRADE])
            ->orderByDesc('id')->first();
        $period = $last && (int) $last->plan_id === (int) $plan->id ? PlanService::getPeriodKey((string) $last->period) : null;
        if ($period === null || !in_array($period, $priced, true)) {
            // 没有可参考的上一单，或它的周期已下架：优先月付，其次任何仍在售的周期
            $period = in_array(Plan::PERIOD_MONTHLY, $priced, true) ? Plan::PERIOD_MONTHLY : ($priced[0] ?? null);
        }
        if ($period === null) return $no('no_period', '套餐当前没有可购买的周期');

        try {
            (new PlanService($plan))->validatePurchase($user, $period);
            $quote = app(PlanCustomizationService::class)->quote($plan, $period, null, $user);
        } catch (ApiException $e) {
            return $no('not_purchasable', $e->getMessage());
        }
        $listAmount = (int) $quote['amount'];
        $discount = max(0, min(100, (int) ($user->discount ?? 0)));
        $amount = $discount > 0 ? max(0, $listAmount - (int) round($listAmount * $discount / 100)) : $listAmount;

        return [
            'available' => true, 'reason' => null, 'message' => null,
            'plan_id' => (int) $plan->id, 'plan_name' => (string) $plan->name,
            'period' => $period, 'period_name' => Plan::getAvailablePeriods()[$period]['name'] ?? $period,
            'options' => $quote['options'],
            'amount' => $amount, 'list_amount' => $listAmount,
            'summary' => $this->summary($plan, $user, $quote['options']),
        ];
    }

    /** 自动续费开关状态与余额是否够下一次续费。 */
    public function autoState(User $user, array $spec): array
    {
        $balance = (int) ($user->balance ?? 0);
        $amount = $spec['available'] ? (int) $spec['amount'] : null;
        return [
            'enabled' => (bool) $user->auto_renew,
            'site_enabled' => self::siteEnabled(),
            'lead_hours' => self::leadHours(),
            'grace_hours' => self::graceHours(),
            'amount' => $amount,
            'balance' => $balance,
            'balance_enough' => $amount !== null && $balance >= $amount,
            'shortfall' => $amount !== null ? max(0, $amount - $balance) : null,
        ];
    }

    /**
     * 后台任务：为一个用户尝试自动续费。
     * @return array{status: string, reason?: string, trade_no?: string, amount?: int, shortfall?: int}
     */
    public function attemptAutoRenew(User $user, bool $dryRun = false): array
    {
        $user = User::find($user->id);
        if (!self::siteEnabled()) return ['status' => 'skipped', 'reason' => 'site_disabled'];
        if (!$user || !$user->auto_renew || $user->banned || !$user->plan_id) return ['status' => 'skipped', 'reason' => 'not_eligible'];
        if (app(UserService::class)->isNotCompleteOrderByUserId($user->id)) return ['status' => 'skipped', 'reason' => 'pending_order'];

        $spec = $this->resolveSpec($user);
        if (!$spec['available']) {
            $this->notifyFailure($user, $spec['message'], null);
            return ['status' => 'unavailable', 'reason' => $spec['reason']];
        }
        $shortfall = $spec['amount'] - (int) ($user->balance ?? 0);
        if ($shortfall > 0) {
            $this->notifyFailure($user, null, $shortfall);
            return ['status' => 'insufficient', 'shortfall' => $shortfall];
        }
        if ($dryRun) return ['status' => 'would_renew', 'amount' => $spec['amount']];

        $plan = Plan::find($spec['plan_id']);
        try {
            $order = DB::transaction(function () use ($user, $plan, $spec) {
                $order = OrderService::createFromRequest($user, $plan, $spec['period'], null, $spec['options'], null);
                // createFromRequest 里已按余额抵扣；报价到此刻之间余额被别处消费了就整体回滚，不留待付单
                if ((int) $order->total_amount !== 0) {
                    throw new \RuntimeException('balance_short');
                }
                $order->auto_renew = 1;
                if (!$order->save()) throw new \RuntimeException('order save failed');
                return $order;
            });
        } catch (\Throwable $e) {
            if ($e->getMessage() === 'balance_short') {
                $this->notifyFailure($user, null, 1);
                return ['status' => 'insufficient', 'shortfall' => 1];
            }
            Log::error('auto_renew.create_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            return ['status' => 'error', 'reason' => $e->getMessage()];
        }

        // 金额已归零：走与收银台 0 元订单完全相同的 paid() → OrderHandleJob 开通链路
        if (!(new OrderService($order))->paid('auto_renew:' . $order->trade_no)) {
            // 钱已扣、单已建、没能开通：单子仍是 0 元待付，用户在收银台点一下即可完成；同时告警
            Log::error('auto_renew.paid_failed', ['user_id' => $user->id, 'trade_no' => $order->trade_no]);
            return ['status' => 'error', 'reason' => 'paid_failed', 'trade_no' => $order->trade_no];
        }
        $this->notifySuccess($user->fresh(), $order, $spec);
        return ['status' => 'renewed', 'trade_no' => $order->trade_no, 'amount' => $spec['amount']];
    }

    /** 给用户看的规格摘要：流量 / 设备 / 速度 / 增值线路名。 */
    private function summary(Plan $plan, User $user, ?array $options): array
    {
        $pick = fn (string $key) => $options !== null && array_key_exists($key, $options) ? $options[$key] : $plan->{$key};
        return [
            'transfer_enable' => $pick('transfer_enable') === null ? null : (int) $pick('transfer_enable'),
            'device_limit' => $pick('device_limit') === null ? null : (int) $pick('device_limit'),
            'speed_limit' => $pick('speed_limit') === null ? null : (int) $pick('speed_limit'),
            'addon_names' => array_column(app(PlanCustomizationService::class)->addonGroupsForUser($plan, $user), 'name'),
        ];
    }

    private function notifyFailure(User $user, ?string $reasonText, ?int $shortfall): void
    {
        $expiry = (int) $user->expired_at;
        if ((int) ($user->auto_renew_notified_at ?? 0) === $expiry) return;   // 同一到期日只提醒一次
        User::withoutEvents(fn () => User::where('id', $user->id)->update(['auto_renew_notified_at' => $expiry]));
        $content = $shortfall !== null
            ? sprintf('自动续费未执行：余额不足，还差 ¥%.2f。请在 %s 前充值，充值后系统会自动续费。', $shortfall / 100, date('Y-m-d H:i', $expiry))
            : '自动续费未执行：' . $reasonText . '。请手动续费或联系客服。';
        $this->send($user, '自动续费未执行', $content);
    }

    private function notifySuccess(User $user, Order $order, array $spec): void
    {
        $content = sprintf('已自动续费：%s · %s · ¥%.2f，已从余额扣除。新到期时间：%s。订单号 %s。',
            $spec['plan_name'], $spec['period_name'], $spec['amount'] / 100,
            $user->expired_at ? date('Y-m-d H:i', (int) $user->expired_at) : '—', $order->trade_no);
        $this->send($user, '已自动续费', $content);
    }

    private function send(User $user, string $subject, string $content): void
    {
        $appName = admin_setting('app_name', 'XBoard');
        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => $subject . ' - ' . $appName,
            'template_name' => 'notify',
            'template_value' => ['name' => $appName, 'url' => admin_setting('app_url'), 'content' => $content],
        ]);
        if ($user->telegram_id) {
            try {
                app(TelegramService::class)->sendMessage((int) $user->telegram_id, $subject . "\n" . $content);
            } catch (\Throwable $e) {
                Log::warning('auto_renew.telegram_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
