<?php

namespace Tests\Feature;

use App\Models\CommissionLog;
use App\Models\Knowledge;
use App\Models\Notice;
use App\Models\Order;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\AuthService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 2026-10-26 19:22Z 生产上有用户拿「1 AND SLEEP(3)」探测公告、邀请、知识库接口，打出 18 个 500。
 * 都不是注入（参数走绑定 / 只做算术），但应该正常回 200 / 400 / 422，而不是 500。
 * 顺带对全部用户接口逐个参数塞畸形值（探测串、数组、溢出整数）扫了一遍，另外几处 500 也在这里回归。
 */
class MalformedUserParamsTest extends TestCase
{
    use RefreshDatabase;

    private const PROBE = '1 AND SLEEP(3)';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        app()->forgetScopedInstances();

        $this->user = $this->makeUser('inviter@example.test');
        Sanctum::actingAs($this->user);
    }

    private function makeUser(string $email, ?int $inviter = null): User
    {
        return User::create([
            'email' => $email,
            'password' => Hash::make('correct-horse-battery'),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'invite_user_id' => $inviter,
        ]);
    }

    private function query(string $path, array $params): string
    {
        return $path . '?' . http_build_query($params);
    }

    public function test_notice_fetch_treats_a_malformed_page_as_page_one(): void
    {
        foreach (range(1, 7) as $i) {
            Notice::create(['title' => "公告 {$i}", 'content' => '内容', 'show' => true]);
        }

        foreach ([self::PROBE, 'abc', '-1', '1.5', '99999999999999999999', ['1']] as $current) {
            $this->getJson($this->query('/api/v1/user/notice/fetch', ['current' => $current]))
                ->assertOk()
                ->assertJsonPath('total', 7)
                ->assertJsonCount(5, 'data');
        }

        // 正常翻页不受影响：每页 5 条，第 2 页剩 2 条，超出末页为空
        $this->getJson('/api/v1/user/notice/fetch?current=2')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/user/notice/fetch?current=3')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_invite_details_and_users_survive_malformed_page_params(): void
    {
        $invitee = $this->makeUser('invitee@example.test', $this->user->id);
        CommissionLog::create([
            'invite_user_id' => $this->user->id,
            'user_id' => $invitee->id,
            'trade_no' => 'T-' . Helper::guid(),
            'order_amount' => 1000,
            'get_amount' => 100,
        ]);

        foreach (['/api/v1/user/invite/details', '/api/v1/user/invite/users'] as $path) {
            foreach ([
                ['current' => self::PROBE],
                ['page_size' => self::PROBE],
                ['current' => self::PROBE, 'page_size' => self::PROBE],
                ['current' => ['1'], 'page_size' => ['a' => ['b' => '1']]],
                ['current' => '-3', 'page_size' => '100000'],
                ['current' => (string) PHP_INT_MAX],
            ] as $params) {
                $response = $this->getJson($this->query($path, $params))->assertOk()->assertJsonPath('total', 1);
                $expected = ($params['current'] ?? null) === (string) PHP_INT_MAX ? 0 : 1;
                $response->assertJsonCount($expected, 'data');
            }
        }
    }

    public function test_knowledge_fetch_rejects_missing_or_hidden_articles_with_400_and_probes_with_422(): void
    {
        $shown = Knowledge::create(['language' => 'zh-CN', 'category' => '入门', 'title' => '显示', 'body' => '正文', 'show' => true, 'sort' => 1]);
        $hidden = Knowledge::create(['language' => 'zh-CN', 'category' => '内部', 'title' => '隐藏', 'body' => '正文', 'show' => false, 'sort' => 2]);

        $this->getJson('/api/v1/user/knowledge/fetch?id=' . $shown->id)
            ->assertOk()
            ->assertJsonPath('data.title', '显示');
        // 原来这两种都是 500「Article does not exist」
        $this->getJson('/api/v1/user/knowledge/fetch?id=' . $hidden->id)->assertStatus(400);
        $this->getJson('/api/v1/user/knowledge/fetch?id=999999')->assertStatus(400);

        $this->getJson($this->query('/api/v1/user/knowledge/fetch', ['id' => self::PROBE]))->assertStatus(422);
        $this->getJson($this->query('/api/v1/user/knowledge/fetch', ['language' => self::PROBE]))->assertStatus(422);
        $this->getJson($this->query('/api/v1/user/knowledge/fetch', ['language' => 'zh-CN', 'keyword' => self::PROBE]))
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_knowledge_get_category_lists_visible_categories_instead_of_500(): void
    {
        Knowledge::create(['language' => 'zh-CN', 'category' => '入门', 'title' => 'a', 'body' => 'x', 'show' => true, 'sort' => 1]);
        Knowledge::create(['language' => 'zh-CN', 'category' => '入门', 'title' => 'b', 'body' => 'x', 'show' => true, 'sort' => 2]);
        Knowledge::create(['language' => 'en-US', 'category' => 'Guides', 'title' => 'c', 'body' => 'x', 'show' => true, 'sort' => 3]);
        Knowledge::create(['language' => 'zh-CN', 'category' => '内部', 'title' => 'd', 'body' => 'x', 'show' => false, 'sort' => 4]);

        $this->getJson('/api/v1/user/knowledge/getCategory')
            ->assertOk()
            ->assertJsonPath('data', ['入门', 'Guides']);
        $this->getJson('/api/v1/user/knowledge/getCategory?language=zh-CN')
            ->assertOk()
            ->assertJsonPath('data', ['入门']);
        $this->getJson($this->query('/api/v1/user/knowledge/getCategory', ['language' => self::PROBE]))->assertStatus(422);
    }

    public function test_page_numbers_past_the_cap_are_rejected_before_they_overflow(): void
    {
        $this->getJson('/api/v1/user/balance/log?page=' . PHP_INT_MAX)->assertStatus(422);
        $this->getJson('/api/v1/user/gift-card/history?page=' . PHP_INT_MAX)->assertStatus(422);

        $this->getJson('/api/v1/user/balance/log?page=2')->assertOk();
        $this->getJson('/api/v1/user/gift-card/history?page=2')->assertOk();
    }

    public function test_remove_active_session_validates_session_id(): void
    {
        // 原来缺参在 removeSession(string) 处 TypeError → 500
        $this->postJson('/api/v1/user/removeActiveSession', [])->assertStatus(422);
        $this->postJson('/api/v1/user/removeActiveSession', ['session_id' => ['1']])->assertStatus(422);
        $this->postJson('/api/v1/user/removeActiveSession', ['session_id' => self::PROBE])->assertStatus(422);

        (new AuthService($this->user))->generateAuthData();
        $tokenId = $this->user->tokens()->value('id');
        $this->postJson('/api/v1/user/removeActiveSession', ['session_id' => $tokenId])->assertOk();
        $this->assertSame(0, $this->user->tokens()->count());
    }

    public function test_quick_login_url_ignores_a_non_string_redirect(): void
    {
        $this->postJson('/api/v1/user/getQuickLoginUrl', ['redirect' => ['x']])
            ->assertOk()
            ->assertJsonPath('data', fn ($url) => is_string($url) && str_contains($url, 'redirect=dashboard'));

        $session = (new AuthService($this->user))->generateAuthData();
        app('auth')->forgetGuards();
        $this->postJson('/api/v1/passport/auth/getQuickLoginUrl', ['auth_data' => $session['auth_data'], 'redirect' => ['x']])
            ->assertOk()
            ->assertJsonPath('data', fn ($url) => is_string($url) && str_contains($url, 'redirect=dashboard'));
    }

    public function test_order_and_coupon_endpoints_reject_array_params_without_500(): void
    {
        $group = new ServerGroup();
        $group->name = '基础';
        $group->save();
        $plan = Plan::create([
            'name' => '月付', 'capacity_limit' => null, 'group_id' => $group->id, 'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => null, 'reset_traffic_method' => 1,
            'prices' => ['monthly' => 20],
        ]);
        $order = Order::create([
            'user_id' => $this->user->id, 'plan_id' => $plan->id, 'period' => 'monthly',
            'trade_no' => 'MALFORMED0000001', 'total_amount' => 2000, 'balance_amount' => 0,
            'type' => Order::TYPE_NEW_PURCHASE, 'status' => Order::STATUS_PENDING,
        ]);

        // 原来 (string) 强转数组报「Array to string conversion」→ 500
        $this->getJson($this->query('/api/v1/user/order/check', ['trade_no' => ['x']]))->assertStatus(400);
        $this->postJson('/api/v1/user/order/cancel', ['trade_no' => ['x']])->assertStatus(422);
        $this->postJson('/api/v1/user/order/save', ['plan_id' => [$plan->id], 'period' => 'month_price'])->assertStatus(422);
        $this->postJson('/api/v1/user/order/save', ['plan_id' => self::PROBE, 'period' => 'month_price'])->assertStatus(422);

        // 正常的查单 / 取消不受影响
        $this->getJson('/api/v1/user/order/check?trade_no=' . $order->trade_no)
            ->assertOk()
            ->assertJsonPath('data', Order::STATUS_PENDING);
        $this->postJson('/api/v1/user/coupon/check', ['code' => 'NOSUCHCOUPON', 'plan_id' => $plan->id, 'period' => ['x']])->assertStatus(422);

        $this->postJson('/api/v1/user/order/cancel', ['trade_no' => $order->trade_no])->assertOk();
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
    }

    public function test_gift_card_check_requires_a_string_code(): void
    {
        // 原来 GiftCardCheckRequest 没有规则，new GiftCardService(string) 收到 null / 数组 → TypeError 500
        $this->postJson('/api/v1/user/gift-card/check', [])->assertStatus(422);
        $this->postJson('/api/v1/user/gift-card/check', ['code' => ['x']])->assertStatus(422);
        $this->postJson('/api/v1/user/gift-card/check', ['code' => 'NOSUCHCODE1'])->assertStatus(400);
    }
}
