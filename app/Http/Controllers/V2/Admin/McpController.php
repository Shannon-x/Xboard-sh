<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Mcp\Servers\AccountServer;
use App\Models\McpCallLog;
use App\Models\McpOauthGrant;
use App\Models\Order;
use App\Models\RechargeOrder;
use App\Models\User;
use App\Services\AuthService;
use App\Services\Mcp\McpAudit;
use App\Services\Mcp\McpTokenService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * 后台「AI 助手」：客户通过 AI 软件（ChatGPT、Claude 等）调用面板的记录。
 *
 * - stats：今天 / 最近 7 天的调用量、失败数、AI 建的单，在连着的 AI 软件数，常用工具排行；
 * - log/fetch：调用记录，可按客户、工具、成败筛，「只看下单」时只列建单类工具；带单号的行附上订单现状；
 * - connection/fetch：客户连着的 AI 软件和令牌（不含明文）；
 * - connection/revoke：替客户断开某个 AI 连接（令牌连同一键授权一起作废，AI 软件需要重新授权）。
 *
 * 开关、每日上限等设置在系统设置的 mcp 分组里（ConfigController）。
 */
class McpController extends Controller
{
    public function stats()
    {
        $today = now()->startOfDay()->getTimestamp();
        $week = time() - 7 * 86400;

        $since = fn (int $from) => McpCallLog::where('created_at', '>=', $from);
        $ordersSince = fn (int $from) => $since($from)->whereIn('tool', McpAudit::ORDER_TOOLS)->where('ok', 1)->whereNotNull('ref');

        $topTools = $since($week)->selectRaw('tool, COUNT(*) AS calls, SUM(CASE WHEN ok = 1 THEN 0 ELSE 1 END) AS failed')
            ->groupBy('tool')->orderByDesc('calls')->limit(10)->get()
            ->map(fn ($row) => ['tool' => (string) $row->tool, 'calls' => (int) $row->calls, 'failed' => (int) $row->failed])
            ->all();

        $tokens = self::liveTokens();

        return $this->success([
            'calls_today' => $since($today)->count(),
            'failed_today' => $since($today)->where('ok', 0)->count(),
            'orders_today' => $ordersSince($today)->count(),
            'calls_7d' => $since($week)->count(),
            'failed_7d' => $since($week)->where('ok', 0)->count(),
            'orders_7d' => $ordersSince($week)->count(),
            'users_7d' => $since($week)->distinct()->count('user_id'),
            'connections' => (clone $tokens)->count(),
            'connected_users' => (clone $tokens)->distinct()->count('tokenable_id'),
            'top_tools' => $topTools,
            'tools' => self::toolTitles(),
            'order_tools' => McpAudit::ORDER_TOOLS,
        ]);
    }

    public function fetchLog(Request $request)
    {
        $request->validate([
            'current' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:100',
            'user_id' => 'nullable|integer|min:1',
            'email' => 'nullable|string|max:255',
            'token_id' => 'nullable|integer|min:1',
            'tool' => 'nullable|string|max:64',
            'ok' => 'nullable|integer|in:0,1',
            'orders_only' => 'nullable|boolean',
            'ref' => 'nullable|string|max:64',
            'start_at' => 'nullable|integer|min:0',
            'end_at' => 'nullable|integer|min:0',
        ]);

        $query = McpCallLog::query();
        if (!$this->filterUser($request, $query)) {
            return $this->paginate(McpCallLog::whereRaw('1 = 0')->paginate(1));
        }
        $query->when($request->filled('token_id'), fn ($q) => $q->where('token_id', $request->integer('token_id')))
            ->when($request->filled('tool'), fn ($q) => $q->where('tool', (string) $request->input('tool')))
            ->when($request->filled('ok'), fn ($q) => $q->where('ok', $request->integer('ok')))
            ->when($request->filled('ref'), fn ($q) => $q->where('ref', (string) $request->input('ref')))
            ->when($request->filled('start_at'), fn ($q) => $q->where('created_at', '>=', $request->integer('start_at')))
            ->when($request->filled('end_at'), fn ($q) => $q->where('created_at', '<=', $request->integer('end_at')))
            ->when($request->boolean('orders_only'), fn ($q) => $q->whereIn('tool', McpAudit::ORDER_TOOLS)->where('ok', 1)->whereNotNull('ref'));

        $page = $query->orderByDesc('id')->paginate(
            perPage: max(1, min(100, $request->integer('pageSize', 20))),
            page: max(1, $request->integer('current', 1)),
        );
        $rows = $page->getCollection();

        $users = User::whereIn('id', $rows->pluck('user_id')->unique())->get(['id', 'email'])->keyBy('id');
        $tokenNames = PersonalAccessToken::whereIn('id', $rows->pluck('token_id')->unique())->pluck('name', 'id');
        $apps = McpOauthGrant::whereIn('token_id', $rows->pluck('token_id')->unique())->pluck('app_name', 'token_id');
        $refs = $rows->pluck('ref')->filter()->unique()->values();
        $orders = self::ordersByTradeNo($refs);

        $page->setCollection($rows->map(fn (McpCallLog $log) => [
            'id' => (int) $log->id,
            'user_id' => (int) $log->user_id,
            'email' => $users[$log->user_id]->email ?? null,
            'token_id' => (int) $log->token_id,
            // 令牌被删了（客户断开、过期清掉）就没名字了，记录照样留着
            'token_name' => isset($tokenNames[$log->token_id]) ? Str::after((string) $tokenNames[$log->token_id], McpTokenService::NAME_PREFIX) : null,
            'app_name' => $apps[$log->token_id] ?? null,
            'tool' => (string) $log->tool,
            'ok' => (bool) $log->ok,
            'ref' => $log->ref,
            'message' => $log->message,
            'ip' => $log->ip,
            'created_at' => (int) $log->getRawOriginal('created_at'),
            'order' => $log->ref !== null ? ($orders[$log->ref] ?? null) : null,
        ]));

        return $this->paginate($page);
    }

