<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommissionWithdrawal;
use App\Models\TicketMessage;
use App\Services\Commission\WithdrawalConfig;
use App\Services\CommissionWithdrawalService;
use Illuminate\Http\Request;

/**
 * 后台佣金提现结算工作流：列表 / 详情（含二维码附件）/ 标记已打款 / 驳回。
 */
class WithdrawController extends Controller
{
    public function fetch(Request $request)
    {
        $query = CommissionWithdrawal::with('user:id,email')
            ->when($request->filled('status') && $request->input('status') !== '', function ($q) use ($request) {
                $q->where('status', (int) $request->input('status'));
            })
            ->when($request->filled('id'), fn($q) => $q->where('id', (int) $request->input('id')))
            ->when($request->filled('email'), function ($q) use ($request) {
                $q->whereHas('user', fn($u) => $u->where('email', 'like', '%' . $request->input('email') . '%'));
            })
            ->when($request->filled('user_id'), fn($q) => $q->where('user_id', (int) $request->input('user_id')))
            ->orderByRaw('CASE WHEN status = 0 THEN 0 ELSE 1 END')
            ->orderBy('id', 'DESC');

        $page = $query->paginate(
            perPage: max(1, min(100, $request->integer('pageSize', 15))),
            page: max(1, $request->integer('current', 1))
        );

        return response([
            'data' => collect($page->items())->map(fn(CommissionWithdrawal $w) => $this->transform($w))->all(),
            'total' => $page->total(),
        ]);
    }

    /**
     * 待处理数量与金额，供后台角标 / 仪表盘用。
     */
    public function stats()
    {
        $pending = CommissionWithdrawal::where('status', CommissionWithdrawal::STATUS_PENDING);
        return $this->success([
            'pending_count' => (int) $pending->count(),
            'pending_amount' => (int) $pending->sum('amount'),
        ]);
    }

    public function detail(Request $request)
    {
        $request->validate(['id' => 'required|integer|min:1']);
        $withdrawal = CommissionWithdrawal::with('user:id,email,commission_balance,balance')->find((int) $request->input('id'));
        if (!$withdrawal) {
            return $this->fail([400202, '提现申请不存在']);
        }

        $data = $this->transform($withdrawal);
        // 申请时随系统工单上传的二维码等附件
        $data['attachments'] = [];
        if ($withdrawal->ticket_id) {
            $firstMessage = TicketMessage::where('ticket_id', $withdrawal->ticket_id)
                ->with('attachments')
                ->orderBy('id')
                ->first();
            if ($firstMessage) {
                $data['attachments'] = $firstMessage->attachments->toArray();
            }
        }
        $data['user_commission_balance'] = $withdrawal->user ? (int) $withdrawal->user->commission_balance : null;
        // 风控参考：这个地址 / 这个用户历史成功打款次数。首次打款到新地址值得多看一眼二维码与工单
        $data['same_address_paid_count'] = (int) CommissionWithdrawal::where('address', $withdrawal->address)
            ->where('status', CommissionWithdrawal::STATUS_COMPLETED)
            ->where('id', '!=', $withdrawal->id)
            ->count();
        $data['user_paid_count'] = (int) CommissionWithdrawal::where('user_id', $withdrawal->user_id)
            ->where('status', CommissionWithdrawal::STATUS_COMPLETED)
            ->where('id', '!=', $withdrawal->id)
            ->count();
        return $this->success($data);
    }

    public function settle(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|min:1',
            'txid' => 'nullable|string|max:255',
            'paid_usdt' => 'nullable|numeric|min:0',
            'remark' => 'nullable|string|max:500',
        ]);
        $withdrawal = (new CommissionWithdrawalService())->settle(
            (int) $request->input('id'),
            $request->user(),
            $request->input('txid'),
            $request->input('paid_usdt') !== null ? (string) $request->input('paid_usdt') : null,
            $request->input('remark')
        );
        return $this->success($this->transform($withdrawal->load('user:id,email')));
    }

    public function reject(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|min:1',
            'reason' => 'required|string|max:255',
        ], [
            'reason.required' => '请填写驳回原因',
        ]);
        $withdrawal = (new CommissionWithdrawalService())->reject(
            (int) $request->input('id'),
            $request->user(),
            (string) $request->input('reason')
        );
        return $this->success($this->transform($withdrawal->load('user:id,email')));
    }

    private function transform(CommissionWithdrawal $w): array
    {
        $explorer = null;
        if ($w->txid) {
            $chain = WithdrawalConfig::fromSettings()->findChain($w->chain_code);
            if ($chain && $chain['explorer_tx'] !== '') {
                $explorer = str_replace('{txid}', rawurlencode($w->txid), $chain['explorer_tx']);
            }
        }
        return [
            'id' => $w->id,
            'user_id' => $w->user_id,
            'user_email' => $w->user->email ?? null,
            'ticket_id' => $w->ticket_id,
            'amount' => (int) $w->amount,
            'currency' => $w->currency,
            'chain_code' => $w->chain_code,
            'chain_name' => $w->chain_name,
            'network' => $w->network,
            'address' => $w->address,
            'usdt_rate' => $w->usdt_rate,
            'usdt_amount' => $w->usdt_amount,
            'paid_usdt' => $w->paid_usdt,
            'status' => (int) $w->status,
            'status_text' => CommissionWithdrawal::$statusMap[$w->status] ?? (string) $w->status,
            'admin_id' => $w->admin_id,
            'txid' => $w->txid,
            'explorer_url' => $explorer,
            'remark' => $w->remark,
            'reject_reason' => $w->reject_reason,
            'settled_at' => $w->settled_at,
            'created_at' => $w->created_at,
            'updated_at' => $w->updated_at,
        ];
    }
}
