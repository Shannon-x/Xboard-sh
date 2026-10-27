<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\ConfigSave;
use App\Models\McpCallLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\AuthService;
use App\Services\CheckoutService;
use App\Services\Mcp\McpTokenService;
use App\Services\Mcp\OrderPayService;
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

        // 试点名单：不在名单里的账号不发
        admin_setting(['mcp_whitelist' => strtoupper($other->email)]);
        $this->assertNull(McpTokenService::make()->ineligibleReason($other), '名单按邮箱匹配，不分大小写');
        $this->assertSame(McpTokenService::REASON_NOT_LISTED, McpTokenService::make()->ineligibleReason($user));
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
        foreach (['get_account_overview', 'get_traffic_daily', 'list_orders', 'list_plans', 'list_tickets', 'search_help', 'list_nodes', 'get_payg_wallet'] as $tool) {
            $out = $this->tool($token, $tool);
            $this->assertFalse($out['error'], "{$tool}: {$out['text']}");
            $this->assertStringNotContainsString($user->token, $out['text'], $tool);
            $this->assertStringNotContainsString($user->uuid, $out['text'], $tool);
            $this->assertStringNotContainsString('/s/', $out['text'], $tool);
        }
        $this->assertSame(9, McpCallLog::where('user_id', $user->id)->where('ok', 1)->count(), '每次调用都记审计');
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

        // 后台关掉「允许 AI 下单」：下单类工具整个消失，查询照常
        admin_setting(['mcp_order_enable' => 0, 'mcp_daily_order_limit' => 10]);
        $names = $this->toolNames($token);
        $this->assertNotContains('create_order', $names);
        $this->assertContains('get_account_overview', $names);
        $this->assertTrue($this->tool($token, 'create_order', ['mode' => 'renew'])['error']);
    }

    public function test_admin_settings_validate(): void
    {
        $rules = (new ConfigSave())->rules();
        $ok = Validator::make([
            'mcp_enable' => 1, 'mcp_whitelist' => "a@example.test\n42", 'mcp_endpoint_url' => 'https://ai.example.test/mcp',
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
