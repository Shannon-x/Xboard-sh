<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\RechargeOrder;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\PaymentService;
use App\Services\Recharge\RechargeService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 通道单笔上限：支付配置 max_amount（元，含手续费）。超过的单在绑定支付方式前拒绝、不去网关下单，
 * 收银台接口下发上限（分）让前端置灰，配置本身不下发。
 */
class PaymentMaxAmountTest extends TestCase
{
    use RefreshDatabase;

    private int $gatewayCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $calls = &$this->gatewayCalls;
        app()->instance(CheckoutService::class, new class ($calls) extends CheckoutService {
            public function __construct(private int &$calls)
            {
            }

            protected function gateway(Payment $payment): PaymentService
            {
                $calls = &$this->calls;
                return new class ($calls) extends PaymentService {
                    public function __construct(private int &$calls)
                    {
                    }

                    public function pay($order)
                    {
                        $this->calls++;
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

    private function alipay(?string $max = '495'): Payment
    {
        return Payment::create([
            'uuid' => Str::random(8), 'payment' => 'EPay', 'name' => '支付宝支付', 'enable' => true,
            'handling_fee_percent' => 4, 'handling_fee_fixed' => 0,
            'config' => ['url' => 'https://pay.test', 'pid' => '1', 'key' => 'secret-key', 'max_amount' => $max],
        ]);
    }

    private function order(User $user, int $amount): Order
    {
        $plan = Plan::create(['name' => '年付', 'group_id' => 1, 'show' => true, 'sell' => true, 'renew' => true, 'transfer_enable' => 100, 'prices' => ['yearly' => $amount / 100]]);
        return Order::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'period' => 'yearly', 'type' => Order::TYPE_NEW_PURCHASE,
            'trade_no' => Helper::generateOrderNo(), 'total_amount' => $amount, 'status' => Order::STATUS_PENDING,
        ]);
    }

    public function test_order_over_the_limit_including_fee_is_rejected_before_binding(): void
    {
        $payment = $this->alipay();
        $order = $this->order($this->user(), 47700); // ¥477 + 4% = ¥496.08 > ¥495

        try {
            app(CheckoutService::class)->checkout($order, $payment);
            $this->fail('超限应当拒绝');
        } catch (ApiException $e) {
            $this->assertSame('支付宝支付单笔限额 ¥495，请使用其他支付方式', $e->getMessage());
        }
        $this->assertSame(0, $this->gatewayCalls, '不去网关下单');
        $this->assertNull($order->fresh()->payment_id, '没有绑定，客户还能换别的方式');
    }

    public function test_order_within_the_limit_goes_through(): void
    {
        $payment = $this->alipay();
        $order = $this->order($this->user(), 47500); // ¥475 + ¥19 = ¥494

        $result = app(CheckoutService::class)->checkout($order, $payment);
        $this->assertSame('https://pay.test/submit', $result['data']);
        $this->assertSame(1, $this->gatewayCalls);
        $this->assertSame(1900, (int) $order->fresh()->handling_amount);
    }

    public function test_blank_limit_means_unlimited(): void
    {
        $payment = $this->alipay('');
        $order = $this->order($this->user(), 90000);
        app(CheckoutService::class)->checkout($order, $payment);
        $this->assertSame(1, $this->gatewayCalls);
        $this->assertNull(CheckoutService::maxAmount($payment));
    }

    public function test_recharge_over_the_limit_is_rejected_before_binding(): void
    {
        admin_setting(['recharge_enable' => 1, 'recharge_min_amount' => 1, 'recharge_max_amount' => 5000]);
        $payment = $this->alipay('100');
        $service = app(RechargeService::class);
        $recharge = $service->create($this->user(), 10000); // ¥100 + ¥4 > ¥100

        try {
            $service->checkout($recharge, $payment);
            $this->fail('超限应当拒绝');
        } catch (ApiException $e) {
            $this->assertStringContainsString('单笔限额 ¥100', $e->getMessage());
        }
        $this->assertSame(0, $this->gatewayCalls);
        $this->assertNull(RechargeOrder::find($recharge->id)->payment_id);
    }

    public function test_cashier_lists_the_limit_in_cents_without_exposing_config(): void
    {
        $this->alipay('495.5');
        Sanctum::actingAs($this->user());

        $response = $this->getJson('/api/v1/user/order/getPaymentMethod')->assertOk();
        $method = $response->json('data.0');
        $this->assertSame(49550, $method['max_amount']);
        $this->assertArrayNotHasKey('config', $method);
        $this->assertStringNotContainsString('secret-key', $response->getContent());
    }
}
