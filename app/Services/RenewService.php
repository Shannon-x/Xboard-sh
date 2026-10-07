<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Jobs\SendEmailJob;
use App\Models\BillingDocument;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\BillingDocumentService;
use App\Services\Billing\BillingPayService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;

/**
 * 续费助手：快捷续费（按上次配置一键下单）与自动续费（余额足够时后台代下单）。
 *
 * 两者共用同一份「续费规格」：当前套餐 + 上一笔已完成订单的周期 + 用户此刻的 plan_options
 * （含增值线路），用现有 quote 报价、扣专属折扣，不用优惠券。金额是服务端算的：
 * amount 是给用户看的折后价，list_amount 是折前价 —— 下单守卫 expected_amount 比的是折前价
 * （createFromRequest 里先比报价再算 VIP 折扣），所以前端回传的必须是 list_amount。
 *
 * 快捷续费不是"复制上一单"：套餐配了可选购增值组时，规格里附带 addons（逐项本周期价格 +
 * 上次是否已选），用户可以在下单前加上或去掉，改了就用 /user/plan/quote 重新报价。
 * 自动续费不走这一步，永远按上次配置原样续。
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
            'amount' => $amount, 'list_amount' => $listAmount, 'discount' => $discount,
            'summary' => $this->summary($plan, $user, $quote['options']),
            'addons' => $this->addonChoices($plan, $user, $period, $quote['options']),
        ];
    }

    /**
     * 下单前可勾选的增值线路：套餐里 mode=optional 的组，逐项标出本周期价格（折前）与上次是否已选。
     * included 的组随套餐自动生效、管理员手工授予的组用户已经有了，都不列进来让用户"买"。
     * 没有可选组时返回 []，前端保持一键续费。
     */
    private function addonChoices(Plan $plan, User $user, string $period, ?array $options): array
    {
        $customizer = app(PlanCustomizationService::class);
        $prices = $customizer->optionalAddonPricesForPeriod($plan, $period);
        if ($prices === []) return [];
        $selected = array_map('intval', $options[PlanCustomizationService::ADDON_KEY] ?? []);
        $display = $customizer->addonGroupsForDisplay($plan, $user);
        $choices = [];
        foreach ($prices as $groupId => $price) {
            $info = $display[(string) $groupId] ?? null;
            if ($info === null || !empty($info['admin_granted'])) continue;
            $choices[] = [
                'id' => (int) $groupId,
                'name' => $info['name'],
                'server_count' => (int) $info['server_count'],
                'price' => $price,
                'selected' => in_array((int) $groupId, $selected, true),
            ];
        }
        return $choices;
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
            $this->notifyFailure($user, $spec, null);
            return ['status' => 'unavailable', 'reason' => $spec['reason']];
        }
        $shortfall = $spec['amount'] - (int) ($user->balance ?? 0);
        if ($shortfall > 0) {
            $this->notifyFailure($user, $spec, $shortfall);
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
                // 报价之后余额被别处花掉了：按此刻的余额重算差额（至少 1 分）
                $shortfall = max(1, (int) $spec['amount'] - (int) (User::where('id', $user->id)->value('balance') ?? 0));
                $this->notifyFailure($user, $spec, $shortfall);
                return ['status' => 'insufficient', 'shortfall' => $shortfall];
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

    /**
     * 没扣成的通知（同一到期日只发一次）。站点没有充值入口，所以不叫用户「去充值」：
     * 先给在线续费的链接，再说余额还能从礼品卡、佣金转入补；到期后才补上的会续，但周期从续上那一刻算，
     * 中间停用一段 —— 这一点提前说清楚。文案跟收据 / 账单同一个语言设置。
     */
    private function notifyFailure(User $user, array $spec, ?int $shortfall): void
    {
        $expiry = (int) $user->expired_at;
        if ((int) ($user->auto_renew_notified_at ?? 0) === $expiry) return;   // 同一到期日只提醒一次
        User::withoutEvents(fn () => User::where('id', $user->id)->update(['auto_renew_notified_at' => $expiry]));
        [$subject, $content] = BillingDocumentService::withLocale(function () use ($user, $spec, $shortfall, $expiry) {
            $plans = rtrim((string) admin_setting('app_url', ''), '/') . '/plans';
            $url = $plans . '?mode=renew';
            // 这期的账单已经寄过：直接给免登录付款链接，补差额不用先登录
            $invoice = BillingDocument::where('user_id', $user->id)->where('kind', BillingDocument::KIND_INVOICE)->where('expired_at', $expiry)->first();
            if ($invoice && BillingPayService::linkable($invoice)) {
                $url = BillingPayService::url($invoice);
            }
            if ($shortfall !== null) {
                $lines = [
                    __('billing.auto_renew.failed_short', ['amount' => app(BillingDocumentService::class)->money($shortfall)]),
                    __('billing.auto_renew.failed_short_pay', ['url' => $url]),
                    __('billing.auto_renew.failed_short_topup', ['expiry' => date('Y-m-d H:i', $expiry)]),
                ];
                if (self::graceHours() > 0) {
                    $lines[] = __('billing.auto_renew.failed_short_grace', ['deadline' => date('Y-m-d H:i', $expiry + self::graceHours() * 3600)]);
                }
            } else {
                $key = 'billing.auto_renew.reason.' . ($spec['reason'] ?? '');
                $reason = Lang::has($key) ? __($key, ['detail' => (string) ($spec['message'] ?? '')]) : (string) ($spec['message'] ?? '');
                // 原套餐续不了（下架 / 没有可买周期 / 当前配置买不了）：给套餐列表，让用户换一个
                $lines = [
                    __('billing.auto_renew.failed_reason', ['reason' => $reason]),
                    __('billing.auto_renew.failed_reason_next', ['url' => $plans]),
                ];
            }
            return [__('billing.auto_renew.failed_subject'), implode("\n", $lines)];
        });
        $this->send($user, $subject, $content);
    }

    private function notifySuccess(User $user, Order $order, array $spec): void
    {
        [$subject, $content] = BillingDocumentService::withLocale(fn () => [
            __('billing.auto_renew.success_subject'),
            __('billing.auto_renew.success', [
                'plan' => $spec['plan_name'],
                'period' => Lang::has('billing.period.' . $spec['period']) ? __('billing.period.' . $spec['period']) : $spec['period_name'],
                'amount' => app(BillingDocumentService::class)->money((int) $spec['amount']),
                'date' => $user->expired_at ? date('Y-m-d H:i', (int) $user->expired_at) : '—',
                'trade_no' => $order->trade_no,
            ]),
        ]);
        // 开了收据功能时，订单开通会发带 PDF 的收据（注明自动续费扣款），这封纯文字通知就只走 Telegram
        $this->send($user, $subject, $content, email: !BillingDocumentService::receiptEnabled());
    }

    private function send(User $user, string $subject, string $content, bool $email = true): void
    {
        $appName = admin_setting('app_name', 'XBoard');
        if ($email) {
            SendEmailJob::dispatch([
                'email' => $user->email,
                'user_id' => $user->id,
                'category' => \App\Services\Notification\NotificationPreference::BILLING,
                'subject' => $subject . ' - ' . $appName,
                'template_name' => 'notify',
                'template_value' => ['name' => $appName, 'url' => admin_setting('app_url'), 'content' => $content],
            ]);
        }
        if ($user->telegram_id) {
            try {
                app(TelegramService::class)->sendMessage((int) $user->telegram_id, $subject . "\n" . $content);
            } catch (\Throwable $e) {
                Log::warning('auto_renew.telegram_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
