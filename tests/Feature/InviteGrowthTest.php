<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\ConfigSave;
use App\Jobs\SendEmailJob;
use App\Jobs\SendTelegramJob;
use App\Models\CommissionLog;
use App\Models\Coupon;
use App\Models\InviteCode;
use App\Models\Order;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Models\UserNotificationPref;
use App\Services\Commission\CommissionDigest;
use App\Services\Commission\CommissionNotifier;
use App\Services\Commission\InviteFunnel;
use App\Services\EmailVerification;
use App\Services\Commission\Leaderboard;
use App\Services\Commission\PeriodCommission;
use App\Services\Notification\NotificationPreference;
use App\Services\OrderService;
use App\Support\Setting;
use App\Utils\Helper;
use Carbon\Carbon;
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
 * 周期返佣申请制（B3）、佣金入账通知（C1，含每日 / 每周汇总）、月度榜单（B5）。
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
        // 注册走 MX 检查：CI 的 DNS 能解析出 example.test 没有收信服务器（400），本地 DNS 失败则放行，统一桩掉
        EmailVerification::$dnsResolver = fn (string $domain) => true;

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

    protected function tearDown(): void
    {
        EmailVerification::$dnsResolver = null;
        parent::tearDown();
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
            'email' => 'friend@invitee.test', 'password' => 'secret-pass', 'invite_code' => 'tg-group',
        ])->assertOk();
        $friend = User::where('email', 'friend@invitee.test')->firstOrFail();
        $this->assertSame($inviter->id, (int) $friend->invite_user_id);
        $this->assertSame('tg-group', $friend->invite_code);

        // 无效码（非强制邀请）被静默忽略：不记邀请人，也不记码
        $this->postJson('/api/v1/passport/auth/register', [
            'email' => 'stranger@invitee.test', 'password' => 'secret-pass', 'invite_code' => 'no-such-code',
        ])->assertOk();
        $stranger = User::where('email', 'stranger@invitee.test')->firstOrFail();
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
            'period_auto' => [
                'enable' => false, 'min_invitees' => 50, 'active_paid_invitee_count' => 0,
                'application' => ['status' => 'none', 'reason' => null, 'applied_at' => null, 'decided_at' => null, 'revoked_at' => null, 'can_apply' => false],
            ],
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

    private function adminPath(string $path): string
    {
        return '/api/v2/' . admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))) . $path;
    }

    /** 已推送的邮件任务参数（按推送顺序） */
    private function pushedMails(): array
    {
        return Queue::pushed(SendEmailJob::class)->map(fn (SendEmailJob $job) => (fn () => $this->params)->call($job))->values()->all();
    }

    /**
     * 周期返佣改为申请制：达标后申请 → 站长通过才开通；有效付费人数低于门槛自动取消资格，再达标要重新申请。
     */
    public function test_period_commission_needs_an_approved_application_and_is_revoked_below_the_threshold(): void
    {
        $this->settings['commission_period_auto_enable'] = 1;
        $this->settings['commission_period_auto_min_invitees'] = 3;
        $inviter = $this->makeUser();
        $admin = $this->makeUser(['is_admin' => 1]);
        $a = $this->activeInvitee($inviter);
        $this->paidOrder($a, $inviter);
        $b = $this->activeInvitee($inviter);
        $this->paidOrder($b, $inviter);
        $c = $this->activeInvitee($inviter);
        $this->paidOrder($c, $inviter);

        // 达标但没申请：仍是首单返佣（上一版这里会自动升级）
        $this->assertSame(0, $this->renewalCommission($a));
        Sanctum::actingAs($inviter);
        $this->assertTrue($this->getJson('/api/v1/user/invite/fetch')->json('data.commission.period_auto.application.can_apply'));

        // 申请 → 待审核，仍是首单返佣；不能重复申请
        $resp = $this->postJson('/api/v1/user/invite/period/apply')->assertOk();
        $this->assertSame('pending', $resp->json('data.period_auto.application.status'));
        $this->assertFalse($resp->json('data.period_auto.application.can_apply'));
        $this->postJson('/api/v1/user/invite/period/apply')->assertStatus(400);
        $this->assertSame(0, $this->renewalCommission($a));

        // 站长在后台看到申请（含当前人数）并通过 → 周期返佣，续费也返；用户收到通知，不带编号
        Sanctum::actingAs($admin, ['admin']);
        $list = $this->postJson($this->adminPath('/commission-period/fetch'))->assertOk();
        $this->assertSame(1, $list->json('total'));
        $this->assertSame([0, 3, 3, 3], [
            $list->json('data.0.status'), $list->json('data.0.active_count'),
            $list->json('data.0.current_active_count'), $list->json('data.0.min_invitees'),
        ]);
        $this->assertSame(1, $this->getJson($this->adminPath('/commission-period/stats'))->json('data.pending_count'));
        $id = $list->json('data.0.id');
        $this->postJson($this->adminPath('/commission-period/approve'), ['id' => $id])->assertOk()->assertJsonPath('data.status', 1);
        $this->postJson($this->adminPath('/commission-period/approve'), ['id' => $id])->assertStatus(400);
        $this->assertSame(1000, $this->renewalCommission($a));
        $mails = $this->pushedMails();
        $this->assertCount(1, $mails);
        $this->assertSame([$inviter->email, 'notify'], [$mails[0]['email'], $mails[0]['template_name']]);
        $this->assertStringContainsString('周期返佣已开通', $mails[0]['subject']);
        $this->assertDoesNotMatchRegularExpression('/#\d+/', $mails[0]['subject'] . $mails[0]['template_value']['content']);

        Sanctum::actingAs($inviter);
        $resp = $this->getJson('/api/v1/user/invite/fetch')->assertOk();
        $this->assertSame(['period', 'applied'], [$resp->json('data.commission.mode'), $resp->json('data.commission.mode_source')]);
        $this->assertSame('approved', $resp->json('data.commission.period_auto.application.status'));

        // 一位到期 → 有效人数 2 < 3：当场取消资格、恢复首单返佣，并通知一次
        User::where('id', $c->id)->update(['expired_at' => time() - 10]);
        $this->assertSame(0, $this->renewalCommission($a));
        $this->assertSame(0, $this->renewalCommission($a));
        $row = \App\Models\CommissionPeriodApplication::findOrFail($id);
        $this->assertSame([3, 2], [(int) $row->status, (int) $row->revoked_count]);
        $mails = $this->pushedMails();
        $this->assertCount(2, $mails, '取消资格只通知一次');
        $this->assertStringContainsString('周期返佣资格已取消', $mails[1]['subject']);

        // 人数回到 3：不会自动恢复，要重新申请
        User::where('id', $c->id)->update(['expired_at' => time() + 86400]);
        $this->assertSame(0, $this->renewalCommission($a));
        $resp = $this->getJson('/api/v1/user/invite/fetch')->assertOk();
        $this->assertSame('revoked', $resp->json('data.commission.period_auto.application.status'));
        $this->assertTrue($resp->json('data.commission.period_auto.application.can_apply'));

        // 管理员单设返佣方式时以单设为准，申请制不参与
        User::where('id', $inviter->id)->update(['commission_type' => User::COMMISSION_TYPE_PERIOD]);
        $this->assertSame(1000, $this->renewalCommission($a));
        $this->postJson('/api/v1/user/invite/period/apply')->assertStatus(400);
        User::where('id', $inviter->id)->update(['commission_type' => User::COMMISSION_TYPE_SYSTEM]);

        // 重新申请并通过后，后台关掉开关：不再按周期返佣（资格保留，重新打开且仍达标就恢复）
        $this->postJson('/api/v1/user/invite/period/apply')->assertOk();
        $next = \App\Models\CommissionPeriodApplication::where('user_id', $inviter->id)->latest('id')->firstOrFail();
        (new PeriodCommission())->approve($next->id, $admin);
        $this->assertSame(1000, $this->renewalCommission($a));
        $this->settings['commission_period_auto_enable'] = 0;
        $this->assertSame(0, $this->renewalCommission($a));
        $this->settings['commission_period_auto_enable'] = 1;
        $this->assertSame(1000, $this->renewalCommission($a));
    }

    public function test_period_application_rules_reject_and_reapply(): void
    {
        $this->settings['commission_period_auto_enable'] = 1;
        $this->settings['commission_period_auto_min_invitees'] = 2;
        $inviter = $this->makeUser();
        $admin = $this->makeUser(['is_admin' => 1]);
        $this->addPaidInvitees($inviter, 1, true);
        Sanctum::actingAs($inviter);

        // 不够人数：不能申请，提示当前人数
        $resp = $this->postJson('/api/v1/user/invite/period/apply')->assertStatus(400);
        $this->assertStringContainsString('2', (string) $resp->json('message'));
        $this->assertStringContainsString('1', (string) $resp->json('message'));
        $this->assertFalse($this->getJson('/api/v1/user/invite/fetch')->json('data.commission.period_auto.application.can_apply'));

        // 开关关着：不能申请
        $this->addPaidInvitees($inviter, 1, true);
        $this->settings['commission_period_auto_enable'] = 0;
        $this->postJson('/api/v1/user/invite/period/apply')->assertStatus(400);
        $this->settings['commission_period_auto_enable'] = 1;

        // 申请 → 审核期间人数掉下去：站长不能通过，只能驳回（驳回必须写原因）
        $this->postJson('/api/v1/user/invite/period/apply')->assertOk();
        $application = \App\Models\CommissionPeriodApplication::firstOrFail();
        User::where('invite_user_id', $inviter->id)->limit(1)->update(['expired_at' => time() - 10]);
        Sanctum::actingAs($admin, ['admin']);
        $this->postJson($this->adminPath('/commission-period/approve'), ['id' => $application->id])->assertStatus(400);
        $this->postJson($this->adminPath('/commission-period/reject'), ['id' => $application->id])->assertStatus(422);
        $this->postJson($this->adminPath('/commission-period/reject'), ['id' => $application->id, 'reason' => '近期新增用户集中在同一网段'])
            ->assertOk()->assertJsonPath('data.status', 2);
        $mail = $this->pushedMails()[0];
        $this->assertStringContainsString('近期新增用户集中在同一网段', $mail['template_value']['content']);

        // 用户看到驳回原因；人数回来后可以重新申请
        User::where('invite_user_id', $inviter->id)->update(['expired_at' => time() + 86400]);
        Sanctum::actingAs($inviter);
        $state = $this->getJson('/api/v1/user/invite/fetch')->json('data.commission.period_auto.application');
        $this->assertSame(['rejected', '近期新增用户集中在同一网段', true], [$state['status'], $state['reason'], $state['can_apply']]);
        $this->postJson('/api/v1/user/invite/period/apply')->assertOk();
        $this->assertSame(2, \App\Models\CommissionPeriodApplication::where('user_id', $inviter->id)->count(), '每次申请一行，保留历史');

        // 普通用户调不到后台接口
        $this->postJson($this->adminPath('/commission-period/fetch'))->assertStatus(403);
    }

    public function test_daily_sweep_revokes_approved_users_below_the_threshold_once(): void
    {
        $this->settings['commission_period_auto_enable'] = 1;
        $this->settings['commission_period_auto_min_invitees'] = 2;
        $admin = $this->makeUser(['is_admin' => 1]);
        $keep = $this->makeUser();
        $this->addPaidInvitees($keep, 2, true);
        $drop = $this->makeUser();
        $this->addPaidInvitees($drop, 2, true);
        foreach ([$keep, $drop] as $user) {
            (new PeriodCommission())->apply($user);
            (new PeriodCommission())->approve(\App\Models\CommissionPeriodApplication::where('user_id', $user->id)->value('id'), $admin);
        }
        User::where('invite_user_id', $drop->id)->limit(1)->update(['expired_at' => time() - 10]);

        $this->artisan('commission:period-sweep')->assertSuccessful();
        $this->artisan('commission:period-sweep')->assertSuccessful();

        $status = fn (User $u) => (int) \App\Models\CommissionPeriodApplication::where('user_id', $u->id)->value('status');
        $this->assertSame([1, 3], [$status($keep), $status($drop)]);
        $revokedMails = array_filter($this->pushedMails(), fn ($m) => str_contains($m['subject'], '资格已取消'));
        $this->assertCount(1, $revokedMails);
    }

    public function test_admin_config_rules_accept_the_invite_growth_settings(): void
    {
        $data = [
            'invite_first_order_discount' => 12,
            'commission_tier_enable' => 1, 'commission_tier_2_min' => 10, 'commission_tier_2_rate' => 15,
            'commission_tier_3_min' => 30, 'commission_tier_3_rate' => 20,
            'commission_period_auto_enable' => 1, 'commission_period_auto_min_invitees' => 50,
            'commission_notify_enable' => 1, 'commission_leaderboard_enable' => 1,
            'commission_notify_frequency' => 'weekly',
        ];
        $this->assertTrue(Validator::make($data, ConfigSave::RULES)->passes());
        foreach (['instant', 'daily'] as $frequency) {
            $this->assertTrue(Validator::make(['commission_notify_frequency' => $frequency], ConfigSave::RULES)->passes());
        }
        $this->assertTrue(Validator::make(['commission_notify_frequency' => 'hourly'], ConfigSave::RULES)->fails());
        $this->assertTrue(Validator::make(['invite_first_order_discount' => 101], ConfigSave::RULES)->fails());
        $this->assertTrue(Validator::make(['commission_tier_2_rate' => -1], ConfigSave::RULES)->fails());
        $this->assertTrue(Validator::make(['commission_period_auto_min_invitees' => 0], ConfigSave::RULES)->fails());
    }

    // ---------------------------------------------------------------- C1 佣金入账通知

    /** 待结算（commission_status=1）的已完成订单 */
    private function settleableOrder(User $buyer, User $inviter, int $commission = 1000): Order
    {
        return $this->paidOrder($buyer, $inviter, 10000, 1, $commission);
    }

    /** 取 SendEmailJob 的 params（protected） */
    private function mailParams(SendEmailJob $job): array
    {
        return (fn () => $this->params)->call($job);
    }

    public function test_commission_round_sends_one_summary_per_inviter_and_renders_in_every_theme(): void
    {
        $this->settings['commission_notify_frequency'] = 'instant';
        $inviter = $this->makeUser(['email' => 'promoter@example.test', 'telegram_id' => 123456]);
        $other = $this->makeUser(['email' => 'other@example.test']);
        $buyerA = $this->makeUser(['email' => 'alice@gmail.com', 'invite_user_id' => $inviter->id]);
        $buyerB = $this->makeUser(['email' => 'bob@qq.com', 'invite_user_id' => $inviter->id]);
        $buyerC = $this->makeUser(['email' => 'carol@outlook.com', 'invite_user_id' => $other->id]);
        $this->settleableOrder($buyerA, $inviter, 1000);
        $this->settleableOrder($buyerB, $inviter, 1500);
        $this->settleableOrder($buyerC, $other, 700);
        $this->paidOrder($buyerA, $inviter, 10000, 2, 1000);   // 已结算过的不再进本轮

        $this->artisan('check:commission')->assertExitCode(0);

        // 佣金照常落账
        $this->assertSame(2500, (int) $inviter->fresh()->commission_balance);
        $this->assertSame(700, (int) $other->fresh()->commission_balance);
        $this->assertSame(3, CommissionLog::count());
        $this->assertSame(0, Order::where('commission_status', 1)->count());

        // 每个邀请人一封汇总
        Queue::assertPushed(SendEmailJob::class, 2);
        $jobs = [];
        Queue::assertPushed(SendEmailJob::class, function (SendEmailJob $job) use (&$jobs) {
            $p = $this->mailParams($job);
            $jobs[$p['email']] = $p;
            return true;
        });
        $mail = $jobs['promoter@example.test'];
        $this->assertSame('commissionCredited', $mail['template_name']);
        $this->assertSame('commission', $mail['category']);
        $this->assertSame($inviter->id, $mail['user_id']);
        $this->assertSame('佣金入账 ¥25.00 - Sufe', $mail['subject']);
        $v = $mail['template_value'];
        $this->assertSame('¥25.00', $v['total']);
        $this->assertSame(2, $v['count']);
        $this->assertSame('¥25.00', $v['balance']);
        $this->assertSame('https://www.example.test/invite', $v['invite_url']);
        $this->assertSame(['al****@g****.com', 'bo****@q****.com'], array_column($v['items'], 'email'));
        $this->assertSame(['¥10.00', '¥15.00'], array_column($v['items'], 'amount'));
        $this->assertStringContainsString('本轮共 2 笔佣金入账，合计 ¥25.00', $v['content']);
        $this->assertStringContainsString('al****@g****.com  ¥10.00', $v['content']);
        $this->assertStringNotContainsString('alice@gmail.com', json_encode($v, JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, $jobs['other@example.test']['template_value']['count']);

        // 绑了 Telegram 的再推一条纯文本
        Queue::assertPushed(SendTelegramJob::class, 1);
        Queue::assertPushed(SendTelegramJob::class, function (SendTelegramJob $job) {
            [$id, $text, $mode] = (fn () => [$this->telegramId, $this->text, $this->parseMode])->call($job);
            return $id === 123456 && $mode === '' && str_starts_with($text, '佣金入账 ¥25.00') && str_contains($text, 'bo****@q****.com');
        });

        // 三套主题的模板都能渲染，且页脚带偏好管理链接
        foreach (['editorial', 'classic', 'default'] as $theme) {
            $html = view("mail.{$theme}.commissionCredited", $v + ['manage_url' => 'https://www.example.test/n/abc', 'manage_label' => '管理通知偏好'])->render();
            $this->assertStringContainsString('¥25.00', $html, $theme);
            $this->assertStringContainsString('al****@g****.com', $html, $theme);
            $this->assertStringContainsString('https://www.example.test/invite', $html, $theme);
            $this->assertStringContainsString('https://www.example.test/n/abc', $html, $theme);
        }

        // 再跑一轮：没有新入账就不发
        $this->artisan('check:commission')->assertExitCode(0);
        Queue::assertPushed(SendEmailJob::class, 2);
    }

    public function test_commission_notification_respects_the_preference_the_switch_and_bounced_mailboxes(): void
    {
        $this->settings['commission_notify_frequency'] = 'instant';
        $inviter = $this->makeUser(['email' => 'promoter@example.test', 'telegram_id' => 123456]);
        $buyer = $this->makeUser(['invite_user_id' => $inviter->id]);

        // 用户在偏好里关掉了「推广与佣金」：邮件和 Telegram 都不发，佣金照发
        NotificationPreference::set($inviter, NotificationPreference::COMMISSION, false, UserNotificationPref::SOURCE_PANEL);
        $this->settleableOrder($buyer, $inviter, 1000);
        $this->artisan('check:commission')->assertExitCode(0);
        $this->assertSame(1000, (int) $inviter->fresh()->commission_balance);
        Queue::assertNotPushed(SendEmailJob::class);
        Queue::assertNotPushed(SendTelegramJob::class);

        // 偏好打开但总开关关着
        NotificationPreference::set($inviter, NotificationPreference::COMMISSION, true, UserNotificationPref::SOURCE_PANEL);
        $this->settings['commission_notify_enable'] = 0;
        $this->settleableOrder($this->makeUser(['invite_user_id' => $inviter->id]), $inviter, 1000);
        $this->artisan('check:commission')->assertExitCode(0);
        $this->assertSame(2000, (int) $inviter->fresh()->commission_balance);
        Queue::assertNotPushed(SendEmailJob::class);
        Queue::assertNotPushed(SendTelegramJob::class);

        // 退信过的邮箱：不发邮件，Telegram 照推
        $this->settings['commission_notify_enable'] = 1;
        User::where('id', $inviter->id)->update(['mail_suppressed_at' => time()]);
        $this->settleableOrder($this->makeUser(['invite_user_id' => $inviter->id]), $inviter, 1000);
        $this->artisan('check:commission')->assertExitCode(0);
        Queue::assertNotPushed(SendEmailJob::class);
        Queue::assertPushed(SendTelegramJob::class, 1);

        // 偏好面板列出了新类别，默认开启、可关
        Sanctum::actingAs($inviter);
        $cats = collect($this->getJson('/api/v1/user/notify/prefs')->assertOk()->json('data.categories'))->keyBy('key');
        $this->assertTrue($cats->has('commission'));
        $this->assertTrue($cats['commission']['enabled']);
        $this->assertFalse($cats['commission']['locked']);
    }

    /** 每个收件人的 SendEmailJob params，按邮箱分组 */
    private function mailsByRecipient(): array
    {
        $jobs = [];
        Queue::assertPushed(SendEmailJob::class, function (SendEmailJob $job) use (&$jobs) {
            $p = $this->mailParams($job);
            $jobs[$p['email']] = $p;
            return true;
        });
        return $jobs;
    }

    public function test_weekly_by_default_settlement_sends_nothing_and_monday_noon_sends_one_summary(): void
    {
        $inviter = $this->makeUser(['email' => 'promoter@example.test', 'telegram_id' => 123456]);
        $buyerA = $this->makeUser(['email' => 'alice@gmail.com', 'invite_user_id' => $inviter->id]);
        $buyerB = $this->makeUser(['email' => 'bob@qq.com', 'invite_user_id' => $inviter->id]);

        // 周五、周六各入账一笔：佣金照常落账，结算当下不发
        $this->travelTo(Carbon::parse('2026-10-16 10:00:00'));
        $this->settleableOrder($buyerA, $inviter, 1000);
        $this->artisan('check:commission')->assertExitCode(0);
        $this->travelTo(Carbon::parse('2026-10-17 15:00:00'));
        $this->settleableOrder($buyerB, $inviter, 1500);
        $this->artisan('check:commission')->assertExitCode(0);
        $this->assertSame(2500, (int) $inviter->fresh()->commission_balance);
        Queue::assertNotPushed(SendEmailJob::class);
        Queue::assertNotPushed(SendTelegramJob::class);

        // 周日不是发送日
        $this->travelTo(Carbon::parse('2026-10-18 12:00:00'));
        $this->artisan('commission:notify-digest')->assertExitCode(0);
        Queue::assertNotPushed(SendEmailJob::class);

        // 周一中午：一封汇总，Telegram 一条
        $this->travelTo(Carbon::parse('2026-10-19 12:00:00'));
        $this->artisan('commission:notify-digest')->assertExitCode(0);
        Queue::assertPushed(SendEmailJob::class, 1);
        $mail = $this->mailsByRecipient()['promoter@example.test'];
        $this->assertSame('commissionCredited', $mail['template_name']);
        $this->assertSame('commission', $mail['category']);
        $this->assertSame('每周佣金汇总 ¥25.00 - Sufe', $mail['subject']);
        $v = $mail['template_value'];
        $this->assertSame('每周佣金汇总', $v['heading']);
        $this->assertSame('过去一周共 2 笔，已计入你的佣金余额', $v['subtitle']);
        $this->assertSame('过去一周共 2 笔佣金入账，合计 ¥25.00，已计入你的佣金余额', $v['summary']);
        $this->assertSame(2, $v['count']);
        $this->assertNull($v['more']);
        $this->assertSame(['al****@g****.com', 'bo****@q****.com'], array_column($v['items'], 'email'));
        $this->assertSame(['¥10.00', '¥15.00'], array_column($v['items'], 'amount'));
        $this->assertSame(['2026-10-16 10:00', '2026-10-17 15:00'], array_column($v['items'], 'time'));
        $this->assertStringStartsWith('过去一周共 2 笔佣金入账，合计 ¥25.00', $v['content']);
        Queue::assertPushed(SendTelegramJob::class, function (SendTelegramJob $job) {
            [$id, $text] = (fn () => [$this->telegramId, $this->text])->call($job);
            return $id === 123456 && str_starts_with($text, '每周佣金汇总 ¥25.00') && str_contains($text, 'bo****@q****.com');
        });
        $this->assertSame((int) CommissionLog::max('id'), (int) $this->settings[CommissionDigest::WATERMARK_KEY]);

        // 三套主题的模板都用上了汇总的标题和说明
        foreach (['editorial', 'classic', 'default'] as $theme) {
            $html = view("mail.{$theme}.commissionCredited", $v)->render();
            $this->assertStringContainsString('每周佣金汇总', $html, $theme);
            $this->assertStringContainsString('过去一周共', $html, $theme);
            $this->assertStringNotContainsString('本轮', $html, $theme);
        }

        // 同一天再跑：没有新入账就不发
        $this->artisan('commission:notify-digest')->assertExitCode(0);
        Queue::assertPushed(SendEmailJob::class, 1);

        // 下一周只汇总新的那笔
        $this->travelTo(Carbon::parse('2026-10-21 09:30:00'));
        $this->settleableOrder($this->makeUser(['email' => 'erin@gmail.com', 'invite_user_id' => $inviter->id]), $inviter, 800);
        $this->artisan('check:commission')->assertExitCode(0);
        $this->travelTo(Carbon::parse('2026-10-26 12:00:00'));
        $this->artisan('commission:notify-digest')->assertExitCode(0);
        Queue::assertPushed(SendEmailJob::class, 2);
        Queue::assertPushed(SendEmailJob::class, function (SendEmailJob $job) {
            $p = $this->mailParams($job);
            return $p['subject'] === '每周佣金汇总 ¥8.00 - Sufe' && $p['template_value']['count'] === 1;
        });
    }

    public function test_daily_digest_shows_levels_caps_long_lists_and_never_reaches_back_past_the_lookback(): void
    {
        $this->settings['commission_notify_frequency'] = 'daily';
        $this->settings['commission_distribution_enable'] = 1;
        $this->travelTo(Carbon::parse('2026-10-18 12:00:00'));
        $top = $this->makeUser(['email' => 'top@example.test']);
        $mid = $this->makeUser(['email' => 'mid@example.test', 'invite_user_id' => $top->id]);
        $buyer = $this->makeUser(['email' => 'dave@gmail.com', 'invite_user_id' => $mid->id]);

        // 十天前的历史佣金：第一次跑（还没有水位）也不会翻出来
        $this->log($top, $buyer, 999, now()->subDays(10)->getTimestamp());
        // 同一笔订单：mid 是一级，top 是二级
        $this->log($mid, $buyer, 1000, now()->subHours(5)->getTimestamp());
        $this->log($top, $buyer, 500, now()->subHours(5)->getTimestamp());
        // mid 另有 31 笔，一封里只列最近 30 笔
        for ($i = 0; $i < 31; $i++) {
            $this->log($mid, $this->makeUser(['invite_user_id' => $mid->id]), 100, now()->subHours(2)->getTimestamp());
        }

        $this->artisan('commission:notify-digest')->assertExitCode(0);
        Queue::assertPushed(SendEmailJob::class, 2);
        $mails = $this->mailsByRecipient();

        $topMail = $mails['top@example.test'];
        $this->assertSame('每日佣金汇总 ¥5.00 - Sufe', $topMail['subject']);
        $this->assertSame([2], array_column($topMail['template_value']['items'], 'level'));
        $this->assertStringContainsString('第 2 级', $topMail['template_value']['content']);
        $this->assertSame('过去一天共 1 笔，已计入你的佣金余额', $topMail['template_value']['subtitle']);

        $v = $mails['mid@example.test']['template_value'];
        $this->assertSame('每日佣金汇总 ¥41.00 - Sufe', $mails['mid@example.test']['subject']);
        $this->assertSame(32, $v['count']);
        $this->assertCount(30, $v['items']);
        $this->assertSame([1], array_values(array_unique(array_column($v['items'], 'level'))));
        $this->assertSame('另有 2 笔未列出，可在邀请页查看全部', $v['more']);
        $this->assertStringContainsString('另有 2 笔未列出', $v['content']);
        foreach (['editorial', 'classic', 'default'] as $theme) {
            $html = view("mail.{$theme}.commissionCredited", $v)->render();
            $this->assertStringContainsString('另有 2 笔未列出', $html, $theme);
        }

        // 每天都发，但没有新入账就不发
        $this->travelTo(Carbon::parse('2026-10-19 12:00:00'));
        $this->artisan('commission:notify-digest')->assertExitCode(0);
        Queue::assertPushed(SendEmailJob::class, 2);
    }

    public function test_digest_does_nothing_in_instant_mode_and_never_replays_what_the_switch_or_preference_held_back(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 12:00:00'));
        $inviter = $this->makeUser(['email' => 'promoter@example.test']);
        $buyer = $this->makeUser(['invite_user_id' => $inviter->id]);
        $this->log($inviter, $buyer, 1000, now()->subDay()->getTimestamp());

        // 每笔即时发送：结算当下已经发过，汇总命令什么都不做
        $this->settings['commission_notify_frequency'] = 'instant';
        $this->artisan('commission:notify-digest')->assertExitCode(0);
        Queue::assertNotPushed(SendEmailJob::class);
        $this->assertArrayNotHasKey(CommissionDigest::WATERMARK_KEY, $this->settings);

        // 每周 + 总开关关着：不发，水位照样推进
        $this->settings['commission_notify_frequency'] = 'weekly';
        $this->settings['commission_notify_enable'] = 0;
        $this->artisan('commission:notify-digest')->assertExitCode(0);
        Queue::assertNotPushed(SendEmailJob::class);
        $this->assertSame((int) CommissionLog::max('id'), (int) $this->settings[CommissionDigest::WATERMARK_KEY]);

        // 再打开：关着期间的不补发
        $this->settings['commission_notify_enable'] = 1;
        $this->artisan('commission:notify-digest')->assertExitCode(0);
        Queue::assertNotPushed(SendEmailJob::class);

        // 用户在偏好里关了「推广与佣金」：新入账也不发，水位推进
        NotificationPreference::set($inviter, NotificationPreference::COMMISSION, false, UserNotificationPref::SOURCE_PANEL);
        $this->log($inviter, $buyer, 700, now()->subHour()->getTimestamp());
        $this->artisan('commission:notify-digest')->assertExitCode(0);
        Queue::assertNotPushed(SendEmailJob::class);
        $this->assertSame((int) CommissionLog::max('id'), (int) $this->settings[CommissionDigest::WATERMARK_KEY]);

        // 没填或填了不认识的值都按每周
        unset($this->settings['commission_notify_frequency']);
        $this->assertSame('weekly', CommissionNotifier::frequency());
        $this->settings['commission_notify_frequency'] = 'bogus';
        $this->assertSame('weekly', CommissionNotifier::frequency());
    }

    // ---------------------------------------------------------------- B5 月度榜单

    private function log(User $inviter, User $buyer, int $amount, ?int $at = null): void
    {
        CommissionLog::create([
            'invite_user_id' => $inviter->id, 'user_id' => $buyer->id, 'trade_no' => Helper::generateOrderNo(),
            'order_amount' => $amount * 10, 'get_amount' => $amount, 'created_at' => $at ?? time(), 'updated_at' => $at ?? time(),
        ]);
    }

    public function test_leaderboard_is_off_by_default_and_ranks_this_months_commission_when_on(): void
    {
        $me = $this->makeUser(['email' => 'shannon@sufe.us']);
        Sanctum::actingAs($me);
        $this->assertSame(['enable' => false], $this->getJson('/api/v1/user/invite/fetch')->assertOk()->json('data.leaderboard'));

        $this->settings['commission_leaderboard_enable'] = 1;
        $top = $this->makeUser(['email' => 'lihua@gmail.com']);
        $buyers = [$this->makeUser(), $this->makeUser(), $this->makeUser()];
        $this->log($top, $buyers[0], 6000);
        $this->log($top, $buyers[1], 4000);
        $this->log($top, $buyers[1], 2000);                 // 同一人第二笔：人数不重复
        $this->log($me, $buyers[2], 500);
        $this->log($me, $buyers[0], 9000, strtotime('first day of last month'));   // 上月的不算
        // 11 位只有一笔小佣金的人，把「我」挤出前 10
        for ($i = 0; $i < 11; $i++) {
            $this->log($this->makeUser(), $buyers[2], 600 + $i);
        }
        Leaderboard::forget();

        $board = $this->getJson('/api/v1/user/invite/fetch')->assertOk()->json('data.leaderboard');
        $this->assertTrue($board['enable']);
        $this->assertSame(date('Y-m'), $board['month']);
        $this->assertCount(10, $board['items']);
        $this->assertSame(['rank' => 1, 'email' => 'li****@g****.com', 'paid_invitees' => 2, 'commission_total' => 12000, 'is_me' => false], $board['items'][0]);
        $this->assertSame(range(1, 10), array_column($board['items'], 'rank'));
        $this->assertFalse(in_array(true, array_column($board['items'], 'is_me'), true));
        $this->assertSame(['rank' => null, 'paid_invitees' => 1, 'commission_total' => 500], $board['me']);
        $this->assertStringNotContainsString('lihua@gmail.com', json_encode($board));

        // 榜首看自己：is_me 与 me.rank
        Sanctum::actingAs($top);
        $board = $this->getJson('/api/v1/user/invite/fetch')->assertOk()->json('data.leaderboard');
        $this->assertTrue($board['items'][0]['is_me']);
        $this->assertSame(['rank' => 1, 'paid_invitees' => 2, 'commission_total' => 12000], $board['me']);
    }
}