    public function fetchConnections(Request $request)
    {
        $request->validate([
            'current' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:100',
            'user_id' => 'nullable|integer|min:1',
            'email' => 'nullable|string|max:255',
        ]);

        $query = self::liveTokens();
        if ($request->filled('user_id') || $request->filled('email')) {
            $userIds = $this->matchingUserIds($request);
            if ($userIds === []) {
                return $this->paginate(PersonalAccessToken::whereRaw('1 = 0')->paginate(1));
            }
            $query->whereIn('tokenable_id', $userIds);
        }

        $page = $query->orderByDesc('id')->paginate(
            perPage: max(1, min(100, $request->integer('pageSize', 20))),
            page: max(1, $request->integer('current', 1)),
        );
        $tokens = $page->getCollection();
        $ids = $tokens->pluck('id');

        $users = User::whereIn('id', $tokens->pluck('tokenable_id')->unique())->get(['id', 'email'])->keyBy('id');
        $grants = McpOauthGrant::whereIn('token_id', $ids)->get(['token_id', 'app_name', 'redirect_host', 'client_id'])->keyBy('token_id');
        $lastIps = McpCallLog::whereIn('token_id', $ids)->orderByDesc('id')->limit(500)->get(['token_id', 'ip'])
            ->unique('token_id')->pluck('ip', 'token_id');
        $week = time() - 7 * 86400;
        $calls = McpCallLog::whereIn('token_id', $ids)->where('created_at', '>=', $week)
            ->selectRaw('token_id, COUNT(*) AS calls')->groupBy('token_id')->pluck('calls', 'token_id');
        $orders = McpCallLog::whereIn('token_id', $ids)->whereIn('tool', McpAudit::ORDER_TOOLS)->where('ok', 1)->whereNotNull('ref')
            ->selectRaw('token_id, COUNT(*) AS orders')->groupBy('token_id')->pluck('orders', 'token_id');

        $page->setCollection($tokens->map(fn (PersonalAccessToken $t) => [
            'id' => (int) $t->id,
            'user_id' => (int) $t->tokenable_id,
            'email' => $users[$t->tokenable_id]->email ?? null,
            'name' => Str::after((string) $t->name, McpTokenService::NAME_PREFIX),
            // oauth = 客户在授权页点「允许」连上的（ChatGPT 等），token = 客户在面板手动生成后填进 AI 软件的
            'via' => isset($grants[$t->id]) ? 'oauth' : 'token',
            'app_name' => $grants[$t->id]->app_name ?? null,
            'app_host' => $grants[$t->id]->redirect_host ?? null,
            'can_order' => McpTokenService::canOrder($t),
            'created_at' => $t->created_at?->getTimestamp(),
            'last_used_at' => $t->last_used_at?->getTimestamp(),
            'expires_at' => $t->expires_at?->getTimestamp(),
            'last_ip' => $lastIps[$t->id] ?? null,
            'calls_7d' => (int) ($calls[$t->id] ?? 0),
            'orders_total' => (int) ($orders[$t->id] ?? 0),
        ]));

        return $this->paginate($page);
    }

