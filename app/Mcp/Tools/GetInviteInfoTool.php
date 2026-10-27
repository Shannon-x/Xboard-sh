<?php

namespace App\Mcp\Tools;

use App\Models\InviteCode;
use App\Models\Order;
use App\Models\User;
use App\Services\Commission\CommissionPolicy;
use App\Services\Commission\InviteDiscount;
use App\Services\Commission\InviteeStats;
use App\Services\Commission\WithdrawalConfig;
use App\Services\Mcp\McpCommission;
use App\Services\Mcp\McpPresenter as P;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_invite_info')]
#[Title('邀请与佣金')]
#[Description('客户的邀请链接、邀请了多少人、多少人付过款、返佣比例、可用佣金和确认中的佣金，以及提现情况：门槛、收款地址（只给头尾）、处理中的申请和最近几笔记录。要提现用 withdraw_commission，转余额用 transfer_commission。')]
#[IsReadOnly]
#[IsIdempotent]
class GetInviteInfoTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $user = User::findOrFail($user->id);
        $origin = rtrim((string) admin_setting('app_url', ''), '/');
        $codes = InviteCode::where('user_id', $user->id)->where('status', InviteCode::STATUS_UNUSED)->orderByDesc('id')->limit(5)->pluck('code');
        $pending = (int) Order::where('status', Order::STATUS_COMPLETED)->where('commission_status', 0)
            ->where('invite_user_id', $user->id)->sum('commission_balance');
        if (admin_setting('commission_distribution_enable', 0)) {
            $pending = (int) ($pending * (admin_setting('commission_distribution_l1') / 100));
        }
        $withdraw = WithdrawalConfig::fromSettings();
        $discount = InviteDiscount::percent();

        return self::result([
            'invite_links' => $codes->map(fn (string $code) => $origin . '/register?code=' . $code)->values()->all(),
            'invite_links_note' => $codes->isEmpty() ? '还没有邀请码，请到面板邀请页生成。' : null,
            'invitee_first_order_discount_percent' => $discount > 0 ? $discount : null,
            'registered_invitees' => (int) User::where('invite_user_id', $user->id)->count(),
            'paid_invitees' => InviteeStats::paidInviteeCount($user->id),
            'commission_rate_percent' => (int) CommissionPolicy::view($user)['rate'],
            'commission_available_yuan' => P::yuan((int) $user->commission_balance),
            'commission_pending_yuan' => P::yuan($pending),
            'withdrawal' => McpCommission::withdrawal($user, $withdraw),
            'transfer_to_balance_needs_paid_invitees' => $withdraw->transferRequireInvitees ? $withdraw->minInvitees : 0,
            'note' => '可用佣金能提现（withdraw_commission，打到客户在面板填过的收款地址），也能转进余额抵扣下单（transfer_commission，转进余额后不能再提现）。',
        ]);
    }
}
