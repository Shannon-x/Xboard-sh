<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Jobs\SendEmailJob;
use App\Models\Plan;
use App\Models\PlanRestockNotify;
use App\Models\User;
use App\Services\Billing\BillingDocumentService;
use App\Support\PlanCatalog;
use Illuminate\Support\Collection;

/**
 * 售罄套餐的到货提醒。
 *
 * 登记：登录用户一键登记（用账户邮箱），游客留邮箱；同一套餐同一邮箱一行，重复登记只是重新等待。
 * 发信：定时任务（plan:restock-notify）看有人在等的套餐有没有位置 —— 本套餐放出了席位，
 * 或者同一产品（目录标签 family:）开了新一车且有位 —— 有就给每个在等的人发一封，记上 notified_at。
 * 每次登记最多一封：之后又满、又放位都不会再发，想再等可以重新登记。
 *
 * 有没有位置用 PlanCapacity（与下单校验 PlanService::hasCapacity 同一口径）；邮件里只说「有位了」，
 * 不写剩几个席位。这一封是用户自己要的，不受通知偏好的营销 / 公告开关影响（与验证码同类）。
 */
class RestockNotifier
{
    /** 一个邮箱最多同时等几款：游客接口不能被拿来给别人的邮箱登记一大串 */
    public const MAX_PENDING_PER_EMAIL = 20;
    /** 登记超过这么久还没到货就不再等（定时任务顺手清掉） */
    public const PENDING_TTL_DAYS = 180;

    public function subscribe(Plan $plan, string $email, ?User $user = null): void
    {
        $email = mb_strtolower(trim($email));
        $this->assertSubscribable($plan);
        $existing = PlanRestockNotify::where('plan_id', $plan->id)->where('email', $email)->first();
        if (!$existing && PlanRestockNotify::where('email', $email)->whereNull('notified_at')->count() >= self::MAX_PENDING_PER_EMAIL) {
            throw new ApiException('登记的到货提醒太多了，请先取消一些');
        }
        $userId = $user?->id ?? User::where('email', $email)->value('id');
        if ($existing) {
            $existing->forceFill(['notified_at' => null, 'user_id' => $userId ?? $existing->user_id])->save();
            return;
        }
        PlanRestockNotify::create(['plan_id' => $plan->id, 'email' => $email, 'user_id' => $userId]);
    }

    public function cancel(Plan $plan, string $email): void
    {
        PlanRestockNotify::where('plan_id', $plan->id)->where('email', mb_strtolower(trim($email)))
            ->whereNull('notified_at')->delete();
    }

    /** 登录用户在等哪些套餐（套餐列表里的 restock_subscribed）。 */
    public static function pendingPlanIds(?User $user): array
    {
        if (!$user || !$user->email) return [];
        return PlanRestockNotify::where('email', mb_strtolower($user->email))->whereNull('notified_at')
            ->pluck('plan_id')->map(fn ($id) => (int) $id)->all();
    }

    /** 只有在售、对外展示、此刻满员的套餐能登记；有位置的直接买就行。 */
    private function assertSubscribable(Plan $plan): void
    {
        if (!$plan->show || !$plan->sell) {
            throw new ApiException(__('Subscription plan does not exist'));
        }
        if ((PlanCapacity::remaining([$plan])[(int) $plan->id] ?? null) !== 0) {
            throw new ApiException('这款套餐现在有位置，可以直接购买');
        }
    }

    /**
     * 定时任务：给已经有位置的套餐的登记者发信。
     *
     * @return array{plans: int, sent: int}
     */
    public function run(bool $dryRun = false): array
    {
        if (!$dryRun) {
            PlanRestockNotify::whereNull('notified_at')
                ->where('created_at', '<', time() - self::PENDING_TTL_DAYS * 86400)->delete();
        }
        $waiting = PlanRestockNotify::whereNull('notified_at')->distinct()->pluck('plan_id')->map(fn ($id) => (int) $id);
        if ($waiting->isEmpty()) {
            return ['plans' => 0, 'sent' => 0];
        }
        $plans = Plan::orderBy('sort')->orderBy('id')->get()->keyBy('id');
        $sellable = $plans->filter(fn (Plan $plan) => $plan->show && $plan->sell);
        $remaining = PlanCapacity::remaining($sellable);
        $open = $sellable->filter(fn (Plan $plan) => ($remaining[(int) $plan->id] ?? null) !== 0);
        $openByFamily = $open->groupBy(fn (Plan $plan) => PlanCatalog::family($plan->tags) ?? '');

        $notifiedPlans = 0;
        $sent = 0;
        foreach ($waiting as $planId) {
            $plan = $plans->get($planId);
            if (!$plan) continue;
            $target = $this->openTarget($plan, $open, $openByFamily);
            if (!$target) continue;
            $notifiedPlans++;
            if ($dryRun) {
                $sent += PlanRestockNotify::where('plan_id', $planId)->whereNull('notified_at')->count();
                continue;
            }
            PlanRestockNotify::where('plan_id', $planId)->whereNull('notified_at')
                ->chunkById(200, function ($rows) use ($plan, $target, &$sent) {
                    foreach ($rows as $row) {
                        // 条件更新抢到这一行才发：任务重叠跑或同时被手动触发时不会发两封
                        $claimed = PlanRestockNotify::where('id', $row->id)->whereNull('notified_at')
                            ->update(['notified_at' => time(), 'updated_at' => time()]);
                        if ($claimed !== 1) continue;
                        $this->send($row, $plan, $target);
                        $sent++;
                    }
                });
        }
        return ['plans' => $notifiedPlans, 'sent' => $sent];
    }

    /** 登记的套餐自己有位就是它；否则同一产品（family）里有位的第一车；都没有返回 null。 */
    private function openTarget(Plan $plan, Collection $open, Collection $openByFamily): ?Plan
    {
        if ($open->has($plan->id)) {
            return $plan;
        }
        $family = PlanCatalog::family($plan->tags);
        return $family !== null ? ($openByFamily->get($family)?->first()) : null;
    }

    private function send(PlanRestockNotify $row, Plan $plan, Plan $target): void
    {
        // 退信过、已暂停投递的账户邮箱不再发（与账单 / 流量邮件同一规则）；这一次登记照样算处理过
        if ($row->user_id && User::where('id', $row->user_id)->whereNotNull('mail_suppressed_at')->exists()) {
            return;
        }
        $name = PlanCatalog::family($plan->tags) ?? (string) $plan->name;
        $url = rtrim((string) admin_setting('app_url', ''), '/') . '/plans?plan=' . $target->id;
        $appName = (string) admin_setting('app_name', 'XBoard');
        [$subject, $content] = BillingDocumentService::withLocale(fn () => [
            __('billing.restock.subject', ['plan' => $name]),
            implode("\n", [
                __('billing.restock.body', ['plan' => $name]),
                __('billing.restock.link', ['url' => $url]),
                __('billing.restock.once'),
            ]),
        ]);
        SendEmailJob::dispatch([
            'email' => $row->email,
            'user_id' => $row->user_id,
            'subject' => $subject . ' - ' . $appName,
            'template_name' => 'notify',
            'template_value' => ['name' => $appName, 'url' => admin_setting('app_url'), 'content' => $content],
        ]);
    }
}
