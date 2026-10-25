<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\BalanceLog;
use App\Models\PaymentVerification;
use App\Models\RechargeOrder;
use App\Models\User;
use App\Services\Recharge\RechargeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * 后台「充值记录」：充值单列表、详情、手动确认到账、取消、概览统计。
 * 充值相关的站点设置（开关、金额上下限、赠送档位、快捷金额）在系统设置的 recharge 分组里。
 */
class RechargeController extends Controller
{
    private const SORTABLE_FIELDS = ['id', 'deposit_amount', 'total_credit', 'pay_amount', 'status', 'created_at', 'paid_at'];

    public function fetch(Request $request)
    {
        $request->validate([
            'current' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:100',
            'status' => 'nullable|integer|in:0,2,3',
            'trade_no' => 'nullable|string|max:64',
            'email' => 'nullable|string|max:255',
            'user_id' => 'nullable|integer|min:1',
            'payment_id' => 'nullable|integer|min:1',
            'start_at' => 'nullable|integer|min:0',
            'end_at' => 'nullable|integer|min:0',
            'sort' => 'nullable|string',
            'sort_desc' => 'nullable|boolean',
        ]);

        $query = RechargeOrder::with(['user:id,email', 'payment:id,name,payment']);
        $this->applyFilters($request, $query);

        $sort = in_array($request->input('sort'), self::SORTABLE_FIELDS, true) ? $request->input('sort') : 'id';
        $query->orderBy($sort, $request->boolean('sort_desc', true) ? 'DESC' : 'ASC');

        $page = $query->paginate(
            perPage: max(1, min(100, $request->integer('pageSize', 15))),
            page: max(1, $request->integer('current', 1)),
        );
        $page->getCollection()->transform(fn (RechargeOrder $r) => self::present($r));

        return $this->paginate($page);
    }

    public function detail(Request $request)
    {
        $request->validate([
            'id' => 'required_without:trade_no|integer',
            'trade_no' => 'required_without:id|string|max:64',
        ]);
        $recharge = RechargeOrder::with(['user:id,email,balance,invite_user_id', 'payment:id,name,payment'])
            ->when($request->filled('id'), fn ($q) => $q->where('id', (int) $request->input('id')))
            ->when(!$request->filled('id'), fn ($q) => $q->where('trade_no', (string) $request->input('trade_no')))
            ->first();
        if (!$recharge) {
            return $this->fail([400202, '充值单不存在']);
        }

        $data = self::present($recharge);
        $data['user_balance'] = $recharge->user ? (int) $recharge->user->balance : null;
        $data['invite_user'] = $recharge->invite_user_id
            ? User::where('id', $recharge->invite_user_id)->first(['id', 'email'])
            : null;
        // 入账流水（BalanceLog 带单号）：确认余额确实加上了、加了多少
        $data['ledger'] = BalanceLog::where('ref_type', 'recharge')
            ->where('ref_id', (string) $recharge->trade_no)
            ->orderBy('id')
            ->get(['id', 'type', 'amount', 'balance_before', 'balance_after', 'remark', 'operator_id', 'created_at']);
        // 付款事后核验（只有易支付一类网关会核验）
        $verification = PaymentVerification::where('subject_type', PaymentVerification::SUBJECT_RECHARGE)
            ->where('subject_id', $recharge->id)
            ->first(['verdict', 'gateway_money', 'checked_at', 'last_error', 'refunded_at']);
        $data['verification'] = $verification ? [
            'verdict' => $verification->verdict,
            'verdict_text' => PaymentVerification::LABELS[$verification->verdict] ?? $verification->verdict,
            'gateway_money' => $verification->gateway_money,
            'checked_at' => $verification->checked_at,
            'last_error' => $verification->last_error,
            'refunded_at' => $verification->refunded_at,
        ] : null;

        return $this->success($data);
    }

    /** 手动确认到账：网关回调没进来，但已在商户后台核实收到了钱。待支付、已取消的单都能确认。 */
    public function paid(Request $request, RechargeService $rechargeService)
    {
        $request->validate(['trade_no' => 'required|string|max:64']);
        $recharge = RechargeOrder::where('trade_no', (string) $request->input('trade_no'))->first();
        if (!$recharge) {
            return $this->fail([400202, '充值单不存在']);
        }
        try {
            $outcome = $rechargeService->confirmByAdmin($recharge, (int) $request->user()->id);
        } catch (\Throwable $e) {
            return $this->fail([500, '入账失败：' . $e->getMessage()]);
        }
        if (!in_array($outcome, ['credited', 'late'], true)) {
            return $this->fail([400, '这张充值单已经到账，不能重复确认']);
        }
        return $this->success(true);
    }

