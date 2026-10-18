<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\ConfigSave;
use App\Models\CommissionLog;
use App\Models\Coupon;
use App\Models\InviteCode;
use App\Models\Order;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\Commission\InviteFunnel;
use App\Services\OrderService;
use App\Support\Setting;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 推广增长：分渠道漏斗（C2）、被邀请人首单优惠（B1）、阶梯比例（B2）、
 * 自动周期返佣（B3）、佣金入账通知（C1）、月度榜单（B5）。
 */
class InviteGrowthTest extends TestCase
{
    use RefreshDatabase;

    private array $settings = [];
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        app()->forgetScopedInstances();
        Queue::fake();
        Http::preventStrayRequests();

        $stub = new class extends Setting {
            public array $store = [];
            public function __construct()
            {
            }
            public function get(string $key, mixed $default = null): mixed
            {
                return $this->store[strtolower($key)] ?? $default;
            }
            public function save(array $settings): bool
            {
                foreach ($settings as $k => $v) {
                    $this->store[strtolower($k)] = $v;
                }
                return true;
            }
            public function set(string $key, mixed $value = null): bool
            {
                return $this->save([$key => $value]);
            }
        };
        $stub->store = &$this->settings;
        $this->app->instance(Setting::class, $stub);
        $this->settings = [
            'app_name' => 'Sufe',
            'app_url' => 'https://www.example.test',
            'billing_receipt_enable' => 0,
        ];

