<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramJob;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\RechargeOrder;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\PaymentService;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 支付会话（PaymentAttempt 插件，私有仓库）接入核心收银台：中途换支付方式、会话号回跳、
 * 旧通道到账认领、二次到账退余额。套餐订单与余额充值单走同一套规则。
 *
 * 插件不在 plugins/ 下时（公开仓库的 CI）整组跳过；本地把插件目录放进 plugins/PaymentAttempt 即可跑。
 */
class PaymentSessionCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private array $payCalls = [];
    private array|bool $verify = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(\Plugin\PaymentAttempt\Support\Attempts::class)) {
            $this->markTestSkipped('PaymentAttempt 插件不在 plugins/ 下');
        }
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        app()->forgetScopedInstances();
        Bus::fake([SendTelegramJob::class]);
        // 绑了 Telegram 的管理员：告警才会真的派发 SendTelegramJob
        User::create([
            'email' => 'admin@example.test', 'password' => 'x', 'uuid' => (string) Str::uuid(),
            'token' => Str::random(32), 'is_admin' => 1, 'telegram_id' => 10001,
        ]);

        Artisan::call('migrate', ['--path' => 'plugins/PaymentAttempt/database/migrations', '--force' => true]);
        DB::table('v2_plugins')->insert([
            'name' => '支付会话', 'code' => 'payment_attempt', 'version' => '1.0.0', 'is_enabled' => 1, 'type' => 'feature',
            'config' => json_encode(['enabled' => true, 'duplicate_refund' => true]),
        ]);
        admin_setting(['recharge_enable' => 1, 'recharge_min_amount' => 1, 'recharge_max_amount' => 5000]);

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

    private function user(): User
    {
        return User::create([
            'email' => Str::random(10) . '@example.test', 'password' => 'x', 'uuid' => (string) Str::uuid(),
            'token' => Str::random(32), 'balance' => 0, 'transfer_enable' => 0,
        ]);
    }

    private function payment(string $method, array $config): Payment
    {
        return Payment::create(['uuid' => Str::random(8), 'payment' => $method, 'name' => $method, 'config' => $config, 'enable' => true]);
    }

    private function fakeNotifyPlugins(): void
    {
        $test = $this;
        $plugin = new class ($test) {
            public array $config = [];
            public function __construct(private PaymentSessionCheckoutTest $test) {}
            public function getPluginCode(): string { return 'gw_test'; }
            public function setConfig(array $config): void { $this->config = $config; }
            public function notify($params) { return $this->test->verifyResult(); }
        };
        HookManager::registerFilter('available_payment_methods', fn ($m) => $m + ['EPay' => ['plugin_code' => 'gw_test'], 'BEpusdt' => ['plugin_code' => 'gw_test']]);
        app()->instance(PluginManager::class, new class ($plugin) extends PluginManager {
            public function __construct(private object $fake) { parent::__construct(); }
            public function getEnabledPaymentPlugins(): array { return [$this->fake]; }
        });
    }

    public function verifyResult(): array|bool
    {
        return $this->verify;
    }

    /** 网关插件认领会话后交给核心的回调结果（EPay / BEpusdt 插件在 notify() 里调 Attempts::claim） */
    private function claimAndNotify(Payment $payment, string $ref, string $callbackNo, int $paid)
    {
        $claim = \Plugin\PaymentAttempt\Support\Attempts::claim($ref, (int) $payment->id, $callbackNo, $paid);
        $this->assertSame('ok', $claim['outcome'], (string) $claim['reason']);
        $this->verify = ['trade_no' => $claim['trade_no'], 'callback_no' => $callbackNo, 'paid_amount' => $paid, 'attempt_ref' => $ref];
        return $this->post("/api/v1/guest/payment/notify/{$payment->payment}/{$payment->uuid}", []);
    }

    public function test_order_checkout_sends_a_session_ref_and_may_switch_merchant(): void
    {
        $user = $this->user();
        $plan = Plan::create(['name' => '月付', 'group_id' => 1, 'show' => true, 'sell' => true, 'renew' => true, 'transfer_enable' => 100, 'prices' => ['monthly' => 10]]);
        $order = Order::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'period' => 'monthly', 'type' => Order::TYPE_NEW_PURCHASE,
            'trade_no' => Helper::generateOrderNo(), 'total_amount' => 1000, 'status' => Order::STATUS_PENDING,
        ]);
        $epay = $this->payment('EPay', ['url' => 'https://pay.test', 'pid' => '1', 'key' => 'a']);
        $usdt = $this->payment('BEpusdt', ['url' => 'https://usdt.test', 'token' => 't']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/user/order/checkout', ['trade_no' => $order->trade_no, 'method' => $epay->id])->assertOk();
        $this->postJson('/api/v1/user/order/checkout', ['trade_no' => $order->trade_no, 'method' => $usdt->id])->assertOk();

        $this->assertMatchesRegularExpression('/^' . $order->trade_no . '-[a-z0-9]{8}$/', $this->payCalls[0]['trade_no']);
        $this->assertNotSame($this->payCalls[0]['trade_no'], $this->payCalls[1]['trade_no']);
        $this->assertStringEndsWith('/#/order/' . $order->trade_no, $this->payCalls[1]['return_url'], '回跳用真实单号');
        $this->assertSame($usdt->id, (int) $order->fresh()->payment_id, '会话模式下跨商户切换并改绑');

        // 前端拿会话号来查也认得
        $this->getJson('/api/v1/user/order/detail?trade_no=' . $this->payCalls[0]['trade_no'])
            ->assertOk()->assertJsonPath('data.trade_no', $order->trade_no);
    }

    public function test_recharge_paid_through_the_old_channel_after_switching_is_credited(): void
    {
        $this->fakeNotifyPlugins();
        $user = $this->user();
        $epay = $this->payment('EPay', ['url' => 'https://pay.test', 'pid' => '1', 'key' => 'a']);
        $usdt = $this->payment('BEpusdt', ['url' => 'https://usdt.test', 'token' => 't']);
        Sanctum::actingAs($user);

        $tradeNo = $this->postJson('/api/v1/user/order/save', ['plan_id' => 0, 'period' => 'deposit', 'deposit_amount' => 3000])->json('data');
        $this->postJson('/api/v1/user/order/checkout', ['trade_no' => $tradeNo, 'method' => $epay->id])->assertOk();
        $this->postJson('/api/v1/user/order/checkout', ['trade_no' => $tradeNo, 'method' => $usdt->id])->assertOk();
        [$epayRef, $usdtRef] = [$this->payCalls[0]['trade_no'], $this->payCalls[1]['trade_no']];
        $this->assertSame($usdt->id, (int) RechargeOrder::where('trade_no', $tradeNo)->value('payment_id'));

        // 用户最后付的是旧的易支付链接：充值单已改绑到 BEpusdt，但会话认得这笔钱
        $this->claimAndNotify($epay, $epayRef, 'EP-1', 3000)->assertOk();
        $this->assertSame(3000, (int) $user->fresh()->balance);
        $recharge = RechargeOrder::where('trade_no', $tradeNo)->first();
        $this->assertSame(RechargeOrder::STATUS_COMPLETED, (int) $recharge->status);
        $this->assertSame($epay->id, (int) $recharge->payment_id, '入账时改绑回真正收到钱的通道');

        // 同一条会话又来一笔不同流水：不当成重投放过，转人工
        $this->claimAndNotify($epay, $epayRef, 'EP-2', 3000)->assertOk();
        $this->assertSame(3000, (int) $user->fresh()->balance);
        Bus::assertDispatched(SendTelegramJob::class, fn ($job) => str_contains(serialize($job), '充值单重复付款需人工处理'));

        // 两条链接都付了：第二笔按实付额退回余额，不重复给赠送
        $this->claimAndNotify($usdt, $usdtRef, 'US-1', 3000)->assertOk();
        $this->assertSame(6000, (int) $user->fresh()->balance);

        // 重投不会再退
        $this->claimAndNotify($usdt, $usdtRef, 'US-1', 3000)->assertOk();
        $this->assertSame(6000, (int) $user->fresh()->balance);
    }

    public function test_switching_away_from_a_gateway_whose_links_carry_the_raw_trade_no_is_refused(): void
    {
        $user = $this->user();
        $plan = Plan::create(['name' => '月付', 'group_id' => 1, 'show' => true, 'sell' => true, 'renew' => true, 'transfer_enable' => 100, 'prices' => ['monthly' => 10]]);
        $f2f = $this->payment('AlipayF2f', ['app_id' => 'a', 'private_key' => 'p']);
        $epay = $this->payment('EPay', ['url' => 'https://pay.test', 'pid' => '1', 'key' => 'a']);
        $order = Order::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'period' => 'monthly', 'type' => Order::TYPE_NEW_PURCHASE,
            'trade_no' => Helper::generateOrderNo(), 'total_amount' => 1000, 'status' => Order::STATUS_PENDING, 'payment_id' => $f2f->id,
        ]);
        Sanctum::actingAs($user);

        // 当面付的收款码带的是订单号本身：改绑到易支付后再扫旧码付款，回调会被拒，钱进商户号订单不开通
        $this->postJson('/api/v1/user/order/checkout', ['trade_no' => $order->trade_no, 'method' => $epay->id])
            ->assertStatus(400)->assertJsonFragment(['message' => '订单已绑定其他支付方式，请取消订单后重新下单']);
        $this->assertSame($f2f->id, (int) $order->fresh()->payment_id);
    }
}
