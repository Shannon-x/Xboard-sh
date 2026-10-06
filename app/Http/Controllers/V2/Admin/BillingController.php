<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendBillingMailJob;
use App\Models\BalanceLog;
use App\Models\BillingDocument;
use App\Models\Order;
use App\Models\User;
use App\Services\BalanceLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 后台财务：归档文档查询 / 重发，余额流水查询 / 手工调整。
 * XBoard-admin 暂时没有对应界面，接口先就位（docs/finance-panel.md 有字段说明）。
 */
class BillingController extends Controller
{
    public function fetchDocuments(Request $request)
    {
        $request->validate([
            'user_id' => 'nullable|integer',
            'email' => 'nullable|string|max:191',
            'kind' => 'nullable|in:receipt,invoice',
            'order_id' => 'nullable|integer',
            'current' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:100',
        ]);
        $query = BillingDocument::query()->orderBy('id', 'desc');
        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }
        if ($request->filled('email')) {
            $ids = User::where('email', 'like', '%' . $request->input('email') . '%')->limit(500)->pluck('id');
            $query->whereIn('user_id', $ids);
        }
        if ($request->filled('kind')) {
            $query->where('kind', $request->input('kind'));
        }
        if ($request->filled('order_id')) {
            $query->where('order_id', (int) $request->input('order_id'));
        }
        $page = $query->paginate(
            perPage: max(1, min(100, $request->integer('pageSize', 20))),
            page: max(1, $request->integer('current', 1)),
        );
        $items = $page->getCollection();
        $users = User::whereIn('id', $items->pluck('user_id')->unique()->all())
            ->get(['id', 'email', 'expired_at', 'mail_suppressed_at', 'telegram_id'])->keyBy('id');
        $orders = Order::whereIn('id', $items->pluck('order_id')->filter()->all())->pluck('trade_no', 'id');

        return response([
            'data' => $items->map(function (BillingDocument $d) use ($users, $orders) {
                $user = $users[$d->user_id] ?? null;
                return [
                    'id' => (int) $d->id,
                    'user_id' => (int) $d->user_id,
                    'email' => $user?->email,
                    'mail_suppressed' => $user?->mail_suppressed_at !== null,
                    'telegram_bound' => (bool) ($user?->telegram_id),
                    'kind' => $d->kind,
                    'doc_no' => $d->doc_no,
                    'stage' => $d->stage,
                    'amount' => (int) $d->amount,
                    'status' => $d->statusFor($user),
                    'order_id' => $d->order_id ? (int) $d->order_id : null,
                    'order_trade_no' => $d->order_id ? ($orders[$d->order_id] ?? null) : null,
                    'expired_at' => $d->expired_at ? (int) $d->expired_at : null,
                    'size' => (int) $d->size,
                    'sent_at' => $d->sent_at ? (int) $d->sent_at : null,
                    'send_count' => (int) $d->send_count,
                    'channel' => $d->channel,
                    'download_path' => $d->downloadPath(),
                    'created_at' => (int) $d->created_at,
                ];
            })->values(),
            'total' => $page->total(),
        ]);
    }

    /** 重发：收据按订单重新渲染后再发；账单只有在仍是当前到期日时才有意义。 */
    public function resendDocument(Request $request)
    {
        $request->validate(['id' => 'required|integer']);
        $doc = BillingDocument::find((int) $request->input('id'));
        if (!$doc) {
            return $this->fail([400, '文档不存在']);
        }
        $user = User::find($doc->user_id);
        if (!$user || !$user->email) {
            return $this->fail([400, '用户不存在或没有邮箱']);
        }
        if ($doc->kind === BillingDocument::KIND_RECEIPT) {
            $order = $doc->order_id ? Order::find($doc->order_id) : null;
            if (!$order) {
                return $this->fail([400, '收据对应的订单不存在']);
            }
            SendBillingMailJob::resendReceipt($order);
        } else {
            if ((int) $user->expired_at !== (int) $doc->expired_at || (int) $user->expired_at <= time()) {
                return $this->fail([400, '这张账单对应的周期已经续费或已过期，无需重发']);
            }
            SendBillingMailJob::resendInvoice($user, $doc);
        }
        return $this->success(true);
    }

    public function balanceLog(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer',
            'current' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:100',
        ]);
        $userId = (int) $request->input('user_id');
        $page = BalanceLog::where('user_id', $userId)->orderBy('id', 'desc')->paginate(
            perPage: max(1, min(100, $request->integer('pageSize', 20))),
            page: max(1, $request->integer('current', 1)),
        );
        return response([
            'data' => $page->getCollection()->map(fn (BalanceLog $r) => [
                'id' => (int) $r->id,
                'type' => $r->type,
                'amount' => (int) $r->amount,
                'balance_before' => (int) $r->balance_before,
                'balance_after' => (int) $r->balance_after,
                'order_id' => $r->order_id ? (int) $r->order_id : null,
                'ref_type' => $r->ref_type,
                'ref_id' => $r->ref_id,
                'remark' => $r->remark,
                'operator_id' => $r->operator_id ? (int) $r->operator_id : null,
                'created_at' => (int) $r->created_at,
            ])->values(),
            'total' => $page->total(),
            'balance' => (int) (User::where('id', $userId)->value('balance') ?? 0),
        ]);
    }

    /** 手工调整余额（amount 单位元，可为负），带操作人和备注进流水。 */
    public function adjustBalance(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer',
            'amount' => 'required|numeric|not_in:0',
            'remark' => 'nullable|string|max:255',
        ]);
        $cents = (int) round((float) $request->input('amount') * 100);
        if ($cents === 0) {
            return $this->fail([422, '金额不能为 0']);
        }
        try {
            $balance = DB::transaction(function () use ($request, $cents) {
                $user = User::lockForUpdate()->find((int) $request->input('user_id'));
                if (!$user) {
                    throw new \RuntimeException('用户不存在');
                }
                $next = (int) ($user->balance ?? 0) + $cents;
                if ($next < 0) {
                    throw new \RuntimeException('余额不足以扣减');
                }
                $user->balance = $next;
                if (!$user->save()) {
                    throw new \RuntimeException('保存失败');
                }
                BalanceLedger::record($user, BalanceLog::TYPE_ADMIN_ADJUST, $cents, [
                    'ref_type' => 'admin',
                    'operator_id' => (int) $request->user()->id,
                    'remark' => (string) ($request->input('remark') ?: '后台调整余额'),
                ]);
                return $next;
            });
        } catch (\RuntimeException $e) {
            return $this->fail([400, $e->getMessage()]);
        }
        return $this->success(['balance' => $balance]);
    }
}
