<?php

namespace Tests\Feature;

use App\Models\McpCallLog;
use App\Models\McpOauthGrant;
use App\Models\Order;
use App\Models\Plan;
use App\Models\RechargeOrder;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\Mcp\McpTokenService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 后台「AI 助手」页：管理员按客户查 AI 调用记录、AI 建的单、客户连着的 AI 软件，并能替客户断开。
 */
class McpAdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        app()->forgetScopedInstances();
        admin_setting(['mcp_enable' => 1]);

        $group = new ServerGroup();
        $group->name = '基础';
        $group->save();
        $this->plan = Plan::create([
            'name' => '静态家宽拼车', 'group_id' => $group->id, 'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => null, 'reset_traffic_method' => 1,
            'prices' => ['monthly' => 20],
        ]);
    }

    private function adminPath(string $path): string
    {
        return '/api/v2/' . admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))) . $path;
    }

    private function user(array $attributes = []): User
    {
        return User::create($attributes + [
            'email' => Str::lower(Str::random(8)) . '@example.test', 'password' => 'x',
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'plan_id' => $this->plan->id, 'group_id' => $this->plan->group_id, 'balance' => 0,
            'transfer_enable' => 1073741824, 'u' => 0, 'd' => 0, 'device_limit' => 2,
            'expired_at' => time() + 12 * 86400,
        ]);
    }

    private function asAdmin(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->user(['is_admin' => 1]), ['admin']);
    }

    private function log(User $user, int $tokenId, string $tool, bool $ok = true, ?string $ref = null, ?string $message = null, ?int $at = null): McpCallLog
    {
        $log = McpCallLog::create([
            'user_id' => $user->id, 'token_id' => $tokenId, 'tool' => $tool, 'ok' => $ok ? 1 : 0,
            'ref' => $ref, 'message' => $message, 'ip' => '203.0.113.9',
        ]);
        if ($at !== null) {
            McpCallLog::whereKey($log->id)->update(['created_at' => $at]);
        }
        return $log;
    }

    public function test_admin_sees_a_customers_calls_and_the_orders_ai_created(): void
    {
        $alice = $this->user(['email' => 'alice@example.test']);
        $bob = $this->user(['email' => 'bob@example.test']);
        $token = McpTokenService::make()->issue($alice, 'Claude', true);
        $bobToken = McpTokenService::make()->issue($bob, 'ChatGPT', false);

        $order = Order::create([
            'user_id' => $alice->id, 'plan_id' => $this->plan->id, 'period' => 'monthly', 'type' => Order::TYPE_NEW_PURCHASE,
            'trade_no' => Helper::generateOrderNo(), 'total_amount' => 2000, 'status' => Order::STATUS_PENDING,
        ]);
        $recharge = RechargeOrder::create([
            'user_id' => $alice->id, 'trade_no' => Helper::generateOrderNo(), 'deposit_amount' => 5000, 'bonus_amount' => 0,
            'total_credit' => 5000, 'pay_amount' => 5000, 'status' => RechargeOrder::STATUS_COMPLETED, 'paid_at' => time(),
        ]);

        $this->log($alice, $token['id'], 'get_account_overview');
        $this->log($alice, $token['id'], 'create_order', true, $order->trade_no);
        $this->log($alice, $token['id'], 'create_recharge', true, $recharge->trade_no);
        $this->log($alice, $token['id'], 'create_order', false, null, '套餐已售罄');
        $this->log($bob, $bobToken['id'], 'list_plans');
        $this->log($alice, $token['id'], 'list_plans', true, null, null, time() - 10 * 86400);

        $this->asAdmin();

        $all = $this->postJson($this->adminPath('/mcp/log/fetch'), ['email' => 'alice@'])->assertStatus(200)->json();
        $this->assertSame(5, $all['total']);
        $this->assertSame(['alice@example.test'], array_values(array_unique(array_column($all['data'], 'email'))));
        $this->assertSame('Claude', $all['data'][0]['token_name']);

        $failed = $this->postJson($this->adminPath('/mcp/log/fetch'), ['user_id' => $alice->id, 'ok' => 0])->json('data');
        $this->assertCount(1, $failed);
        $this->assertSame('套餐已售罄', $failed[0]['message']);

        $orders = $this->postJson($this->adminPath('/mcp/log/fetch'), ['user_id' => $alice->id, 'orders_only' => 1])->json('data');
        $this->assertCount(2, $orders);
        $byRef = collect($orders)->keyBy('ref');
        $this->assertSame('order', $byRef[$order->trade_no]['order']['kind']);
        $this->assertSame(2000, $byRef[$order->trade_no]['order']['amount']);
        $this->assertSame('静态家宽拼车', $byRef[$order->trade_no]['order']['plan_name']);
        $this->assertSame(Order::STATUS_PENDING, $byRef[$order->trade_no]['order']['status']);
        $this->assertSame('recharge', $byRef[$recharge->trade_no]['order']['kind']);
        $this->assertSame(RechargeOrder::STATUS_COMPLETED, $byRef[$recharge->trade_no]['order']['status']);

        $this->assertSame(0, $this->postJson($this->adminPath('/mcp/log/fetch'), ['email' => 'nobody@'])->json('total'));

        $stats = $this->getJson($this->adminPath('/mcp/stats'))->assertStatus(200)->json('data');
        $this->assertSame(5, $stats['calls_today']);
        $this->assertSame(1, $stats['failed_today']);
        $this->assertSame(2, $stats['orders_today']);
        $this->assertSame(2, $stats['users_7d']);
        $this->assertSame(2, $stats['connections']);
        $this->assertSame(2, $stats['connected_users']);
        $this->assertSame('下单', $stats['tools']['create_order'] ?? null);
    }

    public function test_admin_lists_and_disconnects_a_customers_ai_apps(): void
    {
        $alice = $this->user(['email' => 'alice@example.test']);
        $bob = $this->user(['email' => 'bob@example.test']);
        $manual = McpTokenService::make()->issue($alice, 'Claude', true);
        $oauth = McpTokenService::make()->issue($alice, 'ChatGPT', false);
        McpOauthGrant::create([
            'user_id' => $alice->id, 'client_key' => hash('sha256', 'chatgpt'), 'client_id' => 'chatgpt',
            'app_name' => 'ChatGPT', 'redirect_host' => 'chatgpt.com', 'token_id' => $oauth['id'],
            'refresh_hash' => hash('sha256', 'r1'),
        ]);
        $bobs = McpTokenService::make()->issue($bob, 'Cursor', false);
        // 面板会话令牌不是 AI 连接，不能出现在列表里
        $alice->createToken('panel', ['*']);
        $this->log($alice, $manual['id'], 'create_order', true, 'T1');
        $this->log($alice, $oauth['id'], 'list_plans');

        $this->asAdmin();

        $rows = $this->postJson($this->adminPath('/mcp/connection/fetch'), ['user_id' => $alice->id])->assertStatus(200)->json('data');
        $this->assertCount(2, $rows);
        $byId = collect($rows)->keyBy('id');
        $this->assertSame('oauth', $byId[$oauth['id']]['via']);
        $this->assertSame('chatgpt.com', $byId[$oauth['id']]['app_host']);
        $this->assertSame('token', $byId[$manual['id']]['via']);
        $this->assertTrue($byId[$manual['id']]['can_order']);
        $this->assertSame(1, $byId[$manual['id']]['orders_total']);
        $this->assertSame(1, $byId[$manual['id']]['calls_7d']);
        $this->assertArrayNotHasKey('token', $byId[$manual['id']]);

        $this->assertSame(3, $this->postJson($this->adminPath('/mcp/connection/fetch'))->json('total'));

        // 别的客户的令牌不能借 alice 的名义断开
        $this->postJson($this->adminPath('/mcp/connection/revoke'), ['user_id' => $alice->id, 'id' => $bobs['id']])->assertStatus(400);
        $this->assertNotNull(PersonalAccessToken::find($bobs['id']));

        $this->postJson($this->adminPath('/mcp/connection/revoke'), ['user_id' => $alice->id, 'id' => $oauth['id']])->assertStatus(200);
        $this->assertNull(PersonalAccessToken::find($oauth['id']));
        $this->assertSame(0, McpOauthGrant::where('token_id', $oauth['id'])->count());
        $this->assertCount(1, $this->postJson($this->adminPath('/mcp/connection/fetch'), ['user_id' => $alice->id])->json('data'));

        // 断开以后调用记录还在，只是查不到令牌名字和软件名了
        $log = collect($this->postJson($this->adminPath('/mcp/log/fetch'), ['user_id' => $alice->id])->json('data'))->keyBy('token_id');
        $this->assertCount(2, $log);
        $this->assertNull($log[$oauth['id']]['token_name']);
        $this->assertNull($log[$oauth['id']]['app_name']);
        $this->assertSame('Claude', $log[$manual['id']]['token_name']);
    }

    public function test_non_admins_cannot_read_the_ai_records(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->user(), ['*']);
        $this->postJson($this->adminPath('/mcp/log/fetch'))->assertStatus(403);
        $this->postJson($this->adminPath('/mcp/connection/revoke'), ['user_id' => 1, 'id' => 1])->assertStatus(403);
    }
}
