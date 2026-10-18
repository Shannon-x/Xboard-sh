<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommissionPeriodApplication;
use App\Services\Commission\InviteeStats;
use App\Services\Commission\PeriodCommission;
use Illuminate\Http\Request;

/**
 * 后台「周期返佣申请」：列表（待审核排最前）/ 待审核数 / 通过 / 驳回。
 * 列表里给出当前的有效付费被邀请人数，方便判断申请之后有没有掉下去。
 */
class CommissionPeriodController extends Controller
{
    public function fetch(Request $request)
    {
        $query = CommissionPeriodApplication::with(['user:id,email,commission_type', 'admin:id,email'])
            ->when($request->filled('status') && $request->input('status') !== '', function ($q) use ($request) {
                $q->where('status', (int) $request->input('status'));
            })
            ->when($request->filled('email'), function ($q) use ($request) {
                $q->whereHas('user', fn ($u) => $u->where('email', 'like', '%' . $request->input('email') . '%'));
            })
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', (int) $request->input('user_id')))
            ->orderByRaw('CASE WHEN status = 0 THEN 0 ELSE 1 END')
            ->orderBy('id', 'DESC');

        $page = $query->paginate(
            perPage: max(1, min(100, $request->integer('pageSize', 15))),
            page: max(1, $request->integer('current', 1))
        );
        $settings = PeriodCommission::settings();

        return response([
            'data' => collect($page->items())->map(fn (CommissionPeriodApplication $a) => $this->transform($a))->all(),
            'total' => $page->total(),
            'settings' => $settings,
        ]);
    }

    public function stats()
    {
        return $this->success([
            'pending_count' => (int) CommissionPeriodApplication::where('status', CommissionPeriodApplication::STATUS_PENDING)->count(),
            'approved_count' => (int) CommissionPeriodApplication::where('status', CommissionPeriodApplication::STATUS_APPROVED)->count(),
            'settings' => PeriodCommission::settings(),
        ]);
    }

    public function approve(Request $request)
    {
        $request->validate(['id' => 'required|integer|min:1']);
        $application = (new PeriodCommission())->approve((int) $request->input('id'), $request->user());
        return $this->success($this->transform($application->load(['user:id,email,commission_type', 'admin:id,email'])));
    }

    public function reject(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|min:1',
            'reason' => 'required|string|max:255',
        ], [
            'reason.required' => '请填写驳回原因',
        ]);
        $application = (new PeriodCommission())->reject(
            (int) $request->input('id'),
            $request->user(),
            (string) $request->input('reason')
        );
        return $this->success($this->transform($application->load(['user:id,email,commission_type', 'admin:id,email'])));
    }

    private function transform(CommissionPeriodApplication $a): array
    {
        return [
            'id' => (int) $a->id,
            'user_id' => (int) $a->user_id,
            'user_email' => $a->user->email ?? null,
            // 管理员在用户编辑里单设了返佣方式时，申请结果不生效（以单设为准）
            'user_commission_type' => (int) ($a->user->commission_type ?? 0),
            'status' => (int) $a->status,
            'status_key' => $a->statusKey(),
            'active_count' => (int) $a->active_count,
            'min_invitees' => (int) $a->min_invitees,
            'current_active_count' => InviteeStats::activePaidInviteeCount((int) $a->user_id),
            'reason' => $a->reason,
            'admin_email' => $a->admin->email ?? null,
            'decided_at' => $a->decided_at ? (int) $a->decided_at : null,
            'revoked_at' => $a->revoked_at ? (int) $a->revoked_at : null,
            'revoked_count' => $a->revoked_count !== null ? (int) $a->revoked_count : null,
            'created_at' => (int) $a->created_at,
        ];
    }
}
