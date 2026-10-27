<?php

namespace App\Mcp\Tools;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\Mcp\McpPresenter as P;
use App\Services\Mcp\OrderPayService;
use App\Services\Payg\PaygService;
use App\Services\PlanCustomizationService;
use App\Services\RenewService;
use App\Services\UserService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_account_overview')]
#[Title('账户概览')]
#[Description('查看当前账户：套餐、到期时间、本周期流量（总量 / 已用 / 剩余）、重置日、设备数、增值线路、账户余额、续费参考价，以及是否有待付款订单。回答「还剩多少流量」「几号到期」「要不要续费」时先调用它。')]
#[IsReadOnly]
#[IsIdempotent]
class GetAccountOverviewTool extends McpTool
{
    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $user = User::findOrFail($user->id);
        $userService = app(UserService::class);
        $plan = $user->plan_id ? Plan::find($user->plan_id) : null;
        $used = (int) $user->u + (int) $user->d;
        $total = (int) $user->transfer_enable;

        $status = !$user->plan_id ? 'no_plan' : ($userService->isAvailable($user) ? 'active' : 'inactive');
        if ($user->plan_id && $user->expired_at !== null && $user->expired_at <= time()) {
            $status = 'expired';
        } elseif ($user->plan_id && $total > 0 && $used >= $total) {
            $status = 'traffic_exhausted';
        }

        $customizer = app(PlanCustomizationService::class);
        $addons = $plan ? collect($customizer->addonGroupsForUser($plan, $user))
            ->map(fn ($g) => ['name' => (string) $g['name'], 'source' => (string) ($g['source'] ?? '')])
            ->values()->all() : [];

        $renew = null;
        if ($plan) {
            $spec = app(RenewService::class)->resolveSpec($user);
            $renew = $spec['available']
                ? ['plan_name' => $spec['plan_name'], 'period' => $spec['period'], 'period_name' => $spec['period_name'], 'amount_yuan' => P::yuan($spec['amount'])]
                : ['unavailable_reason' => $spec['message']];
        }

        $payg = app(PaygService::class)->status($user);
        $pending = Order::where('user_id', $user->id)->where('status', Order::STATUS_PENDING)->orderByDesc('id')->first();

        return self::result([
            'email' => P::maskEmail((string) $user->email),
            'status' => $status,
            'status_hint' => [
                'no_plan' => '当前没有订阅',
                'active' => '正常使用中',
                'inactive' => '暂不可用',
                'expired' => '套餐已到期',
                'traffic_exhausted' => '本周期流量已用完',
            ][$status],
            'plan' => $plan ? ['id' => (int) $plan->id, 'name' => (string) $plan->name] : null,
            'expires_at' => $user->plan_id ? ($user->expired_at === null ? '不限时' : P::time((int) $user->expired_at)) : null,
            'days_left' => $user->expired_at ? max(0, (int) ceil(((int) $user->expired_at - time()) / 86400)) : null,
            'traffic' => [
                'total_gb' => P::gb($total),
                'used_gb' => P::gb($used),
                'remaining_gb' => P::gb(max(0, $total - $used)),
                'upload_gb' => P::gb($user->u),
                'download_gb' => P::gb($user->d),
                'days_until_reset' => $userService->getResetDay($user),
                'next_reset_at' => P::date($user->next_reset_at ? (int) $user->next_reset_at : null),
            ],
            'device_limit' => $user->device_limit,
            'speed_limit_mbps' => $user->speed_limit,
            'addon_lines' => $addons,
            'account_balance_yuan' => P::yuan($user->balance),
            'commission_balance_yuan' => P::yuan($user->commission_balance),
            'auto_renew' => (bool) $user->auto_renew,
            'renew_reference' => $renew,
            'payg_wallet' => $payg ? [
                'remaining_gb' => P::gb($payg['balance_bytes']),
                'used_this_month_yuan' => P::yuan($payg['month_used']),
            ] : null,
            'pending_order' => $pending ? [
                'trade_no' => (string) $pending->trade_no,
                'type' => P::orderType((int) $pending->type),
                'amount_due_yuan' => P::yuan($pending->total_amount),
                'payment_url' => OrderPayService::url((string) $pending->trade_no),
            ] : null,
            'note' => '订阅链接不会通过 AI 助手提供，请在面板「我的订阅」里复制。',
        ]);
    }
}
