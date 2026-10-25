<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramJob;
use App\Models\BalanceLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\RechargeOrder;
use App\Models\Stat;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use App\Services\Recharge\RechargeConfig;
use App\Services\Recharge\RechargeService;
use App\Services\StatisticalService;
use App\Support\PaymentGuard;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Plugin\Epay\Plugin as EpayPlugin;
use Tests\TestCase;

/**
 * 余额充值并入核心：下单（赠送快照、复用 / 替换待支付单）、收银台（绑定、手续费、订阅式网关）、
 * 回调入账（幂等、流水、取消后到账、二次付款、跨单据流水去重、欠额）、后台接口与设置校验、
 * 超时取消、收入口径、旧插件数据迁移与历史流水回填。
 */
class BalanceRechargeTest extends TestCase
{
    use RefreshDatabase;

    /** 假网关收到的 pay() 参数 */
    private array $payCalls = [];

    /** 假网关插件 notify() 的返回值 */
    private array|bool $verify = false;

    protected function setUp(): void
    {
        parent::setUp();
        // 系统设置缓存固定走 redis 存储：测试里换成内存
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        app()->forgetScopedInstances();
        Bus::fake([SendTelegramJob::class]);
        // 绑了 Telegram 的管理员：告警才会真的派发 SendTelegramJob
        User::create([
            'email' => 'admin@example.test', 'password' => 'x', 'uuid' => (string) Str::uuid(),
            'token' => Str::random(32), 'is_admin' => 1, 'telegram_id' => 10001,
        ]);
        $this->enableRecharge();
    }

    // ───────────────────────── 工具 ─────────────────────────

    private function enableRecharge(array $overrides = []): void
    {
        admin_setting($overrides + [
            'recharge_enable' => 1,
            'recharge_min_amount' => 1,
            'recharge_max_amount' => 5000,
            'recharge_bonus_tiers' => [['amount' => 100, 'bonus' => 10], ['amount' => 500, 'bonus' => 80]],
            'recharge_quick_amounts' => [30, 100, 500],
        ]);
    }

    private function user(array $attributes = []): User
    {
        return User::create($attributes + [
            'email' => Str::random(10) . '@example.test', 'password' => 'x', 'uuid' => (string) Str::uuid(),
            'token' => Str::random(32), 'balance' => 0, 'transfer_enable' => 0,
        ]);
    }

    private function payment(string $method = 'EPay', array $config = ['url' => 'https://pay.test', 'pid' => '1001', 'key' => 'k1'], array $attributes = []): Payment
    {
        return Payment::create($attributes + [
            'uuid' => Str::random(8), 'payment' => $method, 'name' => $method . ' 收款', 'config' => $config, 'enable' => true,
        ]);
    }

    private function recharge(User $user, array $attributes = []): RechargeOrder
    {
        return RechargeOrder::create($attributes + [
            'user_id' => $user->id, 'trade_no' => Helper::generateOrderNo(), 'deposit_amount' => 10000,
            'bonus_amount' => 1000, 'total_credit' => 11000, 'pay_amount' => 10000, 'status' => RechargeOrder::STATUS_PENDING,
        ]);
    }

    /** 收银台换成假网关：记下 pay() 收到的参数，回一个固定的收款地址 */
    private function fakeGateway(): void
    {
        $calls = &$this->payCalls;
        app()->instance(CheckoutService::class, new class ($calls) extends CheckoutService {
            public function __construct(private array &$calls)
            {
            }

            protected function gateway(Payment $payment): PaymentService
            {
                $calls = &$this->calls;
                return new class ($calls, $payment) extends PaymentService {
                    public function __construct(private array &$calls, private Payment $p)
                    {
                    }

                    public function pay($order)
                    {
                        $this->calls[] = $order + ['payment_id' => $this->p->id];
                        return ['type' => 1, 'data' => 'https://pay.test/submit'];
                    }
                };
            }
        });
    }

    /** 假装启用了一个提供 EPay 的支付插件，notify() 返回 $this->verify */
    private function fakeNotifyPlugin(): void
    {
        $test = $this;
        $plugin = new class ($test) {
            public array $config = [];
            public function __construct(private BalanceRechargeTest $test) {}
            public function getPluginCode(): string { return 'epay_test'; }
            public function setConfig(array $config): void { $this->config = $config; }
            public function notify($params) { return $this->test->verifyResult(); }
        };
        HookManager::registerFilter('available_payment_methods', fn ($methods) => $methods + ['EPay' => ['plugin_code' => 'epay_test']]);
        app()->instance(PluginManager::class, new class ($plugin) extends PluginManager {
            public function __construct(private object $fake) { parent::__construct(); }
            public function getEnabledPaymentPlugins(): array { return [$this->fake]; }
        });
    }

    public function verifyResult(): array|bool
    {
        return $this->verify;
    }

    private function notify(Payment $payment, array $verify, string $route = 'notify')
    {
        $this->verify = $verify;
        return $this->post("/api/v1/guest/payment/{$route}/{$payment->payment}/{$payment->uuid}", []);
    }