    public function revokeConnection(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|min:1',
            'user_id' => 'required|integer|min:1',
        ]);
        $user = User::find($request->integer('user_id'));
        if (!$user) {
            return $this->fail([400202, '用户不存在']);
        }
        if (!McpTokenService::make()->revoke($user, $request->integer('id'))) {
            return $this->fail([400202, '这个 AI 连接已经不在了（可能客户已经断开，或令牌已过期）']);
        }
        return $this->success(true);
    }

    /** 还在有效期内的 AI 助手令牌（所有客户） */
    private static function liveTokens(): Builder
    {
        return PersonalAccessToken::query()
            ->where('tokenable_type', (new User())->getMorphClass())
            ->where('name', 'like', McpTokenService::NAME_PREFIX . '%')
            ->where('abilities', 'like', '%' . AuthService::ABILITY_MCP_READ . '%')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * 按 user_id / 邮箱筛调用记录。邮箱按包含匹配；一个也对不上返回 false（直接给空页）。
     */
    private function filterUser(Request $request, Builder $query): bool
    {
        if (!$request->filled('user_id') && !$request->filled('email')) {
            return true;
        }
        $ids = $this->matchingUserIds($request);
        if ($ids === []) {
            return false;
        }
        $query->whereIn('user_id', $ids);
        return true;
    }

    /** @return int[] */
    private function matchingUserIds(Request $request): array
    {
        if ($request->filled('user_id')) {
            return [$request->integer('user_id')];
        }
        $email = trim((string) $request->input('email'));
        return User::where('email', 'like', '%' . addcslashes($email, '%_\\') . '%')
            ->orderBy('id')->limit(200)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * 调用记录里的单号 → 订单 / 充值单现状（金额单位：分）。
     * @return array<string, array>
     */
    private static function ordersByTradeNo(Collection $refs): array
    {
        if ($refs->isEmpty()) {
            return [];
        }
        $out = [];
        Order::with('plan:id,name')->whereIn('trade_no', $refs)
            ->get(['id', 'trade_no', 'plan_id', 'period', 'type', 'status', 'total_amount', 'paid_at'])
            ->each(function (Order $o) use (&$out) {
                $out[(string) $o->trade_no] = [
                    'kind' => 'order',
                    'id' => (int) $o->id,
                    'trade_no' => (string) $o->trade_no,
                    'status' => (int) $o->status,
                    'status_text' => Order::$statusMap[$o->status] ?? null,
                    'amount' => (int) $o->total_amount,
                    'plan_name' => $o->plan?->name,
                    'period' => $o->period,
                    'paid_at' => $o->paid_at ? (int) $o->getRawOriginal('paid_at') : null,
                ];
            });
        RechargeOrder::whereIn('trade_no', $refs)
            ->get(['id', 'trade_no', 'status', 'deposit_amount', 'pay_amount', 'paid_at'])
            ->each(function (RechargeOrder $r) use (&$out) {
                $out[(string) $r->trade_no] = [
                    'kind' => 'recharge',
                    'id' => (int) $r->id,
                    'trade_no' => (string) $r->trade_no,
                    'status' => (int) $r->status,
                    'status_text' => RechargeOrder::$statusMap[$r->status] ?? null,
                    'amount' => (int) ($r->pay_amount ?: $r->deposit_amount),
                    'plan_name' => null,
                    'period' => null,
                    'paid_at' => $r->paid_at ? (int) $r->getRawOriginal('paid_at') : null,
                ];
            });
        return $out;
    }

    /**
     * 工具名 → 中文标题（从 AccountServer 注册的工具上读 #[Name] / #[Title]），后台列表直接显示中文。
     * @return array<string, string>
     */
    private static function toolTitles(): array
    {
        $titles = [];
        try {
            $classes = (new \ReflectionClass(AccountServer::class))->getDefaultProperties()['tools'] ?? [];
            foreach ($classes as $class) {
                $ref = new \ReflectionClass($class);
                $name = $ref->getAttributes(Name::class)[0] ?? null;
                $title = $ref->getAttributes(Title::class)[0] ?? null;
                if ($name && $title) {
                    $titles[(string) $name->getArguments()[0]] = (string) $title->getArguments()[0];
                }
            }
        } catch (\Throwable) {
            // 读不到就只显示英文工具名
        }
        return $titles;
    }
}
