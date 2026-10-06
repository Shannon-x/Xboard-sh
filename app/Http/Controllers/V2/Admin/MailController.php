<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillingDocument;
use App\Models\MailLog;
use App\Models\User;
use App\Services\Mail\DeliveryMonitor;
use Illuminate\Http\Request;

/**
 * 后台邮件投递：日志查询、统计、暂停投递的用户与解除。
 */
class MailController extends Controller
{
    public function fetch(Request $request)
    {
        $request->validate([
            'email' => 'nullable|string|max:191',
            'user_id' => 'nullable|integer',
            'status' => 'nullable|in:0,1',
            'category' => 'nullable|in:suppressed,bounce,temporary,config',
            'template' => 'nullable|string|max:191',
            'start_at' => 'nullable|integer',
            'end_at' => 'nullable|integer',
            'current' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:100',
        ]);
        $query = MailLog::query()->orderBy('id', 'desc');
        if ($request->filled('email')) {
            $query->where('email', 'like', '%' . $request->input('email') . '%');
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', (int) $request->input('status'));
        }
        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }
        if ($request->filled('template')) {
            $query->where('template_name', 'like', '%' . $request->input('template') . '%');
        }
        if ($request->filled('start_at')) {
            $query->where('created_at', '>=', (int) $request->input('start_at'));
        }
        if ($request->filled('end_at')) {
            $query->where('created_at', '<=', (int) $request->input('end_at'));
        }
        $page = $query->paginate(
            perPage: max(1, min(100, $request->integer('pageSize', 20))),
            page: max(1, $request->integer('current', 1)),
        );
        return response([
            'data' => $page->getCollection()->map(fn (MailLog $log) => [
                'id' => (int) $log->id,
                'email' => $log->email,
                'user_id' => $log->user_id ? (int) $log->user_id : null,
                'subject' => $log->subject,
                'template_name' => $log->template_name,
                'status' => (int) ($log->status ?? ($log->error ? 0 : 1)),
                'category' => $log->category,
                'error' => $log->error ? mb_substr((string) $log->error, 0, 500) : null,
                'created_at' => (int) $log->created_at,
            ])->values(),
            'total' => $page->total(),
        ]);
    }

    /** 24 小时 / 7 天的发送与失败数、失败分类，暂停投递的用户数与最近的几位，未能投递的归档文档数。 */
    public function stats(Request $request)
    {
        $now = time();
        $out = [];
        foreach (['last_24h' => $now - 86400, 'last_7d' => $now - 7 * 86400] as $key => $since) {
            $base = MailLog::where('created_at', '>=', $since);
            $out[$key] = [
                'sent' => (clone $base)->where('status', 1)->count(),
                'failed' => (clone $base)->where('status', 0)->count(),
                'by_category' => (clone $base)->where('status', 0)->whereNotNull('category')
                    ->selectRaw('category, count(*) as n')->groupBy('category')->pluck('n', 'category'),
            ];
        }
        $out['suppressed_users'] = User::whereNotNull('mail_suppressed_at')->count();
        $out['suppressed_recent'] = User::whereNotNull('mail_suppressed_at')->orderBy('mail_suppressed_at', 'desc')->limit(10)
            ->get(['id', 'email', 'mail_suppressed_reason', 'mail_suppressed_at', 'mail_failed_count', 'telegram_id'])
            ->map(fn (User $u) => $this->userRow($u))->values();
        $out['pending_documents'] = BillingDocument::whereNull('sent_at')->count();
        return $this->success($out);
    }

    public function suppressed(Request $request)
    {
        $request->validate([
            'current' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:100',
        ]);
        $page = User::whereNotNull('mail_suppressed_at')->orderBy('mail_suppressed_at', 'desc')->paginate(
            perPage: max(1, min(100, $request->integer('pageSize', 20))),
            page: max(1, $request->integer('current', 1)),
            columns: ['id', 'email', 'mail_suppressed_reason', 'mail_suppressed_at', 'mail_failed_count', 'telegram_id'],
        );
        $pending = BillingDocument::whereIn('user_id', $page->getCollection()->pluck('id'))->whereNull('sent_at')
            ->selectRaw('user_id, count(*) as n')->groupBy('user_id')->pluck('n', 'user_id');
        return response([
            'data' => $page->getCollection()->map(fn (User $u) => $this->userRow($u) + ['pending_documents' => (int) ($pending[$u->id] ?? 0)])->values(),
            'total' => $page->total(),
        ]);
    }

    /** 解除暂停投递（用户修好邮箱但没自己点自测、或是误判） */
    public function unsuppress(Request $request)
    {
        $request->validate(['user_id' => 'required|integer']);
        $user = User::find((int) $request->input('user_id'));
        if (!$user) {
            return $this->fail([400, '用户不存在']);
        }
        DeliveryMonitor::unsuppress($user);
        return $this->success(true);
    }

    private function userRow(User $u): array
    {
        return [
            'id' => (int) $u->id,
            'email' => $u->email,
            'reason' => $u->mail_suppressed_reason,
            'suppressed_at' => $u->mail_suppressed_at ? (int) $u->mail_suppressed_at : null,
            'failed_count' => (int) ($u->mail_failed_count ?? 0),
            'telegram_bound' => (bool) $u->telegram_id,
        ];
    }
}