        $group = new ServerGroup();
        $group->name = '基础';
        $group->save();
        $this->plan = Plan::create([
            'name' => '家宽', 'capacity_limit' => null, 'group_id' => $group->id, 'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => null, 'reset_traffic_method' => 1,
            'prices' => ['monthly' => 100, 'quarterly' => 270],
        ]);
    }

    private function makeUser(array $attrs = []): User
    {
        return User::create($attrs + [
            'email' => 'ig-' . Str::random(8) . '@example.test',
            'password' => 'x',
            'uuid' => Str::uuid()->toString(),
            'token' => bin2hex(random_bytes(16)),
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'expired_at' => 0,
            'plan_id' => null,
            'group_id' => null,
        ]);
    }

    private function makeCode(User $owner, string $code): InviteCode
    {
        $row = new InviteCode();
        $row->user_id = $owner->id;
        $row->code = $code;
        $row->pv = 0;
        $row->save();
        return $row;
    }

    /** 已完成、实付 $amount 分、归属 $inviter 的订单 */
    private function paidOrder(User $buyer, User $inviter, int $amount = 10000, int $commissionStatus = 2, int $commission = 0): Order
    {
        return Order::create([
            'user_id' => $buyer->id, 'plan_id' => $this->plan->id, 'period' => 'month_price',
            'trade_no' => Helper::generateOrderNo(), 'total_amount' => $amount, 'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_COMPLETED, 'invite_user_id' => $inviter->id,
            'commission_balance' => $commission, 'commission_status' => $commissionStatus, 'paid_at' => time(),
        ]);
    }

    /** 当前订阅有效的被邀请人：有套餐、未到期 */
    private function activeInvitee(User $inviter, ?string $code = null): User
    {
        return $this->makeUser([
            'invite_user_id' => $inviter->id, 'invite_code' => $code,
            'plan_id' => $this->plan->id, 'expired_at' => time() + 86400 * 20,
        ]);
    }

    // ---------------------------------------------------------------- C2 分渠道漏斗

    public function test_register_stores_the_invite_code_that_was_used(): void
    {
        $inviter = $this->makeUser();
        $this->makeCode($inviter, 'tg-group');

        $this->postJson('/api/v1/passport/auth/register', [
            'email' => 'friend@example.test', 'password' => 'secret-pass', 'invite_code' => 'tg-group',
        ])->assertOk();
        $friend = User::where('email', 'friend@example.test')->firstOrFail();
        $this->assertSame($inviter->id, (int) $friend->invite_user_id);
        $this->assertSame('tg-group', $friend->invite_code);

        // 无效码（非强制邀请）被静默忽略：不记邀请人，也不记码
        $this->postJson('/api/v1/passport/auth/register', [
            'email' => 'stranger@example.test', 'password' => 'secret-pass', 'invite_code' => 'no-such-code',
        ])->assertOk();
        $stranger = User::where('email', 'stranger@example.test')->firstOrFail();
        $this->assertNull($stranger->invite_user_id);
        $this->assertNull($stranger->invite_code);
    }

    public function test_invite_fetch_returns_a_funnel_per_code_plus_unknown_and_daily_series(): void
    {
        $inviter = $this->makeUser();
        $a = $this->makeCode($inviter, 'code-a');
        InviteCode::where('id', $a->id)->update(['pv' => 128]);
        $this->makeCode($inviter, 'code-b');

        // code-a：2 注册 1 付费；code-b：1 注册 0 付费；未知来源（老用户，没记码）：1 注册 1 付费
        $a1 = $this->makeUser(['invite_user_id' => $inviter->id, 'invite_code' => 'code-a']);
        $this->makeUser(['invite_user_id' => $inviter->id, 'invite_code' => 'code-a']);
        $this->makeUser(['invite_user_id' => $inviter->id, 'invite_code' => 'code-b']);
        $old = $this->makeUser(['invite_user_id' => $inviter->id, 'invite_code' => null]);
        $this->paidOrder($a1, $inviter, 10000);
        $this->paidOrder($a1, $inviter, 10000);           // 同一人第二单，付费人数仍是 1
        $this->paidOrder($old, $inviter, 5000);
        $this->paidOrder($this->makeUser(['invite_user_id' => $inviter->id, 'invite_code' => 'code-b']), $inviter, 0); // 0 元单不算付费
        CommissionLog::create(['invite_user_id' => $inviter->id, 'user_id' => $a1->id, 'trade_no' => 'T1', 'order_amount' => 10000, 'get_amount' => 1000]);
        CommissionLog::create(['invite_user_id' => $inviter->id, 'user_id' => $old->id, 'trade_no' => 'T2', 'order_amount' => 5000, 'get_amount' => 500]);
        InviteFunnel::forget($inviter->id);

        Sanctum::actingAs($inviter);
        $resp = $this->getJson('/api/v1/user/invite/fetch')->assertOk();
        $funnel = $resp->json('data.funnel');
        $this->assertSame(
            [['code' => 'code-a', 'pv' => 128, 'registered' => 2, 'paid' => 1, 'commission_total' => 1000],
             ['code' => 'code-b', 'pv' => 0, 'registered' => 2, 'paid' => 0, 'commission_total' => 0]],
            $funnel['codes']
        );
        $this->assertSame(['registered' => 1, 'paid' => 1, 'commission_total' => 500], $funnel['unknown']);
        $this->assertCount(30, $funnel['daily']);
        $today = $funnel['daily'][29];
        $this->assertSame(date('Y-m-d'), $today['date']);
        $this->assertSame(5, $today['registered']);
        $this->assertSame(2, $today['paid']);
        $this->assertSame(1500, $today['commission']);
        // 老字段原样保留
        $this->assertSame(5, $resp->json('data.stat.0'));
        $this->assertSame(2, $resp->json('data.paid_invitee_count'));
    }
    // ---------------------------------------------------------------- B1 被邀请人首单优惠

    private function coupon(string $code, int $type, int $value, ?int $limitUse = null): Coupon
    {
        return Coupon::create([
            'code' => $code, 'name' => $code, 'type' => $type, 'value' => $value, 'show' => true,
            'limit_use' => $limitUse, 'limit_use_with_user' => null, 'limit_plan_ids' => null, 'limit_period' => null,
            'started_at' => time() - 60, 'ended_at' => time() + 86400,
        ]);
    }

    public function test_invitee_gets_the_first_order_discount_once(): void
    {
        $this->settings['invite_first_order_discount'] = 12;   // 88 折
        $inviter = $this->makeUser();
        $friend = $this->makeUser(['invite_user_id' => $inviter->id]);

        $order = OrderService::createFromRequest($friend, $this->plan, 'monthly');
        $this->assertSame(8800, (int) $order->total_amount);
        $this->assertSame(1200, (int) $order->discount_amount);
        $this->assertSame(1200, (int) $order->invite_discount_amount);
        $this->assertNull($order->coupon_id);
        // 佣金按折后实付算（邀请人默认 10%）
        $this->assertSame(880, (int) $order->commission_balance);

        // 第一单完成后，第二单不再享受
        Order::where('id', $order->id)->update(['status' => Order::STATUS_COMPLETED]);
        $second = OrderService::createFromRequest($friend->fresh(), $this->plan, 'monthly');
        $this->assertSame(10000, (int) $second->total_amount);
        $this->assertSame(0, (int) $second->invite_discount_amount);

        // 没有邀请人 / 自邀 / 开关为 0 都不打折
        $this->assertSame(10000, (int) OrderService::createFromRequest($this->makeUser(), $this->plan, 'monthly')->total_amount);
        $self = $this->makeUser();
        User::where('id', $self->id)->update(['invite_user_id' => $self->id]);
        $this->assertSame(10000, (int) OrderService::createFromRequest($self->fresh(), $this->plan, 'monthly')->total_amount);
        $this->settings['invite_first_order_discount'] = 0;
        $this->assertSame(10000, (int) OrderService::createFromRequest($this->makeUser(['invite_user_id' => $inviter->id]), $this->plan, 'monthly')->total_amount);
    }

    public function test_first_order_discount_and_coupon_do_not_stack_the_bigger_one_wins(): void
    {
        $this->settings['invite_first_order_discount'] = 12;
        $inviter = $this->makeUser();

        // 券更大（20%）：保留券，邀请折扣不记
        $big = $this->coupon('BIG20', 2, 20);
        $order = OrderService::createFromRequest($this->makeUser(['invite_user_id' => $inviter->id]), $this->plan, 'monthly', 'BIG20');
        $this->assertSame(8000, (int) $order->total_amount);
        $this->assertSame($big->id, (int) $order->coupon_id);
        $this->assertSame(0, (int) $order->invite_discount_amount);

        // 券更小（减 5 元，限量 3 张）：改用邀请折扣，券的占用退回
        $small = $this->coupon('MINUS5', 1, 500, 3);
        $order = OrderService::createFromRequest($this->makeUser(['invite_user_id' => $inviter->id]), $this->plan, 'monthly', 'MINUS5');
        $this->assertSame(8800, (int) $order->total_amount);
        $this->assertNull($order->coupon_id);
        $this->assertSame(1200, (int) $order->invite_discount_amount);
        $this->assertSame(3, (int) $small->fresh()->limit_use);
    }

    public function test_info_and_guest_config_expose_the_first_order_discount(): void
    {
        $this->settings['invite_first_order_discount'] = 12;
        $inviter = $this->makeUser();
        $friend = $this->makeUser(['invite_user_id' => $inviter->id]);

        $this->getJson('/api/v1/guest/comm/config')->assertOk()->assertJsonPath('data.invite_first_order_discount', 12);

        Sanctum::actingAs($friend);
        $this->getJson('/api/v1/user/info')->assertOk()
            ->assertJsonPath('data.invite_first_order_discount', 12)
            ->assertJsonPath('data.invite_first_order_discount_eligible', true);

        $this->paidOrder($friend, $inviter, 8800);
        $this->getJson('/api/v1/user/info')->assertOk()->assertJsonPath('data.invite_first_order_discount_eligible', false);

        Sanctum::actingAs($inviter);
        $this->getJson('/api/v1/user/info')->assertOk()->assertJsonPath('data.invite_first_order_discount_eligible', false);

        $this->settings['invite_first_order_discount'] = 0;
        $this->getJson('/api/v1/guest/comm/config')->assertOk()->assertJsonPath('data.invite_first_order_discount', 0);
    }

    // ---------------------------------------------------------------- B2 阶梯比例

    /** 让 $inviter 多 $n 位付费被邀请人（各一单 100 元，已完成） */
    private function addPaidInvitees(User $inviter, int $n, bool $active = false): void
    {
        for ($i = 0; $i < $n; $i++) {
            $buyer = $active ? $this->activeInvitee($inviter) : $this->makeUser(['invite_user_id' => $inviter->id]);
            $this->paidOrder($buyer, $inviter, 10000);
        }
    }

    private function newFriendOrder(User $inviter): Order
    {
        return OrderService::createFromRequest($this->makeUser(['invite_user_id' => $inviter->id]), $this->plan, 'monthly');
    }

    public function test_tier_rate_follows_the_paid_invitee_count_and_user_rate_wins(): void
    {
        $this->settings['commission_tier_enable'] = 1;
        $inviter = $this->makeUser();

        // 0 位付费：基础 10%
        $this->assertSame(1000, (int) $this->newFriendOrder($inviter)->commission_balance);
        // 10 位付费：15%
        $this->addPaidInvitees($inviter, 10);
        $this->assertSame(1500, (int) $this->newFriendOrder($inviter)->commission_balance);
        // 30 位付费：20%
        $this->addPaidInvitees($inviter, 20);
        $this->assertSame(2000, (int) $this->newFriendOrder($inviter)->commission_balance);
        // 自定义档位：只认后台填的数
        $this->settings['commission_tier_3_min'] = 25;
        $this->settings['commission_tier_3_rate'] = 33;
        $this->assertSame(3300, (int) $this->newFriendOrder($inviter)->commission_balance);
        // 管理员给该用户单设了比例：阶梯不再参与
        User::where('id', $inviter->id)->update(['commission_rate' => 5]);
        $this->assertSame(500, (int) $this->newFriendOrder($inviter->fresh())->commission_balance);
        // 开关关着：回到站点默认
        User::where('id', $inviter->id)->update(['commission_rate' => null]);
        $this->settings['commission_tier_enable'] = 0;
        $this->assertSame(1000, (int) $this->newFriendOrder($inviter->fresh())->commission_balance);
        $this->settings['invite_commission'] = 8;
        $this->assertSame(800, (int) $this->newFriendOrder($inviter->fresh())->commission_balance);
    }

    public function test_invite_fetch_exposes_the_effective_commission_policy(): void
    {
        $this->settings['commission_tier_enable'] = 1;
        $this->settings['invite_first_order_discount'] = 12;
        $inviter = $this->makeUser();
        $this->addPaidInvitees($inviter, 10);
        Sanctum::actingAs($inviter);

        $resp = $this->getJson('/api/v1/user/invite/fetch')->assertOk();
        $this->assertSame([
            'rate' => 15, 'rate_source' => 'tier', 'mode' => 'first_order', 'mode_source' => 'default',
            'tiers' => [
                'enable' => true, 'base_rate' => 10,
                'levels' => [['min' => 10, 'rate' => 15], ['min' => 30, 'rate' => 20]],
                'next' => ['min' => 30, 'rate' => 20],
            ],
            'period_auto' => ['enable' => false, 'min_invitees' => 50, 'active_paid_invitee_count' => 0],
        ], $resp->json('data.commission'));
        $this->assertSame(15, $resp->json('data.stat.3'));
        $this->assertSame(12, $resp->json('data.first_order_discount'));

        // 到顶：next = null
        $this->addPaidInvitees($inviter, 20);
        $resp = $this->getJson('/api/v1/user/invite/fetch')->assertOk();
        $this->assertSame(20, $resp->json('data.commission.rate'));
        $this->assertNull($resp->json('data.commission.tiers.next'));

        // 用户单设比例：rate_source=user，不再提示下一档
        User::where('id', $inviter->id)->update(['commission_rate' => 5]);
        $resp = $this->getJson('/api/v1/user/invite/fetch')->assertOk();
        $this->assertSame('user', $resp->json('data.commission.rate_source'));
        $this->assertSame(5, $resp->json('data.commission.rate'));
        $this->assertNull($resp->json('data.commission.tiers.next'));

        // 关着：levels 为空、按站点默认
        User::where('id', $inviter->id)->update(['commission_rate' => null]);
        $this->settings['commission_tier_enable'] = 0;
        $resp = $this->getJson('/api/v1/user/invite/fetch')->assertOk();
        $this->assertSame(['enable' => false, 'base_rate' => 10, 'levels' => [], 'next' => null], $resp->json('data.commission.tiers'));
        $this->assertSame('default', $resp->json('data.commission.rate_source'));
        $this->assertSame(10, $resp->json('data.commission.rate'));
    }

    // ---------------------------------------------------------------- B3 自动周期返佣

    /** $buyer 的一张续费单会记多少佣金（下完随即取消，避免「有未付款订单」拦下一张） */
    private function renewalCommission(User $buyer): int
    {
        $order = OrderService::createFromRequest($buyer->fresh(), $this->plan, 'monthly');
        $this->assertSame(Order::TYPE_RENEWAL, (int) $order->type);
        Order::where('id', $order->id)->update(['status' => Order::STATUS_CANCELLED]);
        return (int) $order->commission_balance;
    }

    public function test_period_commission_switches_on_at_the_threshold_and_back_off_below(): void
    {
        $this->settings['commission_period_auto_enable'] = 1;
        $this->settings['commission_period_auto_min_invitees'] = 3;
        $inviter = $this->makeUser();
        $a = $this->activeInvitee($inviter);
        $this->paidOrder($a, $inviter);
        $b = $this->activeInvitee($inviter);
        $this->paidOrder($b, $inviter);

        // 2 位有效付费：仍是首单返佣，老用户续费不返
        $this->assertSame(0, $this->renewalCommission($a));
        $this->assertSame($inviter->id, (int) Order::where('user_id', $a->id)->latest('id')->value('invite_user_id'));   // 邀请关系照记

        // 第 3 位到位：自动升级为周期返佣，续费也返
        $c = $this->activeInvitee($inviter);
        $this->paidOrder($c, $inviter);
        $this->assertSame(1000, $this->renewalCommission($a));

        // 一位到期 → 有效人数掉回 2：自动恢复首单返佣
        User::where('id', $c->id)->update(['expired_at' => time() - 10]);
        $this->assertSame(0, $this->renewalCommission($a));

        // 付过费但没套餐的不算「有效」
        $d = $this->makeUser(['invite_user_id' => $inviter->id]);
        $this->paidOrder($d, $inviter);
        $this->assertSame(0, $this->renewalCommission($a));

        // 管理员单设「周期」：门槛不参与
        User::where('id', $inviter->id)->update(['commission_type' => User::COMMISSION_TYPE_PERIOD]);
        $this->assertSame(1000, $this->renewalCommission($a));
        // 管理员单设「一次性」：即使人数够也只返首单
        User::where('id', $c->id)->update(['expired_at' => time() + 86400]);
        User::where('id', $inviter->id)->update(['commission_type' => User::COMMISSION_TYPE_ONETIME]);
        $this->assertSame(0, $this->renewalCommission($a));
        // 开关关着：人数够也不自动开
        User::where('id', $inviter->id)->update(['commission_type' => User::COMMISSION_TYPE_SYSTEM]);
        $this->settings['commission_period_auto_enable'] = 0;
        $this->assertSame(0, $this->renewalCommission($a));
    }

    public function test_invite_fetch_shows_the_period_auto_threshold_and_mode(): void
    {
        $this->settings['commission_period_auto_enable'] = 1;
        $this->settings['commission_period_auto_min_invitees'] = 2;
        $inviter = $this->makeUser();
        $this->addPaidInvitees($inviter, 1, true);
        Sanctum::actingAs($inviter);

        $resp = $this->getJson('/api/v1/user/invite/fetch')->assertOk();
        $this->assertSame('first_order', $resp->json('data.commission.mode'));
        $this->assertSame('default', $resp->json('data.commission.mode_source'));
        $this->assertSame(['enable' => true, 'min_invitees' => 2, 'active_paid_invitee_count' => 1], $resp->json('data.commission.period_auto'));

        $this->addPaidInvitees($inviter, 1, true);
        $resp = $this->getJson('/api/v1/user/invite/fetch')->assertOk();
        $this->assertSame('period', $resp->json('data.commission.mode'));
        $this->assertSame('auto', $resp->json('data.commission.mode_source'));
        $this->assertSame(2, $resp->json('data.commission.period_auto.active_paid_invitee_count'));

        User::where('id', $inviter->id)->update(['commission_type' => User::COMMISSION_TYPE_ONETIME]);
        $resp = $this->getJson('/api/v1/user/invite/fetch')->assertOk();
        $this->assertSame(['first_order', 'user'], [$resp->json('data.commission.mode'), $resp->json('data.commission.mode_source')]);
    }

    public function test_admin_config_rules_accept_the_invite_growth_settings(): void
    {
        $data = [
            'invite_first_order_discount' => 12,
            'commission_tier_enable' => 1, 'commission_tier_2_min' => 10, 'commission_tier_2_rate' => 15,
            'commission_tier_3_min' => 30, 'commission_tier_3_rate' => 20,
            'commission_period_auto_enable' => 1, 'commission_period_auto_min_invitees' => 50,
            'commission_notify_enable' => 1, 'commission_leaderboard_enable' => 1,
        ];
        $this->assertTrue(Validator::make($data, ConfigSave::RULES)->passes());
        $this->assertTrue(Validator::make(['invite_first_order_discount' => 101], ConfigSave::RULES)->fails());
        $this->assertTrue(Validator::make(['commission_tier_2_rate' => -1], ConfigSave::RULES)->fails());
        $this->assertTrue(Validator::make(['commission_period_auto_min_invitees' => 0], ConfigSave::RULES)->fails());
    }
}