    private function adminPath(string $path): string
    {
        return '/api/v2/' . admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))) . $path;
    }

    private function actingAsAdmin(): User
    {
        $admin = User::where('email', 'admin@example.test')->first();
        Sanctum::actingAs($admin, ['admin']);
        return $admin;
    }

    // ───────────────────────── 配置 ─────────────────────────

    public function test_config_parses_money_without_float_truncation_and_normalizes_tiers(): void
    {
        $this->assertSame(29, RechargeConfig::toCents('0.29'));
        $this->assertSame(29, RechargeConfig::toCents(0.29));
        $this->assertSame(10000, RechargeConfig::toCents(100));
        $this->assertNull(RechargeConfig::toCents('1.234'));
        $this->assertNull(RechargeConfig::toCents('-1'));

        $tiers = RechargeConfig::normalizeTiers([
            ['amount' => 500, 'bonus' => 50], ['amount' => 100, 'bonus' => 10], ['amount' => 500, 'bonus' => 80],
            ['amount' => 0, 'bonus' => 5], ['amount' => 'x', 'bonus' => 1], 'junk',
        ]);
        $this->assertSame([['amount' => 10000, 'bonus' => 1000], ['amount' => 50000, 'bonus' => 8000]], $tiers);

        $config = RechargeConfig::fromSettings();
        $this->assertSame(0, $config->bonusFor(9999));
        $this->assertSame(1000, $config->bonusFor(10000));
        $this->assertSame(8000, $config->bonusFor(60000));
    }

    public function test_guest_config_exposes_recharge_settings_under_the_legacy_keys(): void
    {
        $data = $this->getJson('/api/v1/guest/comm/config')->assertOk()->json('data');

        $this->assertTrue($data['balance_recharge_enable']);
        $this->assertEquals(1, $data['balance_recharge_min']);
        $this->assertEquals(5000, $data['balance_recharge_max']);
        $this->assertEquals([['amount' => 100, 'bonus' => 10], ['amount' => 500, 'bonus' => 80]], $data['deposit_bonus_tiers']);
        $this->assertEquals([30, 100, 500], $data['balance_recharge_quick_amounts']);
    }

    // ───────────────────────── 下单 ─────────────────────────

    public function test_deposit_order_snapshots_bonus_and_inviter(): void
    {
        $inviter = $this->user();
        $user = $this->user(['invite_user_id' => $inviter->id]);
        Sanctum::actingAs($user);

        $tradeNo = $this->postJson('/api/v1/user/order/save', ['plan_id' => 0, 'period' => 'deposit', 'deposit_amount' => 10000])
            ->assertOk()->json('data');

        $recharge = RechargeOrder::where('trade_no', $tradeNo)->firstOrFail();
        $this->assertSame([10000, 1000, 11000, 10000], [$recharge->deposit_amount, $recharge->bonus_amount, $recharge->total_credit, $recharge->pay_amount]);
        $this->assertSame($inviter->id, $recharge->invite_user_id);
        $this->assertSame(0, $recharge->commission_balance, '充值不返佣');

        // 档位之后改了，已下的单仍按下单时的赠送额
        $this->enableRecharge(['recharge_bonus_tiers' => []]);
        $this->assertSame(11000, (int) $recharge->fresh()->total_credit);
    }

    public function test_plan_id_zero_without_period_is_still_a_deposit(): void
    {
        Sanctum::actingAs($this->user());
        $tradeNo = $this->postJson('/api/v1/user/order/save', ['plan_id' => 0, 'deposit_amount' => 3000])->assertOk()->json('data');
        $this->assertTrue(RechargeOrder::where('trade_no', $tradeNo)->exists());
    }

    public function test_deposit_respects_switch_and_amount_limits(): void
    {
        Sanctum::actingAs($this->user());
        $save = fn (int $amount) => $this->postJson('/api/v1/user/order/save', ['plan_id' => 0, 'period' => 'deposit', 'deposit_amount' => $amount]);

        $save(99)->assertStatus(400)->assertJsonFragment(['message' => '单笔最低充值 1.00 元']);
        $save(500001)->assertStatus(400)->assertJsonFragment(['message' => '单笔最高充值 5000.00 元']);
        $save(0)->assertStatus(422);

        $this->enableRecharge(['recharge_enable' => 0]);
        $save(1000)->assertStatus(400)->assertJsonFragment(['message' => '充值功能暂未开放']);
        $this->assertSame(0, RechargeOrder::count());
    }

    public function test_same_amount_reuses_the_pending_order_and_a_new_amount_replaces_it(): void
    {
        $user = $this->user();
        $service = app(RechargeService::class);

        $first = $service->create($user, 3000);
        $this->assertSame($first->id, $service->create($user, 3000)->id, '连点两次不会多出一张');

        $second = $service->create($user, 5000);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(RechargeOrder::STATUS_CANCELLED, (int) $first->fresh()->status, '改金额时旧的待支付单作废');
        $this->assertSame(RechargeOrder::STATUS_PENDING, (int) $second->fresh()->status);
    }

    public function test_creating_recharges_is_rate_limited_per_user(): void
    {
        $user = $this->user();
        $service = app(RechargeService::class);
        for ($i = 0; $i < RechargeService::CREATE_LIMIT_PER_HOUR; $i++) {
            $service->create($user, 100 + $i);
        }
        $this->expectExceptionMessage('充值下单过于频繁');
        $service->create($user, 9999);
    }

    // ───────────────────────── 列表 / 详情 / 轮询 / 取消 ─────────────────────────

    public function test_detail_and_fetch_present_recharges_in_the_legacy_order_shape(): void
    {
        $user = $this->user();
        $plan = Plan::create(['name' => '月付', 'group_id' => 1, 'show' => true, 'sell' => true, 'renew' => true, 'transfer_enable' => 100, 'prices' => ['monthly' => 10]]);
        $order = Order::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'period' => 'monthly', 'type' => Order::TYPE_NEW_PURCHASE,
            'trade_no' => Helper::generateOrderNo(), 'total_amount' => 1000, 'status' => Order::STATUS_COMPLETED,
            'created_at' => time() - 100,
        ]);
        $recharge = $this->recharge($user, ['created_at' => time()]);
        Sanctum::actingAs($user);

        $detail = $this->getJson('/api/v1/user/order/detail?trade_no=' . $recharge->trade_no)->assertOk()->json('data');
        $this->assertSame(0, $detail['plan_id']);
        $this->assertSame('余额充值', $detail['plan']['name']);
        $this->assertSame('deposit', $detail['period']);
        $this->assertSame(RechargeOrder::LEGACY_ORDER_TYPE, $detail['type']);
        $this->assertSame([10000, 1000, 11000], [$detail['total_amount'], $detail['bounus'], $detail['get_amount']]);
        $this->assertTrue($detail['is_recharge']);

        $list = $this->getJson('/api/v1/user/order/fetch')->assertOk()->json('data');
        $this->assertSame([$recharge->trade_no, $order->trade_no], array_column($list, 'trade_no'), '按创建时间倒序合并');

        $this->assertSame([], $this->getJson('/api/v1/user/order/fetch?status=1')->assertOk()->json('data'));
    }

    public function test_check_and_cancel_recognise_recharges_and_stay_scoped_to_the_owner(): void
    {
        $user = $this->user();
        $recharge = $this->recharge($user);
        Sanctum::actingAs($this->user());
        $this->getJson('/api/v1/user/order/check?trade_no=' . $recharge->trade_no)->assertStatus(400);
        $this->postJson('/api/v1/user/order/cancel', ['trade_no' => $recharge->trade_no])->assertStatus(400);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/user/order/check?trade_no=' . $recharge->trade_no)->assertOk()->assertJsonPath('data', 0);
        $this->postJson('/api/v1/user/order/cancel', ['trade_no' => $recharge->trade_no])->assertOk();
        $this->assertSame(RechargeOrder::STATUS_CANCELLED, (int) $recharge->fresh()->status);
        $this->postJson('/api/v1/user/order/cancel', ['trade_no' => $recharge->trade_no])->assertStatus(400);
    }

    // ───────────────────────── 收银台 ─────────────────────────

    public function test_checkout_binds_the_payment_and_charges_its_handling_fee(): void
    {
        $this->fakeGateway();
        $user = $this->user();
        $recharge = $this->recharge($user);
        $payment = $this->payment(attributes: ['handling_fee_percent' => 2, 'handling_fee_fixed' => 50]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/user/order/checkout', ['trade_no' => $recharge->trade_no, 'method' => $payment->id])
            ->assertOk()->assertJson(['type' => 1, 'data' => 'https://pay.test/submit']);

        $recharge->refresh();
        $this->assertSame([$payment->id, 'EPay', 250, 10250], [$recharge->payment_id, $recharge->payment_method, $recharge->handling_amount, $recharge->pay_amount]);
        $this->assertSame(11000, $recharge->total_credit, '手续费不影响到账额');
        $this->assertCount(1, $this->payCalls);
        $this->assertSame($recharge->trade_no, $this->payCalls[0]['trade_no']);
        $this->assertSame(10250, $this->payCalls[0]['total_amount']);
        $this->assertStringEndsWith('/#/order/' . $recharge->trade_no, $this->payCalls[0]['return_url']);
    }

    public function test_checkout_refuses_subscription_gateways(): void
    {
        $this->fakeGateway();
        $user = $this->user();
        $recharge = $this->recharge($user);
        Sanctum::actingAs($user);

        foreach (['StripeSub', 'PaypalSubscription'] as $method) {
            $payment = $this->payment($method, ['secret' => 's']);
            $this->postJson('/api/v1/user/order/checkout', ['trade_no' => $recharge->trade_no, 'method' => $payment->id])
                ->assertStatus(400)->assertJsonFragment(['message' => '该支付方式不支持余额充值，请换一种支付方式']);
        }
        $this->assertSame([], $this->payCalls);

        $methods = collect($this->getJson('/api/v1/user/order/getPaymentMethod')->assertOk()->json('data'))->keyBy('payment');
        $this->assertFalse($methods['StripeSub']['supports_recharge']);
        $this->assertFalse($methods['StripeSub']['supports_customization'], 'StripeSubscription 插件的方式名是 StripeSub');
    }

    public function test_checkout_allows_alias_configs_of_the_same_merchant_but_not_another_merchant(): void
    {
        $this->fakeGateway();
        $user = $this->user();
        $recharge = $this->recharge($user);
        $alipay = $this->payment(config: ['url' => 'https://pay.test', 'pid' => '1001', 'key' => 'k1', 'type' => 'alipay']);
        $wxpay = $this->payment(config: ['url' => 'https://pay.test', 'pid' => '1001', 'key' => 'k1', 'type' => 'wxpay']);
        $other = $this->payment(config: ['url' => 'https://other.test', 'pid' => '2002', 'key' => 'k2']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/user/order/checkout', ['trade_no' => $recharge->trade_no, 'method' => $alipay->id])->assertOk();
        $this->postJson('/api/v1/user/order/checkout', ['trade_no' => $recharge->trade_no, 'method' => $wxpay->id])->assertOk();
        $this->assertSame($alipay->id, (int) $recharge->fresh()->payment_id, '同商户的别名配置不改绑');

        $this->postJson('/api/v1/user/order/checkout', ['trade_no' => $recharge->trade_no, 'method' => $other->id])
            ->assertStatus(400)->assertJsonFragment(['message' => '订单已绑定其他支付方式，请取消订单后重新下单']);
    }

    // ───────────────────────── 回调入账 ─────────────────────────

    public function test_callback_credits_once_and_writes_a_recharge_ledger_entry(): void
    {
        $this->fakeNotifyPlugin();
        $user = $this->user(['balance' => 500]);
        $payment = $this->payment();
        $recharge = $this->recharge($user, ['payment_id' => $payment->id]);

        $this->notify($payment, ['trade_no' => $recharge->trade_no, 'callback_no' => 'GW-1'])->assertOk()->assertSee('success');
        $this->notify($payment, ['trade_no' => $recharge->trade_no, 'callback_no' => 'GW-1'])->assertOk();

        $this->assertSame(500 + 11000, (int) $user->fresh()->balance, '网关重投不会重复加钱');
        $recharge->refresh();
        $this->assertSame([RechargeOrder::STATUS_COMPLETED, 'GW-1'], [$recharge->status, $recharge->callback_no]);
        $this->assertNotNull($recharge->paid_at);

        $log = BalanceLog::where('user_id', $user->id)->sole();
        $this->assertSame([BalanceLog::TYPE_RECHARGE, 11000, 500, 11500], [$log->type, (int) $log->amount, (int) $log->balance_before, (int) $log->balance_after]);
        $this->assertSame(['recharge', $recharge->trade_no], [$log->ref_type, $log->ref_id]);
        $this->assertSame('充值 ¥100.00，赠送 ¥10.00', $log->remark);
    }

    public function test_legacy_recharge_notify_url_is_an_alias_of_the_core_callback(): void
    {
        $this->fakeNotifyPlugin();
        $user = $this->user();
        $payment = $this->payment();
        $recharge = $this->recharge($user, ['payment_id' => $payment->id]);

        $this->notify($payment, ['trade_no' => $recharge->trade_no, 'callback_no' => 'GW-2'], 'recharge-notify')->assertOk();
        $this->assertSame(11000, (int) $user->fresh()->balance);
    }

    public function test_callback_from_another_merchant_or_short_payment_is_rejected(): void
    {
        $this->fakeNotifyPlugin();
        $user = $this->user();
        $bound = $this->payment();
        $other = $this->payment(config: ['url' => 'https://other.test', 'pid' => '2002', 'key' => 'k2']);
        $recharge = $this->recharge($user, ['payment_id' => $bound->id]);

        $this->notify($other, ['trade_no' => $recharge->trade_no, 'callback_no' => 'GW-X'])->assertStatus(400);
        $this->notify($bound, ['trade_no' => $recharge->trade_no, 'callback_no' => 'GW-Y', 'paid_amount' => 9999])->assertStatus(400);

        $this->assertSame(RechargeOrder::STATUS_PENDING, (int) $recharge->fresh()->status);
        $this->assertSame(0, (int) $user->fresh()->balance);

        // 一笔流水已经给别的单据入过账（比如它其实是一张订单的付款），不能再拿来给充值单入账
        $plan = Plan::create(['name' => '月付', 'group_id' => 1, 'show' => true, 'sell' => true, 'renew' => true, 'transfer_enable' => 100, 'prices' => ['monthly' => 10]]);
        $order = Order::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'period' => 'monthly', 'type' => Order::TYPE_NEW_PURCHASE,
            'trade_no' => Helper::generateOrderNo(), 'total_amount' => 1000, 'status' => Order::STATUS_PENDING, 'payment_id' => $bound->id,
        ]);
        Bus::fake([\App\Jobs\OrderHandleJob::class, SendTelegramJob::class]);
        $this->assertTrue((new OrderService($order))->paid('GW-SHARED', $bound->id, true));
        $this->notify($bound, ['trade_no' => $recharge->trade_no, 'callback_no' => 'GW-SHARED'])->assertStatus(400);
        $this->assertSame(0, (int) $user->fresh()->balance);
    }

    public function test_cancelled_recharge_paid_late_is_still_credited_and_admins_are_told(): void
    {
        $this->fakeNotifyPlugin();
        $user = $this->user();
        $payment = $this->payment();
        $recharge = $this->recharge($user, ['payment_id' => $payment->id, 'status' => RechargeOrder::STATUS_CANCELLED]);

        $this->notify($payment, ['trade_no' => $recharge->trade_no, 'callback_no' => 'GW-LATE'])->assertOk();

        $this->assertSame(RechargeOrder::STATUS_COMPLETED, (int) $recharge->fresh()->status);
        $this->assertSame(11000, (int) $user->fresh()->balance);
        $this->assertStringContainsString('单据取消后到账', BalanceLog::where('user_id', $user->id)->sole()->remark);
        Bus::assertDispatched(SendTelegramJob::class, fn ($job) => str_contains(serialize($job), '已取消的充值单收到付款'));
    }

    public function test_second_payment_on_a_credited_recharge_is_acknowledged_but_sent_to_manual_review(): void
    {
        $this->fakeNotifyPlugin();
        $user = $this->user();
        $payment = $this->payment();
        $recharge = $this->recharge($user, ['payment_id' => $payment->id]);

        $this->notify($payment, ['trade_no' => $recharge->trade_no, 'callback_no' => 'GW-A'])->assertOk();
        $this->notify($payment, ['trade_no' => $recharge->trade_no, 'callback_no' => 'GW-B', 'paid_amount' => 10000])->assertOk();

        $this->assertSame(11000, (int) $user->fresh()->balance, '没有支付会话可核对时不自动加第二次');
        $this->assertSame('GW-A', $recharge->fresh()->callback_no);
        Bus::assertDispatched(SendTelegramJob::class, fn ($job) => str_contains(serialize($job), '充值单重复付款需人工处理'));
    }

    public function test_gateway_callback_after_a_manual_confirmation_is_adopted_not_credited_again(): void
    {
        $this->fakeNotifyPlugin();
        $user = $this->user();
        $payment = $this->payment();
        $recharge = $this->recharge($user, ['payment_id' => $payment->id]);
        app(RechargeService::class)->confirmByAdmin($recharge, null);

        $this->notify($payment, ['trade_no' => $recharge->trade_no, 'callback_no' => 'GW-LATE-CB'])->assertOk();
        $this->notify($payment, ['trade_no' => $recharge->trade_no, 'callback_no' => 'GW-LATE-CB'])->assertOk();

        $this->assertSame(11000, (int) $user->fresh()->balance, '回调迟到的那一笔就是手动确认的那一笔');
        $this->assertSame('GW-LATE-CB', $recharge->fresh()->callback_no);
        Bus::assertDispatched(SendTelegramJob::class, fn ($job) => str_contains(serialize($job), '手动确认过的充值单收到了网关回调'));
    }

    public function test_callback_rebinds_the_recharge_to_the_alias_that_was_actually_paid(): void
    {
        $this->fakeNotifyPlugin();
        $user = $this->user();
        $alipay = $this->payment(config: ['url' => 'https://pay.test', 'pid' => '1001', 'key' => 'k1', 'type' => 'alipay']);
        $wxpay = $this->payment(config: ['url' => 'https://pay.test', 'pid' => '1001', 'key' => 'k1', 'type' => 'wxpay']);
        $recharge = $this->recharge($user, ['payment_id' => $alipay->id, 'payment_method' => 'EPay']);

        $this->notify($wxpay, ['trade_no' => $recharge->trade_no, 'callback_no' => 'GW-WX'])->assertOk();

        $this->assertSame($wxpay->id, (int) $recharge->fresh()->payment_id, '事后核验要去真正收钱的那条配置查');
        $this->assertSame(11000, (int) $user->fresh()->balance);
    }

    public function test_gateways_with_their_own_webhooks_cannot_pay_recharges(): void
    {
        $this->fakeGateway();
        $user = $this->user();
        $recharge = $this->recharge($user);
        $stripe = $this->payment('StripeCheckout', ['secret_key' => 'sk']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/user/order/checkout', ['trade_no' => $recharge->trade_no, 'method' => $stripe->id])
            ->assertStatus(400)->assertJsonFragment(['message' => '该支付方式不支持余额充值，请换一种支付方式']);
        $methods = collect($this->getJson('/api/v1/user/order/getPaymentMethod')->json('data'))->keyBy('payment');
        $this->assertFalse($methods['StripeCheckout']['supports_recharge']);
        $this->assertTrue($methods['StripeCheckout']['supports_customization'], '一次性网关仍可付自选套餐');
    }

    public function test_payment_verification_expects_the_amount_actually_charged_for_recharges_with_fees(): void
    {
        $user = $this->user();
        $payment = $this->payment();
        $recharge = $this->recharge($user, [
            'payment_id' => $payment->id, 'handling_amount' => 250, 'pay_amount' => 10250,
            'status' => RechargeOrder::STATUS_COMPLETED, 'paid_at' => time() - 3600, 'callback_no' => 'GW-FEE',
        ]);

        $subjects = (new \App\Services\PaymentVerificationService(fn () => []))->dueSubjects(time(), 10);
        $subject = collect($subjects)->firstWhere('id', $recharge->id);
        $this->assertSame(10250, $subject['expected'], '手续费只算一次');
    }

    public function test_payment_guard_and_the_epay_plugin_know_the_recharge_amount(): void
    {
        $recharge = $this->recharge($this->user(), ['pay_amount' => 10250, 'handling_amount' => 250]);

        $this->assertTrue(PaymentGuard::ensureAmount('EPay', $recharge->trade_no, 10250, 'enforce'), 'pay_amount 已含手续费，不能再加一次');
        $this->assertFalse(PaymentGuard::ensureAmount('EPay', $recharge->trade_no, 10000, 'enforce'));

        $plugin = new EpayPlugin('epay');
        $plugin->setConfig(['key' => 'epay-secret', 'pid' => 'merchant-1']);
        $sign = function (array $params): array {
            ksort($params);
            $params['sign'] = md5(stripslashes(urldecode(http_build_query($params))) . 'epay-secret');
            $params['sign_type'] = 'MD5';
            return $params;
        };
        $params = ['pid' => 'merchant-1', 'out_trade_no' => $recharge->trade_no, 'trade_no' => 'EPAY-R1', 'trade_status' => 'TRADE_SUCCESS', 'money' => '102.50'];
        $this->assertIsArray($plugin->notify($sign($params)));
        $this->assertFalse($plugin->notify($sign(['money' => '100.00'] + $params)));
    }

    // ───────────────────────── 后台 ─────────────────────────

    public function test_admin_lists_details_and_summarises_recharges(): void
    {
        $user = $this->user(['email' => 'buyer@example.test']);
        $payment = $this->payment();
        $paid = $this->recharge($user, ['payment_id' => $payment->id, 'status' => RechargeOrder::STATUS_COMPLETED, 'paid_at' => time(), 'callback_no' => 'GW-9']);
        $this->recharge($this->user());
        BalanceLog::create(['user_id' => $user->id, 'type' => BalanceLog::TYPE_RECHARGE, 'amount' => 11000, 'balance_before' => 0, 'balance_after' => 11000, 'ref_type' => 'recharge', 'ref_id' => $paid->trade_no, 'created_at' => time()]);
        $this->actingAsAdmin();

        $page = $this->postJson($this->adminPath('/recharge/fetch'), ['email' => 'BUYER@example.test'])->assertOk()->json();
        $this->assertSame(1, $page['total']);
        $this->assertSame([$paid->trade_no, 'buyer@example.test', '已到账', $payment->name], [
            $page['data'][0]['trade_no'], $page['data'][0]['user_email'], $page['data'][0]['status_text'], $page['data'][0]['payment_name'],
        ]);
        $this->assertSame(2, $this->postJson($this->adminPath('/recharge/fetch'), [])->json('total'));
        $this->assertSame(1, $this->postJson($this->adminPath('/recharge/fetch'), ['status' => 0])->json('total'));

        $detail = $this->postJson($this->adminPath('/recharge/detail'), ['trade_no' => $paid->trade_no])->assertOk()->json('data');
        $this->assertSame(11000, $detail['ledger'][0]['amount']);

        $stats = $this->getJson($this->adminPath('/recharge/stats'))->assertOk()->json('data');
        $this->assertSame(['count' => 1, 'deposit_amount' => 10000, 'bonus_amount' => 1000, 'users' => 1], $stats['today']);
        $this->assertSame(1, $stats['pending_count']);
    }

    public function test_admin_can_confirm_pending_or_cancelled_recharges_once(): void
    {
        $user = $this->user();
        $pending = $this->recharge($user);
        $cancelled = $this->recharge($user, ['status' => RechargeOrder::STATUS_CANCELLED, 'deposit_amount' => 2000, 'bonus_amount' => 0, 'total_credit' => 2000, 'pay_amount' => 2000]);
        $admin = $this->actingAsAdmin();

        $this->postJson($this->adminPath('/recharge/paid'), ['trade_no' => $pending->trade_no])->assertOk();
        $this->postJson($this->adminPath('/recharge/paid'), ['trade_no' => $pending->trade_no])->assertStatus(400);
        $this->postJson($this->adminPath('/recharge/paid'), ['trade_no' => $cancelled->trade_no])->assertOk();

        $this->assertSame(13000, (int) $user->fresh()->balance);
        $this->assertSame(RechargeOrder::MANUAL_CALLBACK_NO, $pending->fresh()->callback_no);
        $logs = BalanceLog::where('user_id', $user->id)->orderBy('id')->get();
        $this->assertSame([$admin->id, $admin->id], $logs->pluck('operator_id')->map(fn ($v) => (int) $v)->all());
        $this->assertStringContainsString('管理员确认到账', $logs[0]->remark);
    }

    public function test_admin_cancel_only_touches_pending_recharges(): void
    {
        $user = $this->user();
        $pending = $this->recharge($user);
        $done = $this->recharge($user, ['status' => RechargeOrder::STATUS_COMPLETED, 'paid_at' => time()]);
        $this->actingAsAdmin();

        $this->postJson($this->adminPath('/recharge/cancel'), ['trade_no' => $pending->trade_no])->assertOk();
        $this->postJson($this->adminPath('/recharge/cancel'), ['trade_no' => $done->trade_no])->assertStatus(400);
        $this->assertSame([RechargeOrder::STATUS_CANCELLED, RechargeOrder::STATUS_COMPLETED], [(int) $pending->fresh()->status, (int) $done->fresh()->status]);
    }

    public function test_admin_settings_validate_and_round_trip_recharge_config(): void
    {
        $this->actingAsAdmin();

        $this->postJson($this->adminPath('/config/save'), ['recharge_min_amount' => 50, 'recharge_max_amount' => 10])
            ->assertStatus(422);
        $this->postJson($this->adminPath('/config/save'), ['recharge_bonus_tiers' => [['amount' => 10, 'bonus' => 100]]])
            ->assertStatus(422);
        $this->postJson($this->adminPath('/config/save'), ['recharge_quick_amounts' => [1.234]])
            ->assertStatus(422);

        $this->postJson($this->adminPath('/config/save'), [
            'recharge_enable' => 1, 'recharge_min_amount' => 5, 'recharge_max_amount' => 2000,
            'recharge_bonus_tiers' => [['amount' => 200, 'bonus' => 20.5]], 'recharge_quick_amounts' => [50, 10, 50],
        ])->assertOk();

        $saved = $this->getJson($this->adminPath('/config/fetch?key=recharge'))->assertOk()->json('data.recharge');
        $this->assertEquals([
            'recharge_enable' => true, 'recharge_min_amount' => 5, 'recharge_max_amount' => 2000,
            'recharge_bonus_tiers' => [['amount' => 200, 'bonus' => 20.5]], 'recharge_quick_amounts' => [10, 50],
        ], $saved);
    }

    // ───────────────────────── 生命周期 / 统计 ─────────────────────────

    public function test_check_order_cancels_recharges_left_unpaid_too_long(): void
    {
        $user = $this->user();
        $stale = $this->recharge($user, ['created_at' => time() - RechargeService::PENDING_CANCEL_AFTER - 1]);
        $fresh = $this->recharge($user, ['created_at' => time() - 60]);

        $this->artisan('check:order')->assertSuccessful();

        $this->assertSame(RechargeOrder::STATUS_CANCELLED, (int) $stale->fresh()->status);
        $this->assertSame(RechargeOrder::STATUS_PENDING, (int) $fresh->fresh()->status);
    }

    public function test_income_statistics_include_recharge_cash_but_not_bonus(): void
    {
        $user = $this->user();
        $this->recharge($user, ['status' => RechargeOrder::STATUS_COMPLETED, 'paid_at' => time()]);
        $this->recharge($user, ['status' => RechargeOrder::STATUS_CANCELLED]);
        $this->actingAsAdmin();

        $income = $this->getJson($this->adminPath('/stat/getDashboard'))->assertOk()->json('data.income');
        $this->assertSame(['amount' => 10000, 'paid_count' => 1, 'zero_count' => 0], $income['today']);
        $this->assertSame([['type' => RechargeOrder::LEGACY_ORDER_TYPE, 'label' => '余额充值', 'count' => 1, 'amount' => 10000]], $income['today_by_type']);

        Redis::shouldReceive('connection')->andReturn(new \stdClass()); // 构造函数会连 redis，这里只用到 DB 统计
        $service = new StatisticalService();
        $service->setStartAt(strtotime('today'));
        $service->setEndAt(strtotime('tomorrow'));
        $data = $service->generateStatData();
        $this->assertSame([10000, 1], [(int) $data['paid_total'], (int) $data['paid_count']]);
    }

    // ───────────────────────── 迁移 / 插件 / 回填 ─────────────────────────

    public function test_migration_renames_the_plugin_table_and_moves_its_settings(): void
    {
        Schema::drop('v2_recharge_order');
        Schema::create('plugin_recharge_orders', function ($table) {
            $table->id();
            $table->integer('user_id')->index();
            $table->string('trade_no', 64)->unique();
            $table->integer('deposit_amount');
            $table->integer('bonus_amount')->default(0);
            $table->integer('total_credit');
            $table->integer('pay_amount');
            $table->integer('handling_amount')->nullable();
            $table->integer('payment_id')->nullable();
            $table->string('payment_method', 64)->nullable();
            $table->tinyInteger('status')->default(0);
            $table->string('callback_no', 255)->nullable();
            $table->integer('invite_user_id')->nullable();
            $table->integer('commission_balance')->default(0);
            $table->integer('paid_at')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
        DB::table('plugin_recharge_orders')->insert(['id' => 42, 'user_id' => 1, 'trade_no' => 'T42', 'deposit_amount' => 100, 'total_credit' => 100, 'pay_amount' => 100, 'status' => 3, 'created_at' => 1, 'updated_at' => 1]);
        DB::table('v2_settings')->whereIn('name', ['recharge_enable', 'recharge_min_amount', 'recharge_max_amount', 'recharge_bonus_tiers'])->delete();
        DB::table('v2_plugins')->insert([
            'name' => '余额充值', 'code' => 'balance_recharge', 'version' => '1.2.1', 'is_enabled' => 1, 'type' => 'feature',
            'config' => json_encode(['enabled' => true, 'min_amount' => 2, 'max_amount' => 800, 'deposit_bonus' => ['100:10', 'bad', '300: 45']]),
        ]);
        admin_setting(['recharge_quick_amounts' => []]); // 触发设置缓存刷新

        $migration = require base_path('database/migrations/2026_10_25_000001_promote_balance_recharge_to_core.php');
        $migration->up();

        $this->assertFalse(Schema::hasTable('plugin_recharge_orders'));
        $this->assertSame('T42', RechargeOrder::findOrFail(42)->trade_no, 'id 不变');
        $this->assertTrue(Schema::hasColumn('v2_payment_callback', 'subject_type'));
        $this->assertFalse(DB::table('v2_plugins')->where('code', 'balance_recharge')->exists());
        $config = RechargeConfig::fromSettings();
        $this->assertSame([true, 200, 80000], [$config->enable, $config->minAmount, $config->maxAmount]);
        $this->assertSame([['amount' => 10000, 'bonus' => 1000], ['amount' => 30000, 'bonus' => 4500]], $config->tiers);

        $migration->up(); // 再跑一次不出错、不覆盖
        $this->assertSame(200, RechargeConfig::fromSettings()->minAmount);
    }

    public function test_the_absorbed_plugin_cannot_come_back(): void
    {
        $manager = app(PluginManager::class);
        foreach (['install', 'enable'] as $method) {
            try {
                $manager->{$method}('balance_recharge');
                $this->fail("{$method} 应被拒绝");
            } catch (\Exception $e) {
                $this->assertStringContainsString('已并入系统', $e->getMessage());
            }
        }
    }

    public function test_backfill_relabels_only_unambiguous_legacy_ledger_rows(): void
    {
        $user = $this->user();
        $paidAt = time() - 3600;
        $recharge = $this->recharge($user, ['status' => RechargeOrder::STATUS_COMPLETED, 'paid_at' => $paidAt]);
        $matching = BalanceLog::create(['user_id' => $user->id, 'type' => BalanceLog::TYPE_ADMIN_ADJUST, 'amount' => 11000, 'balance_before' => 0, 'balance_after' => 11000, 'created_at' => $paidAt]);
        $manual = BalanceLog::create(['user_id' => $user->id, 'type' => BalanceLog::TYPE_ADMIN_ADJUST, 'amount' => 11000, 'balance_before' => 11000, 'balance_after' => 22000, 'operator_id' => 1, 'ref_type' => 'admin', 'created_at' => $paidAt + 1]);
        $unrelated = BalanceLog::create(['user_id' => $user->id, 'type' => BalanceLog::TYPE_ADMIN_ADJUST, 'amount' => 11000, 'balance_before' => 22000, 'balance_after' => 33000, 'created_at' => $paidAt + 7200]);

        $this->artisan('recharge:backfill', ['--ledger' => true, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(BalanceLog::TYPE_ADMIN_ADJUST, $matching->fresh()->type, 'dry-run 不写库');

        $this->artisan('recharge:backfill', ['--ledger' => true])->assertSuccessful();
        $this->artisan('recharge:backfill', ['--ledger' => true])->assertSuccessful();
        $matching->refresh();
        $this->assertSame([BalanceLog::TYPE_RECHARGE, 'recharge', $recharge->trade_no, '充值 ¥100.00，赠送 ¥10.00'], [$matching->type, $matching->ref_type, $matching->ref_id, $matching->remark]);
        $this->assertSame(BalanceLog::TYPE_ADMIN_ADJUST, $manual->fresh()->type, '后台手工调整不动');
        $this->assertSame(BalanceLog::TYPE_ADMIN_ADJUST, $unrelated->fresh()->type, '时间对不上的不动');
    }

    public function test_backfill_adds_recharge_income_to_old_daily_stats_once_and_leaves_orders_alone(): void
    {
        $user = $this->user();
        $day = strtotime('yesterday');
        $this->recharge($user, ['status' => RechargeOrder::STATUS_COMPLETED, 'paid_at' => $day + 3600]);
        $this->recharge($user, ['status' => RechargeOrder::STATUS_CANCELLED, 'created_at' => $day + 60]);
        // 当时记下的订单收入 5000 / 2 笔；之后这些订单被删号收回了，现在库里一张都没有——补充值时不能把它们减掉
        Stat::create(['record_at' => $day, 'record_type' => 'd', 'order_count' => 2, 'order_total' => 5000, 'paid_count' => 2, 'paid_total' => 5000, 'commission_count' => 0, 'commission_total' => 0, 'register_count' => 0, 'invite_count' => 0, 'transfer_used_total' => '0']);
        $quiet = Stat::create(['record_at' => strtotime('-2 day', $day), 'record_type' => 'd', 'order_count' => 1, 'order_total' => 700, 'paid_count' => 1, 'paid_total' => 700, 'commission_count' => 0, 'commission_total' => 0, 'register_count' => 0, 'invite_count' => 0, 'transfer_used_total' => '0']);

        $this->artisan('recharge:backfill', ['--stats' => true, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(5000, (int) Stat::where('record_at', $day)->value('paid_total'), 'dry-run 不写库');

        $this->artisan('recharge:backfill', ['--stats' => true])->assertSuccessful();
        // 模拟别的进程在写入提交前重建了设置缓存：缓存里没有标记，第二次仍必须跳过
        $stale = Cache::store('redis')->get(\App\Support\Setting::CACHE_KEY, []);
        unset($stale['recharge_stats_backfilled_before']);
        Cache::store('redis')->forever(\App\Support\Setting::CACHE_KEY, $stale);
        $this->artisan('recharge:backfill', ['--stats' => true])->assertSuccessful();

        $stat = Stat::where('record_at', $day)->first();
        $this->assertSame([15000, 3], [(int) $stat->paid_total, (int) $stat->paid_count], '只加一次充值，订单部分照旧');
        $this->assertSame([700, 1], [(int) $quiet->fresh()->paid_total, (int) $quiet->fresh()->paid_count]);
    }
}
