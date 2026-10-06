<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\BalanceLog;
use App\Models\BillingDocument;
use App\Models\Order;
use App\Models\User;
use App\Services\Billing\BillingDocumentService;
use App\Services\MailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * 用户端财务：收据 / 账单归档列表、余额流水、邮箱投递自测。
 */
class BillingController extends Controller
{
    /** 我的收据与账单：最近 200 份，新的在前 */
    public function documents(Request $request)
    {
        $user = User::find($request->user()->id);
        $docs = BillingDocument::where('user_id', $user->id)->orderBy('id', 'desc')->limit(200)->get();
        $tradeNos = Order::whereIn('id', $docs->pluck('order_id')->filter()->all())->pluck('trade_no', 'id');
        return $this->success($docs->map(fn (BillingDocument $d) => [
            'id' => (int) $d->id,
            'kind' => $d->kind,
            'doc_no' => $d->doc_no,
            'stage' => $d->stage,
            'amount' => (int) $d->amount,
            'status' => $d->statusFor($user),
            'created_at' => (int) $d->created_at,
            'sent_at' => $d->sent_at ? (int) $d->sent_at : null,
            'channel' => $d->channel,
            'order_trade_no' => $d->order_id ? ($tradeNos[$d->order_id] ?? null) : null,
            'expired_at' => $d->expired_at ? (int) $d->expired_at : null,
            'download_path' => $d->downloadPath(),
        ])->values());
    }

    /** 余额流水（分页），同时带当前余额 */
    public function balanceLog(Request $request)
    {
        $request->validate([
            'page' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:100',
        ]);
        $page = max(1, (int) $request->input('page', 1));
        $size = max(1, min(100, (int) $request->input('pageSize', 20)));
        $userId = (int) $request->user()->id;

        $query = BalanceLog::where('user_id', $userId);
        $total = (clone $query)->count();
        $rows = $query->orderBy('id', 'desc')->forPage($page, $size)->get();

        return response([
            'data' => $rows->map(fn (BalanceLog $r) => [
                'id' => (int) $r->id,
                'type' => $r->type,
                'amount' => (int) $r->amount,
                'balance_after' => (int) $r->balance_after,
                'remark' => $r->remark,
                'order_trade_no' => $r->ref_type === 'order' ? $r->ref_id : null,
                'created_at' => (int) $r->created_at,
            ])->values(),
            'total' => $total,
            'balance' => (int) (User::where('id', $userId)->value('balance') ?? 0),
        ]);
    }

    /**
     * 退信后用户自助「重新测试邮箱」：发一封最短的测试邮件，发成功即由 DeliveryMonitor 自动解除暂停投递标记。
     * 10 分钟一次，防止把一个被抑制的地址反复往 SMTP 塞。
     */
    public function mailTest(Request $request)
    {
        $user = User::find($request->user()->id);
        if (!$user || !$user->email) {
            return $this->fail([400, __('billing.mail_test.no_email')]);
        }
        if (!Cache::add('billing:mail_test:' . $user->id, time(), 600)) {
            return $this->fail([400, __('billing.mail_test.too_often')]);
        }
        $log = BillingDocumentService::withLocale(function () use ($user) {
            $data = app(BillingDocumentService::class)->testMail($user);
            return MailService::deliver($user->email, $data['subject'], 'billing.mail.test', $data, [], (int) $user->id);
        });
        $ok = $log['error'] === null;
        return $this->success([
            'ok' => $ok,
            'category' => $ok ? null : $log['category'],
            'message' => BillingDocumentService::withLocale(fn () => $ok ? __('billing.mail_test.ok') : __('billing.mail_test.failed_' . $log['category'])),
        ]);
    }
}