    public function cancel(Request $request, RechargeService $rechargeService)
    {
        $request->validate(['trade_no' => 'required|string|max:64']);
        $recharge = RechargeOrder::where('trade_no', (string) $request->input('trade_no'))->first();
        if (!$recharge) {
            return $this->fail([400202, '充值单不存在']);
        }
        if (!$rechargeService->cancel($recharge)) {
            return $this->fail([400, '只能取消待支付的充值单']);
        }
        return $this->success(true);
    }

    /** 列表页顶部的概览：今日 / 本月 / 上月已到账的笔数与金额、待支付张数 */
    public function stats()
    {
        $now = time();
        $todayStart = strtotime('today', $now);
        $monthStart = strtotime(date('Y-m-01', $now));
        $lastMonthStart = strtotime('-1 month', $monthStart);

        $sum = static function (int $from, int $to): array {
            $row = RechargeOrder::where('status', RechargeOrder::STATUS_COMPLETED)
                ->where('paid_at', '>=', $from)
                ->where('paid_at', '<', $to)
                ->selectRaw('COUNT(*) AS c, COALESCE(SUM(deposit_amount), 0) AS d, COALESCE(SUM(bonus_amount), 0) AS b, COUNT(DISTINCT user_id) AS u')
                ->first();
            return [
                'count' => (int) $row->c,
                'deposit_amount' => (int) $row->d,
                'bonus_amount' => (int) $row->b,
                'users' => (int) $row->u,
            ];
        };

        return $this->success([
            'today' => $sum($todayStart, $now + 1),
            'month' => $sum($monthStart, $now + 1),
            'last_month' => $sum($lastMonthStart, $monthStart),
            'pending_count' => RechargeOrder::where('status', RechargeOrder::STATUS_PENDING)->count(),
        ]);
    }

    private function applyFilters(Request $request, Builder $query): void
    {
        if ($request->filled('status')) {
            $query->where('status', (int) $request->input('status'));
        }
        if ($request->filled('trade_no')) {
            $query->where('trade_no', 'like', '%' . trim((string) $request->input('trade_no')) . '%');
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }
        if ($request->filled('email')) {
            $email = strtolower(trim((string) $request->input('email')));
            $query->whereIn('user_id', User::where('email', 'like', '%' . $email . '%')->select('id'));
        }
        if ($request->filled('payment_id')) {
            $query->where('payment_id', (int) $request->input('payment_id'));
        }
        if ($request->filled('start_at')) {
            $query->where('created_at', '>=', (int) $request->input('start_at'));
        }
        if ($request->filled('end_at')) {
            $query->where('created_at', '<', (int) $request->input('end_at'));
        }
    }

    public static function present(RechargeOrder $r): array
    {
        return [
            'id' => (int) $r->id,
            'trade_no' => (string) $r->trade_no,
            'user_id' => (int) $r->user_id,
            'user_email' => $r->relationLoaded('user') ? $r->user?->email : null,
            'deposit_amount' => (int) $r->deposit_amount,
            'bonus_amount' => (int) $r->bonus_amount,
            'total_credit' => (int) $r->total_credit,
            'pay_amount' => (int) $r->pay_amount,
            'handling_amount' => $r->handling_amount === null ? null : (int) $r->handling_amount,
            'payment_id' => $r->payment_id === null ? null : (int) $r->payment_id,
            'payment_name' => $r->relationLoaded('payment') ? $r->payment?->name : null,
            'payment_method' => $r->payment_method,
            'status' => (int) $r->status,
            'status_text' => RechargeOrder::$statusMap[(int) $r->status] ?? (string) $r->status,
            'callback_no' => $r->callback_no,
            'manual' => $r->callback_no === RechargeOrder::MANUAL_CALLBACK_NO,
            'invite_user_id' => $r->invite_user_id === null ? null : (int) $r->invite_user_id,
            'paid_at' => $r->paid_at === null ? null : (int) $r->paid_at,
            'created_at' => (int) $r->created_at,
            'updated_at' => (int) $r->updated_at,
        ];
    }
}
