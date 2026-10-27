<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\ConfigSave;
use App\Models\BalanceLog;
use App\Models\CommissionWithdrawal;
use App\Models\Coupon;
use App\Models\McpCallLog;
use App\Models\Notice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlanRestockNotify;
use App\Models\ServerGroup;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Models\UserNotificationPref;
use App\Models\UserPayoutProfile;
use App\Services\AuthService;
use App\Services\CheckoutService;
use App\Services\Mcp\McpTicketRef;
use App\Services\Mcp\McpTokenService;
use App\Services\Mcp\OrderPayService;
use App\Services\Notification\NotificationPreference;
use App\Services\TicketService;
use App\Services\PaymentService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 客户 AI 助手（MCP）：客户在面板生成令牌填进自己的 AI，AI 通过 /mcp 查账户、按客户的话下单，
 * 钱由客户本人在免登录付款链接里付。
 *
 * 守住三件事：令牌只认 /mcp（面板接口、快捷登录一律拒绝）；输出里没有订阅链接 / token / uuid；
 * 下单走面板同一个 OrderService，付款走同一个收银台核心。
 */
class McpAssistantTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1073741824;
    private const APP_URL = 'https://panel.example.test';

    private Plan $plan;
    private Plan $bigPlan;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        // Setting 缓存写死 Cache::store('redis')，CI 没起 Redis：桩成内存驱动，并丢掉启动时建好的 Setting 实例
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        app()->forgetScopedInstances();
        config(['v2board.app_name' => '苏菲家宽', 'v2board.app_url' => self::APP_URL . '/']);
        McpFakeGateway::$calls = [];
        $this->app->bind(CheckoutService::class, fn () => new McpFakeCheckout());

        $group = new ServerGroup();
        $group->name = '基础';
        $group->save();
        $this->plan = Plan::create([
            'name' => '静态家宽拼车', 'group_id' => $group->id, 'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => null, 'reset_traffic_method' => 1, 'capacity_limit' => null,
            'prices' => ['monthly' => 20, 'quarterly' => 57],
        ]);
        $this->bigPlan = Plan::create([
            'name' => '静态家宽独享', 'group_id' => $group->id, 'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 300, 'device_limit' => 3, 'speed_limit' => null, 'reset_traffic_method' => 1, 'capacity_limit' => null,
            'prices' => ['monthly' => 45],
        ]);
    }

    private function enable(array $extra = []): void
    {
        admin_setting(['mcp_enable' => 1] + $extra);
    }

    private function user(array $attributes = [], bool $paid = true): User
    {
        $user = User::create($attributes + [
            'email' => 'shannon' . Str::lower(Str::random(6)) . '@example.test', 'password' => 'x',
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'plan_id' => $this->plan->id, 'group_id' => $this->plan->group_id, 'balance' => 0,
            'transfer_enable' => 100 * self::GB, 'u' => 3 * self::GB, 'd' => 17 * self::GB, 'device_limit' => 2,
            'expired_at' => time() + 12 * 86400,
        ]);
        if ($paid) {
            Order::create([
                'user_id' => $user->id, 'plan_id' => $this->plan->id, 'period' => 'monthly', 'type' => Order::TYPE_NEW_PURCHASE,
                'trade_no' => Helper::generateOrderNo(), 'total_amount' => 2000, 'status' => Order::STATUS_COMPLETED,
                'created_at' => time() - 40 * 86400, 'updated_at' => time() - 40 * 86400,
            ]);
        }
        return $user;
    }

    private function mcpToken(User $user, bool $canOrder = false): string
    {
        return McpTokenService::make()->create($user, 'Claude', $canOrder)['token'];
    }

    private function panelToken(User $user): string
    {
        return substr((new AuthService($user))->generateAuthData()['auth_data'], strlen('Bearer '));
    }

    /** 每次请求前丢掉 guard 里缓存的用户：同一个测试里会换着令牌发请求 */
    private function bearer(string $token): self
    {
        $this->app['auth']->forgetGuards();
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token]);
    }

    private function rpc(string $token, string $method, array $params = [])
    {
        static $id = 0;
        return $this->bearer($token)->withHeaders([
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-06-18',
        ])->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => ++$id, 'method' => $method, 'params' => (object) $params]);
    }

    private function toolNames(string $token): array
    {
        return collect($this->rpc($token, 'tools/list')->assertOk()->json('result.tools'))->pluck('name')->all();
    }

    /** @return array{error: bool, text: string, data: array|null} */
    private function tool(string $token, string $tool, array $arguments = []): array
    {
        $response = $this->rpc($token, 'tools/call', ['name' => $tool, 'arguments' => (object) $arguments]);
        if ($response->json('error')) {
            // 没注册的工具（只读令牌调下单）：协议层直接报错
            return ['error' => true, 'text' => (string) $response->json('error.message'), 'data' => null];
        }
        $result = $response->assertOk()->json('result');
        return [
            'error' => (bool) ($result['isError'] ?? false),
            'text' => (string) ($result['content'][0]['text'] ?? ''),
            'data' => $result['structuredContent'] ?? null,
        ];
    }

    private function payment(string $method = 'EPay'): Payment
    {
        return Payment::create(['uuid' => Str::random(32), 'name' => $method, 'payment' => $method, 'config' => [], 'enable' => true]);
    }

    // ───────────────────────── 令牌隔离 ─────────────────────────

    public function test_assistant_tokens_are_refused_by_panel_endpoints_and_cannot_mint_a_quick_login(): void
    {
        $this->enable();
        $user = $this->user();
        $mcp = $this->mcpToken($user, true);

        $this->bearer($mcp)->getJson('/api/v1/user/info')->assertStatus(403);
        $this->bearer($mcp)->getJson('/api/v1/user/getSubscribe')->assertStatus(403);
        $this->bearer($mcp)->postJson('/api/v1/user/changePassword', ['old_password' => 'x', 'new_password' => 'yyyyyyyy'])->assertStatus(403);
        $this->bearer($mcp)->getJson('/api/v1/user/mcp/token/fetch')->assertStatus(403, '令牌不能给自己再签令牌');
        $this->assertNull(AuthService::findUserByBearerToken('Bearer ' . $mcp), '不能拿去换快捷登录链接');
        $this->assertNull(AuthService::decryptAuthData('Bearer ' . $mcp));

        // 面板会话令牌照旧
        $panel = $this->panelToken($user);
        $this->bearer($panel)->getJson('/api/v1/user/info')->assertOk();
        $this->assertSame($user->id, AuthService::findUserByBearerToken('Bearer ' . $panel)?->id);
    }

    public function test_guest_config_tells_the_homepage_whether_the_assistant_is_open(): void
    {
        $this->assertSame(0, $this->getJson('/api/v1/guest/comm/config')->assertOk()->json('data.ai_assistant_enable'));
        $this->enable();
        $this->assertSame(1, $this->getJson('/api/v1/guest/comm/config')->assertOk()->json('data.ai_assistant_enable'));
    }

    public function test_mcp_endpoint_is_closed_by_default_and_only_accepts_assistant_tokens(): void
    {
        $user = $this->user();
        // 先开着发令牌，再关掉：关掉后已发的令牌也用不了
        $this->enable();
        $mcp = $this->mcpToken($user);
        admin_setting(['mcp_enable' => 0]);
        $this->rpc($mcp, 'tools/list')->assertStatus(403);

        $this->enable();
        $this->rpc($this->panelToken($user), 'tools/list')->assertStatus(401)->assertHeader('WWW-Authenticate');
        $this->rpc('not-a-token', 'tools/list')->assertStatus(401);

        $init = $this->rpc($mcp, 'initialize', [
            'protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1'],
        ])->assertOk();
        $this->assertSame('苏菲家宽', $init->json('result.serverInfo.name'));
        $this->assertStringContainsString('payment_url', $init->json('result.instructions'));

        // 封号后令牌立刻失效
        $user->update(['banned' => 1]);
        $this->rpc($mcp, 'tools/list')->assertStatus(403);
    }

    public function test_panel_card_issues_tokens_only_to_eligible_accounts(): void
    {
        $user = $this->user();
        $panel = $this->panelToken($user);

        $this->bearer($panel)->getJson('/api/v1/user/mcp/token/fetch')->assertOk()
            ->assertJsonPath('data.enabled', false)->assertJsonPath('data.reason', 'disabled')->assertJsonPath('data.tokens', []);
        $this->bearer($panel)->postJson('/api/v1/user/mcp/token/create', ['name' => 'Claude'])->assertStatus(403);

        $this->enable();
        // 没付过款的账号：不发
        $fresh = $this->user([], false);
        $this->bearer($this->panelToken($fresh))->getJson('/api/v1/user/mcp/token/fetch')->assertOk()
            ->assertJsonPath('data.eligible', false)->assertJsonPath('data.reason', 'not_paid');
        // 后台账号：不发
        $admin = $this->user(['is_admin' => 1]);
        $this->assertSame(McpTokenService::REASON_ACCOUNT, McpTokenService::make()->ineligibleReason($admin));

        $created = $this->bearer($panel)->postJson('/api/v1/user/mcp/token/create', ['name' => '我的 Claude', 'can_order' => true])->assertOk();
        $plain = $created->json('data.token');
        $this->assertSame(self::APP_URL . '/mcp', $created->json('data.endpoint_url'));
        $this->assertContains('create_order', $this->toolNames($plain), '明文令牌直接能用');

        $list = $this->bearer($panel)->getJson('/api/v1/user/mcp/token/fetch')->assertOk()->json('data');
        $this->assertTrue($list['eligible']);
        $this->assertCount(1, $list['tokens']);
        $this->assertSame('我的 Claude', $list['tokens'][0]['name']);
        $this->assertTrue($list['tokens'][0]['can_order']);
        $this->assertNotNull($list['tokens'][0]['last_used_at']);
        $this->assertArrayNotHasKey('token', $list['tokens'][0], '列表里没有明文');

        // 每人最多 5 个
        for ($i = 0; $i < 4; $i++) {
            McpTokenService::make()->create($user, "t{$i}", false);
        }
        $this->bearer($panel)->postJson('/api/v1/user/mcp/token/create', ['name' => 'x'])->assertStatus(400);

        // 吊销后立刻失效；别人的令牌吊销不了
        $other = $this->user();
        $this->bearer($this->panelToken($other))->postJson('/api/v1/user/mcp/token/revoke', ['id' => $list['tokens'][0]['id']])->assertStatus(400);
        $this->bearer($panel)->postJson('/api/v1/user/mcp/token/revoke', ['id' => $list['tokens'][0]['id']])->assertOk();
        $this->rpc($plain, 'tools/list')->assertStatus(401);
    }

    // ───────────────────────── 只读 ─────────────────────────

    public function test_read_only_token_sees_no_ordering_tools_and_never_gets_subscription_secrets(): void
    {
        $this->enable();
        $user = $this->user();
        $token = $this->mcpToken($user);

        $names = $this->toolNames($token);
        $this->assertContains('get_account_overview', $names);
        $this->assertContains('get_payment_link', $names);
        $this->assertNotContains('create_order', $names);
        $this->assertNotContains('cancel_order', $names);
        $this->assertNotContains('create_recharge', $names);
        $this->assertTrue($this->tool($token, 'create_order', ['mode' => 'renew'])['error']);
        $this->assertSame(0, Order::where('user_id', $user->id)->where('status', Order::STATUS_PENDING)->count());

        $overview = $this->tool($token, 'get_account_overview');
        $this->assertFalse($overview['error'], $overview['text']);
        $this->assertSame('静态家宽拼车', $overview['data']['plan']['name']);
        $this->assertEquals(80, $overview['data']['traffic']['remaining_gb']);
        $this->assertSame('20.00', $overview['data']['renew_reference']['amount_yuan']);
        $this->assertNull($overview['data']['pending_order']);

        // 所有只读工具的输出里都不能出现订阅凭据
        $readTools = ['get_account_overview', 'get_traffic_daily', 'get_traffic_options', 'get_account_settings', 'list_orders',
            'list_balance_log', 'list_plans', 'list_offers', 'list_tickets', 'get_invite_info', 'search_help', 'list_nodes', 'get_payg_wallet'];
        $this->assertEqualsCanonicalizing(array_merge($readTools, ['quote_plan', 'get_payment_link']), $names, '只读令牌只有查询工具');
        foreach ($readTools as $tool) {
            $out = $this->tool($token, $tool);
            $this->assertFalse($out['error'], "{$tool}: {$out['text']}");
            $this->assertStringNotContainsString($user->token, $out['text'], $tool);
            $this->assertStringNotContainsString($user->uuid, $out['text'], $tool);
            $this->assertStringNotContainsString('/s/', $out['text'], $tool);
        }
        $this->assertSame(14, McpCallLog::where('user_id', $user->id)->where('ok', 1)->count(), '每次调用都记审计');
    }

    // ───────────────────────── 下单与付款 ─────────────────────────

    public function test_ai_order_uses_the_panel_order_service_and_is_paid_through_the_login_free_link(): void
    {
        $this->enable();
        $user = $this->user(['balance' => 500]);
        $token = $this->mcpToken($user, true);
        $payment = $this->payment();

        $quote = $this->tool($token, 'quote_plan', ['mode' => 'purchase', 'plan_id' => $this->bigPlan->id, 'period' => 'monthly']);
        $this->assertFalse($quote['error'], $quote['text']);

        // 价格变了（报价后后台改价）→ 拒绝下单，不按新价悄悄下
        $stale = $this->tool($token, 'create_order', ['mode' => 'purchase', 'plan_id' => $this->bigPlan->id, 'period' => 'monthly', 'expected_price_yuan' => '1.00']);
        $this->assertTrue($stale['error']);
        $this->assertSame(0, Order::where('user_id', $user->id)->where('status', Order::STATUS_PENDING)->count());

        $created = $this->tool($token, 'create_order', [
            'mode' => 'purchase', 'plan_id' => $this->bigPlan->id, 'period' => 'monthly', 'expected_price_yuan' => $quote['data']['price_yuan'],
        ]);
        $this->assertFalse($created['error'], $created['text']);
        $order = Order::where('trade_no', $created['data']['trade_no'])->firstOrFail();
        $this->assertSame(Order::STATUS_PENDING, (int) $order->status);
        $this->assertSame($this->bigPlan->id, (int) $order->plan_id);
        $this->assertSame(500, (int) $order->balance_amount, '余额先抵扣，和面板下单一样');
        $this->assertSame(0, (int) $user->fresh()->balance);
        $this->assertSame(OrderPayService::url($order->trade_no), $created['data']['payment_url']);
        $this->assertStringStartsWith(self::APP_URL . '/pay/o-' . $order->trade_no . '-', $created['data']['payment_url']);
        $this->assertSame((string) $order->trade_no, McpCallLog::where('tool', 'create_order')->where('ok', 1)->value('ref'));

        // 已有待付单：不重复下单，把那一单的付款链接给出来
        $again = $this->tool($token, 'create_order', ['mode' => 'renew']);
        $this->assertTrue($again['error']);
        $this->assertStringContainsString($created['data']['payment_url'], $again['text']);
        $link = $this->tool($token, 'get_payment_link');
        $this->assertSame($created['data']['payment_url'], $link['data']['payment_url']);

        // 客户打开付款链接：不用登录
        $payToken = OrderPayService::token($order->trade_no);
        $this->app['auth']->forgetGuards();
        $view = $this->postJson('/api/v1/guest/order/pay/fetch', ['token' => $payToken])->assertOk()->json('data');
        $this->assertSame('pending', $view['state']);
        $this->assertSame('静态家宽独享', $view['order']['plan_name']);
        $this->assertSame((int) $order->total_amount, $view['order']['total_amount']);
        $this->assertSame(Order::TYPE_UPGRADE, (int) $order->type, '有在用的套餐，换成别的套餐 = 套餐变更（剩余价值折抵）');
        $this->assertGreaterThan(0, (int) $order->surplus_amount);
        $this->assertStringNotContainsString($user->email, json_encode($view, JSON_UNESCAPED_UNICODE), '邮箱打码');
        $this->assertSame([$payment->id], array_column($view['methods'], 'id'));

        // 签名不对 / 订单号被改：一律 404
        $this->postJson('/api/v1/guest/order/pay/fetch', ['token' => substr($payToken, 0, -1) . (str_ends_with($payToken, 'a') ? 'b' : 'a')])->assertStatus(404);
        $this->postJson('/api/v1/guest/order/pay/fetch', ['token' => 'o-' . Helper::generateOrderNo() . '-' . substr($payToken, -32)])->assertStatus(404);

        $paid = $this->postJson('/api/v1/guest/order/pay/checkout', ['token' => $payToken, 'method' => $payment->id])->assertOk();
        $this->assertSame(1, $paid->json('type'));
        $this->assertCount(1, McpFakeGateway::$calls, '走的是同一个收银台核心，金额由它算');
        $this->assertSame($order->trade_no, McpFakeGateway::$calls[0]['trade_no']);
        $this->assertSame(OrderPayService::url($order->trade_no), McpFakeGateway::$calls[0]['return_url']);

        // 客户不想要了：取消，余额退回
        $this->postJson('/api/v1/guest/order/pay/cancel', ['token' => $payToken])->assertOk();
        $this->assertSame(Order::STATUS_CANCELLED, (int) $order->fresh()->status);
        $this->assertSame(500, (int) $user->fresh()->balance);
        $this->postJson('/api/v1/guest/order/pay/fetch', ['token' => $payToken])->assertOk()->assertJsonPath('data.state', 'closed');
        $this->postJson('/api/v1/guest/order/pay/checkout', ['token' => $payToken, 'method' => $payment->id])->assertStatus(400);
    }

    public function test_ordering_switch_and_daily_limit(): void
    {
        $this->enable(['mcp_daily_order_limit' => 1]);
        $user = $this->user();
        $token = $this->mcpToken($user, true);

        $first = $this->tool($token, 'create_order', ['mode' => 'renew']);
        $this->assertFalse($first['error'], $first['text']);
        $this->assertSame(Order::TYPE_RENEWAL, (int) Order::where('trade_no', $first['data']['trade_no'])->value('type'));
        $cancel = $this->tool($token, 'cancel_order', ['trade_no' => $first['data']['trade_no']]);
        $this->assertFalse($cancel['error'], $cancel['text']);

        $second = $this->tool($token, 'create_order', ['mode' => 'renew']);
        $this->assertTrue($second['error']);
        $this->assertStringContainsString('上限', $second['text']);

        // 后台关掉「允许 AI 替客户操作」：下单类工具整个消失，查询照常
        admin_setting(['mcp_order_enable' => 0, 'mcp_daily_order_limit' => 10]);
        $names = $this->toolNames($token);
        $this->assertNotContains('create_order', $names);
        $this->assertContains('get_account_overview', $names);
        $this->assertTrue($this->tool($token, 'create_order', ['mode' => 'renew'])['error']);
    }

    // ───────────────────────── 只碰令牌主人自己的账户 ─────────────────────────

    public function test_tools_only_touch_the_token_owners_account(): void
    {
        $this->enable();
        $alice = $this->user();
        $bob = $this->user();
        $bobOrder = Order::create([
            'user_id' => $bob->id, 'plan_id' => $this->bigPlan->id, 'period' => 'monthly', 'type' => Order::TYPE_UPGRADE,
            'trade_no' => Helper::generateOrderNo(), 'total_amount' => 4500, 'status' => Order::STATUS_PENDING,
        ]);
        $bobTicket = (new TicketService())->createTicket($bob->id, '鲍勃的工单', 1, '东京 01 连不上', [], 'connection');
        $token = $this->mcpToken($alice, true);

        // 没有一个工具接受「用户 ID / 邮箱」之类的参数：想指定别人也没有入口
        foreach ($this->rpc($token, 'tools/list')->assertOk()->json('result.tools') as $tool) {
            $props = array_map('strtolower', array_keys((array) ($tool['inputSchema']['properties'] ?? [])));
            $this->assertEmpty(array_intersect($props, ['user_id', 'uid', 'email', 'user', 'account']), $tool['name']);
        }

        // 拿别人的订单号：查不到、取消不了
        $this->assertTrue($this->tool($token, 'get_payment_link', ['trade_no' => $bobOrder->trade_no])['error']);
        $this->assertTrue($this->tool($token, 'cancel_order', ['trade_no' => $bobOrder->trade_no])['error']);
        $this->assertSame(Order::STATUS_PENDING, (int) $bobOrder->fresh()->status);
        $this->assertStringNotContainsString($bobOrder->trade_no, $this->tool($token, 'list_orders')['text']);

        // 拿别人的工单编号：看不到、回不了、关不掉
        $bobRef = McpTicketRef::of($bobTicket);
        $this->assertStringNotContainsString('鲍勃的工单', $this->tool($token, 'list_tickets')['text']);
        $this->assertTrue($this->tool($token, 'reply_ticket', ['ticket_ref' => $bobRef, 'message' => '插一句'])['error']);
        $this->assertTrue($this->tool($token, 'close_ticket', ['ticket_ref' => $bobRef])['error']);
        $this->assertSame(Ticket::STATUS_OPENING, (int) $bobTicket->fresh()->status);
        $this->assertSame(1, TicketMessage::where('ticket_id', $bobTicket->id)->count());

        // 别人的邮箱、订阅凭据不出现在任何输出里
        foreach (['get_account_overview', 'get_account_settings', 'get_invite_info', 'list_balance_log'] as $tool) {
            $text = $this->tool($token, $tool)['text'];
            foreach ([$bob->email, $bob->token, $bob->uuid] as $secret) {
                $this->assertStringNotContainsString($secret, $text, $tool);
            }
        }
    }

    // ───────────────────────── 面板操作：设置、流量、工单、订阅 ─────────────────────────

    public function test_ai_switches_auto_renew_and_email_notifications_like_the_panel(): void
    {
        $this->enable();
        $user = $this->user(['balance' => 500]);
        $readOnly = $this->mcpToken($user);
        $this->assertNotContains('update_account_settings', $this->toolNames($readOnly));
        $token = $this->mcpToken($user, true);

        $view = $this->tool($token, 'get_account_settings');
        $this->assertFalse($view['error'], $view['text']);
        $this->assertFalse($view['data']['auto_renew']['enabled']);
        $this->assertSame('20.00', $view['data']['auto_renew']['renew_amount_yuan']);
        $this->assertSame('15.00', $view['data']['auto_renew']['shortfall_yuan']);

        $on = $this->tool($token, 'update_account_settings', ['auto_renew' => true, 'email_notifications' => ['marketing' => false]]);
        $this->assertFalse($on['error'], $on['text']);
        $this->assertSame(1, (int) $user->fresh()->auto_renew);
        $this->assertFalse(NotificationPreference::enabled($user->fresh(), NotificationPreference::MARKETING));
        $this->assertSame(UserNotificationPref::SOURCE_MCP, UserNotificationPref::where('user_id', $user->id)->where('category', 'marketing')->value('source'));
        $this->assertTrue($on['data']['settings']['auto_renew']['enabled']);

        $this->assertTrue($this->tool($token, 'update_account_settings', ['email_notifications' => ['spam' => false]])['error']);
        $this->assertTrue($this->tool($token, 'update_account_settings', [])['error']);
        $this->assertTrue($this->tool($token, 'update_account_settings', ['payg_auto_topup' => true])['error'], '不是按量套餐');

        $this->assertFalse($this->tool($token, 'update_account_settings', ['auto_renew' => false])['error']);
        $this->assertSame(0, (int) $user->fresh()->auto_renew);
        admin_setting(['auto_renew_enable' => 0]);
        $this->assertTrue($this->tool($token, 'update_account_settings', ['auto_renew' => true])['error'], '站点关了自动续费');
        $this->assertSame(0, (int) $user->fresh()->auto_renew);
    }

    public function test_ai_resets_traffic_by_pack_or_by_advancing_the_cycle(): void
    {
        $this->enable();
        $this->plan->prices = ['monthly' => 20, 'quarterly' => 57, 'reset_traffic' => 5];
        $this->plan->save();
        $expiredAt = time() + 40 * 86400;
        $user = $this->user(['u' => 30 * self::GB, 'd' => 67 * self::GB, 'expired_at' => $expiredAt, 'next_reset_at' => time() + 10 * 86400]);
        $token = $this->mcpToken($user, true);

        $options = $this->tool($token, 'get_traffic_options');
        $this->assertFalse($options['error'], $options['text']);
        $this->assertEquals(3, $options['data']['remaining_gb']);
        $this->assertTrue($options['data']['reset_traffic_pack']['available']);
        $this->assertSame('5.00', $options['data']['reset_traffic_pack']['price_yuan']);
        $this->assertTrue($options['data']['advance_cycle']['available'], json_encode($options['data']['advance_cycle'], JSON_UNESCAPED_UNICODE));
        $this->assertFalse($options['data']['traffic_topup']['available'], '套餐没开加购');

        // 花钱的重置包：报价 → 下单，和面板一样是一张「流量重置」订单
        $quote = $this->tool($token, 'quote_plan', ['mode' => 'reset_traffic']);
        $this->assertFalse($quote['error'], $quote['text']);
        $this->assertSame('5.00', $quote['data']['price_yuan']);
        $created = $this->tool($token, 'create_order', ['mode' => 'reset_traffic', 'expected_price_yuan' => '5.00']);
        $this->assertFalse($created['error'], $created['text']);
        $order = Order::where('trade_no', $created['data']['trade_no'])->firstOrFail();
        $this->assertSame(Plan::PERIOD_RESET_TRAFFIC, $order->period);
        $this->assertSame(Order::TYPE_RESET_TRAFFIC, (int) $order->type);
        $this->assertFalse($this->tool($token, 'cancel_order', ['trade_no' => $order->trade_no])['error']);
        $this->assertTrue($this->tool($token, 'create_order', ['mode' => 'purchase', 'plan_id' => $this->plan->id, 'period' => 'reset_traffic'])['error'], '重置包只走 mode=reset_traffic');

        // 免费提前重置：不带 confirm 只预览，什么都不改
        $preview = $this->tool($token, 'advance_traffic_cycle');
        $this->assertFalse($preview['error'], $preview['text']);
        $this->assertFalse($preview['data']['done']);
        $this->assertSame(97 * self::GB, (int) $user->fresh()->u + (int) $user->fresh()->d);
        $this->assertSame($expiredAt, (int) $user->fresh()->expired_at);

        $done = $this->tool($token, 'advance_traffic_cycle', ['confirm' => true]);
        $this->assertFalse($done['error'], $done['text']);
        $this->assertTrue($done['data']['done']);
        $fresh = $user->fresh();
        $this->assertSame(0, (int) $fresh->u + (int) $fresh->d);
        $this->assertSame($expiredAt - 30 * 86400, (int) $fresh->expired_at, '到期日提前 30 天');
        $this->assertTrue($this->tool($token, 'advance_traffic_cycle', ['confirm' => true])['error'], '流量没用完不能再提前');
    }

    public function test_ai_handles_tickets_restock_alerts_gift_cards_and_subscription_reset(): void
    {
        $this->enable();
        $user = $this->user();
        $token = $this->mcpToken($user, true);

        $created = $this->tool($token, 'create_ticket', ['subject' => '东京 01 晚上卡顿', 'message' => '每晚 9 点卡几秒', 'category' => 'connection']);
        $this->assertFalse($created['error'], $created['text']);
        $ticket = Ticket::where('user_id', $user->id)->firstOrFail();
        $this->assertSame($created['data']['ticket_ref'], McpTicketRef::of($ticket));
        $this->assertStringContainsString('AI 助手', (string) TicketMessage::where('ticket_id', $ticket->id)->value('message'), '客服看得出是 AI 代写的');
        $this->assertSame($created['data']['ticket_ref'], $this->tool($token, 'list_tickets')['data']['tickets'][0]['ticket_ref']);
        $this->assertTrue($this->tool($token, 'create_ticket', ['subject' => '再开一张', 'message' => 'x'])['error'], '求助工单同时只能开一张');

        $this->assertFalse($this->tool($token, 'reply_ticket', ['ticket_ref' => strtolower($created['data']['ticket_ref']), 'message' => '补充：用的是 Clash'])['error']);
        $this->assertSame(2, TicketMessage::where('ticket_id', $ticket->id)->count());
        $this->assertFalse($this->tool($token, 'close_ticket', ['ticket_ref' => $created['data']['ticket_ref']])['error']);
        $this->assertSame(Ticket::STATUS_CLOSED, (int) $ticket->fresh()->status);
        $this->assertTrue($this->tool($token, 'reply_ticket', ['ticket_ref' => $created['data']['ticket_ref'], 'message' => '还在吗'])['error']);

        // 到货提醒：只有售罄的套餐能登记
        $this->assertTrue($this->tool($token, 'set_restock_alert', ['plan_id' => $this->bigPlan->id])['error'], '有位置的套餐直接买');
        $this->bigPlan->capacity_limit = 0;
        $this->bigPlan->save();
        $this->assertFalse($this->tool($token, 'set_restock_alert', ['plan_id' => $this->bigPlan->id])['error']);
        $this->assertSame(1, PlanRestockNotify::where('plan_id', $this->bigPlan->id)->where('email', $user->email)->count());
        $this->assertFalse($this->tool($token, 'set_restock_alert', ['plan_id' => $this->bigPlan->id, 'cancel' => true])['error']);
        $this->assertSame(0, PlanRestockNotify::where('plan_id', $this->bigPlan->id)->count());

        // 礼品卡：错码报错；与面板共用每分钟 5 次的限流桶（AI 试 3 次 + 面板试 2 次就满了，两边都被挡）
        for ($i = 0; $i < 3; $i++) {
            $miss = $this->tool($token, 'redeem_gift_card', ['code' => 'NOPE' . $i . 'XXXXXX']);
            $this->assertTrue($miss['error']);
            $this->assertStringNotContainsString('频繁', $miss['text']);
        }
        $panel = $this->panelToken($user);
        for ($i = 0; $i < 2; $i++) {
            $this->assertNotSame(429, $this->bearer($panel)->postJson('/api/v1/user/gift-card/redeem', ['code' => 'PANEL' . $i . 'XXXXXX'])->status());
        }
        $this->assertStringContainsString('频繁', $this->tool($token, 'redeem_gift_card', ['code' => 'NOPE9XXXXXX'])['text']);
        $this->bearer($panel)->postJson('/api/v1/user/gift-card/redeem', ['code' => 'PANEL9XXXXXX'])->assertStatus(429);

        // 重置订阅：不带 confirm 只说后果；确认后旧凭据作废，新链接不经过 AI
        [$oldToken, $oldUuid] = [$user->token, $user->uuid];
        $this->assertFalse($this->tool($token, 'reset_subscription')['data']['done']);
        $this->assertSame($oldToken, $user->fresh()->token);
        $reset = $this->tool($token, 'reset_subscription', ['confirm' => true]);
        $this->assertTrue($reset['data']['done']);
        $fresh = $user->fresh();
        $this->assertNotSame($oldToken, $fresh->token);
        $this->assertNotSame($oldUuid, $fresh->uuid);
        $this->assertStringNotContainsString($fresh->token, $reset['text']);
        $this->assertStringNotContainsString($fresh->uuid, $reset['text']);

        // 只读令牌看不到这些操作
        $names = $this->toolNames($this->mcpToken($user));
        foreach (['create_ticket', 'reply_ticket', 'close_ticket', 'set_restock_alert', 'redeem_gift_card', 'reset_subscription', 'advance_traffic_cycle'] as $tool) {
            $this->assertNotContains($tool, $names);
        }
    }

    // ───────────────────────── 佣金：提现与转余额 ─────────────────────────

    public function test_ai_withdraws_commission_only_to_the_saved_address_and_moves_it_to_balance(): void
    {
        $this->enable();
        admin_setting(['commission_withdraw_limit' => 10, 'commission_withdraw_rate_source' => 'manual', 'commission_withdraw_usdt_rate' => 7.2]);
        $address = 'TN3W4H6rK2ce4vX9YnFQHwKENnHjoxb3m9';
        $user = $this->user(['commission_balance' => 30000, 'balance' => 500]);
        $token = $this->mcpToken($user, true);

        // 没在面板填过收款地址：AI 提不了，工具也没有填地址的参数
        $first = $this->tool($token, 'withdraw_commission', ['amount_yuan' => '150']);
        $this->assertTrue($first['error']);
        $this->assertStringContainsString('面板', $first['text']);
        $schema = collect($this->rpc($token, 'tools/list')->json('result.tools'))->firstWhere('name', 'withdraw_commission');
        $this->assertEqualsCanonicalizing(['amount_yuan', 'confirm'], array_keys($schema['inputSchema']['properties']));

        // 面板提过一次、留下收款信息之后：地址只给头尾
        UserPayoutProfile::create(['user_id' => $user->id, 'chain_code' => 'usdt_trc20', 'address' => $address]);
        $info = $this->tool($token, 'get_invite_info');
        $this->assertSame('TN3W4H…b3m9', $info['data']['withdrawal']['payout_address']['address']);
        $this->assertStringNotContainsString($address, $info['text']);

        // 预览不动账户；确认后是与面板同一张提现单：佣金冻结、开系统工单，工单里注明是 AI 代提
        $preview = $this->tool($token, 'withdraw_commission', ['amount_yuan' => '150']);
        $this->assertFalse($preview['data']['done'], $preview['text']);
        $this->assertSame('150.00', $preview['data']['amount_yuan']);
        $this->assertSame('TN3W4H…b3m9', $preview['data']['to']['address']);
        $this->assertSame(30000, (int) $user->fresh()->commission_balance);
        $this->assertSame(0, CommissionWithdrawal::count());

        $done = $this->tool($token, 'withdraw_commission', ['amount_yuan' => '150', 'confirm' => true]);
        $this->assertTrue($done['data']['done'], $done['text']);
        $withdrawal = CommissionWithdrawal::where('user_id', $user->id)->firstOrFail();
        $this->assertSame($address, $withdrawal->address);
        $this->assertSame(15000, (int) $withdrawal->amount);
        $this->assertSame(15000, (int) $user->fresh()->commission_balance);
        $this->assertSame($withdrawal->reference(), $done['data']['withdrawal']['reference']);
        $this->assertStringContainsString('AI 助手', (string) TicketMessage::where('ticket_id', $withdrawal->ticket_id)->value('message'));
        $this->assertMatchesRegularExpression('/^WD-\d{8}-[0-9A-Z]{8}$/', $done['data']['withdrawal']['reference'], '编号不带自增 id');
        $again = $this->tool($token, 'withdraw_commission', ['amount_yuan' => '200', 'confirm' => true]);
        $this->assertTrue($again['error']);
        $this->assertStringContainsString('处理中', $again['text'], '同一时间只有一笔，先说处理中的那笔而不是余额不足');

        // 取消：冻结的佣金退回
        $cancel = $this->tool($token, 'cancel_withdrawal');
        $this->assertFalse($cancel['error'], $cancel['text']);
        $this->assertSame(CommissionWithdrawal::STATUS_CANCELLED, (int) $withdrawal->fresh()->status);
        $this->assertSame(30000, (int) $user->fresh()->commission_balance);
        $this->assertTrue($this->tool($token, 'cancel_withdrawal')['error']);

        // 佣金转余额：预览不动账户，确认后记一笔余额流水
        $this->assertFalse($this->tool($token, 'transfer_commission', ['amount_yuan' => '50'])['data']['done']);
        $this->assertSame(500, (int) $user->fresh()->balance);
        $moved = $this->tool($token, 'transfer_commission', ['amount_yuan' => '50', 'confirm' => true]);
        $this->assertTrue($moved['data']['done'], $moved['text']);
        $fresh = $user->fresh();
        $this->assertSame(25000, (int) $fresh->commission_balance);
        $this->assertSame(5500, (int) $fresh->balance);
        $this->assertSame(1, BalanceLog::where('user_id', $user->id)->where('type', BalanceLog::TYPE_COMMISSION_TRANSFER)->count());
        $this->assertTrue($this->tool($token, 'transfer_commission', ['amount_yuan' => '9999', 'confirm' => true])['error']);

        // 门槛与面板一致：付费被邀请人不够就提不了
        admin_setting(['commission_withdraw_min_invitees' => 3]);
        $this->assertTrue($this->tool($token, 'withdraw_commission', ['amount_yuan' => '20'])['error']);
        $this->assertSame(0, CommissionWithdrawal::where('status', CommissionWithdrawal::STATUS_PENDING)->count());

        // 只读令牌看不到这三个
        $names = $this->toolNames($this->mcpToken($user));
        foreach (['withdraw_commission', 'cancel_withdrawal', 'transfer_commission'] as $tool) {
            $this->assertNotContains($tool, $names);
        }
    }

    // ───────────────────────── 节日优惠：公告里的优惠码 ─────────────────────────

    public function test_ai_offers_only_coupons_published_in_announcements_and_applies_them(): void
    {
        $this->enable();
        $coupon = fn (string $code, int $type, int $value, array $extra = []) => Coupon::create($extra + [
            'code' => $code, 'name' => $code . ' 券', 'type' => $type, 'value' => $value, 'show' => true,
            'limit_use' => null, 'limit_use_with_user' => null, 'started_at' => time() - 86400, 'ended_at' => time() + 7 * 86400,
        ]);
        $coupon('GUOQING20', 2, 20);
        $coupon('VIPONLY88', 1, 500);                                        // 私下发给渠道的码：公告里没写
        $coupon('OLD2025', 2, 30, ['ended_at' => time() - 60]);             // 公告还挂着，但券已过期
        $coupon('DRAFT10', 2, 10);                                           // 只出现在未上架的公告里
        Notice::create(['title' => '国庆快乐', 'content' => '<p>全场 8 折，下单填 <b>GUOQING20</b>；去年的 OLD2025 已失效。</p>', 'show' => true]);
        Notice::create(['title' => '草稿', 'content' => '双十一用 DRAFT10', 'show' => false]);

        $user = $this->user();
        $token = $this->mcpToken($user, true);

        $offers = $this->tool($token, 'list_offers');
        $this->assertFalse($offers['error'], $offers['text']);
        $this->assertSame(['GUOQING20'], array_column($offers['data']['coupons'], 'code'));
        $this->assertSame('8 折（减 20%）', $offers['data']['coupons'][0]['discount']);
        $this->assertSame('国庆快乐', $offers['data']['notices'][0]['title']);
        $this->assertStringNotContainsString('VIPONLY88', $offers['text']);
        $this->assertStringNotContainsString('DRAFT10', $offers['text']);

        // 客户只是来查流量，AI 也能看到正在发的券
        $overview = $this->tool($token, 'get_account_overview');
        $this->assertSame('GUOQING20', $overview['data']['current_offers'][0]['code']);
        $this->assertStringNotContainsString('VIPONLY88', $overview['text']);

        $quote = $this->tool($token, 'quote_plan', ['mode' => 'renew', 'coupon_code' => 'GUOQING20']);
        $this->assertFalse($quote['error'], $quote['text']);
        $this->assertTrue($quote['data']['coupon']['valid']);
        $this->assertSame('4.00', $quote['data']['coupon']['discount_yuan']);
        $this->assertSame('16.00', $quote['data']['cash_due_estimate_yuan']);
        $this->assertFalse($this->tool($token, 'quote_plan', ['mode' => 'renew', 'coupon_code' => 'OLD2025'])['data']['coupon']['valid']);

        $created = $this->tool($token, 'create_order', ['mode' => 'renew', 'coupon_code' => 'GUOQING20', 'expected_price_yuan' => $quote['data']['price_yuan']]);
        $this->assertFalse($created['error'], $created['text']);
        $order = Order::where('trade_no', $created['data']['trade_no'])->firstOrFail();
        $this->assertSame(400, (int) $order->discount_amount);
        $this->assertSame(1600, (int) $order->total_amount);
        $this->assertSame('16.00', $created['data']['cash_due_yuan']);
    }

    public function test_admin_settings_validate(): void
    {
        $rules = (new ConfigSave())->rules();
        $ok = Validator::make([
            'mcp_enable' => 1, 'mcp_endpoint_url' => 'https://ai.example.test/mcp',
            'mcp_order_enable' => 0, 'mcp_daily_order_limit' => 0, 'mcp_rate_per_minute' => 60, 'mcp_rate_per_day' => 2000, 'mcp_token_idle_days' => 90,
        ], $rules);
        $this->assertFalse($ok->fails(), json_encode($ok->errors()->all(), JSON_UNESCAPED_UNICODE));
        $bad = Validator::make(['mcp_endpoint_url' => 'ai.example.test', 'mcp_rate_per_minute' => 0, 'mcp_token_idle_days' => 400], $rules);
        $this->assertEqualsCanonicalizing(['mcp_endpoint_url', 'mcp_rate_per_minute', 'mcp_token_idle_days'], array_keys($bad->errors()->toArray()));
    }
}

/** 不连支付插件：记下 pay() 收到的参数，回一个固定的收款地址 */
class McpFakeGateway extends PaymentService
{
    public static array $calls = [];

    public function __construct()
    {
    }

    public function pay($order)
    {
        self::$calls[] = $order;
        return ['type' => 1, 'data' => 'https://gateway.example.test/pay?no=' . $order['trade_no']];
    }
}

class McpFakeCheckout extends CheckoutService
{
    protected function gateway(Payment $payment): PaymentService
    {
        return new McpFakeGateway();
    }
}
