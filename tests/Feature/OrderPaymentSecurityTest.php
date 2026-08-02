<?php

namespace Tests\Feature;

use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use App\Support\PaymentGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Plugin\Epay\Plugin as EpayPlugin;
use Tests\TestCase;

class OrderPaymentSecurityTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    private function makeUser(array $overrides = []): User
    {
        $this->sequence++;
        return User::create(array_merge([
            'email' => "order-security-{$this->sequence}@example.com",
            'password' => 'x',
            'uuid' => Str::uuid()->toString(),
            'token' => Str::random(32),
            'balance' => 0,
            'transfer_enable' => 0,
            'expired_at' => 0,
        ], $overrides));
    }

    private function makeOrder(User $user, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'user_id' => $user->id,
            'plan_id' => 1,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => Str::upper(Str::random(16)),
            'total_amount' => 1000,
            'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_PENDING,
        ], $overrides));
    }

    private function makePayment(string $method = 'EPay'): Payment
    {
        return Payment::create([
            'uuid' => Str::random(32),
            'payment' => $method,
            'name' => $method . ' Test',
            'config' => [],
            'enable' => true,
        ]);
    }

    public function test_stale_cancel_models_refund_balance_only_once(): void
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user, ['balance_amount' => 500]);

        // 两个请求都在第一次取消提交前读到了 PENDING，复现原漏洞的旧模型条件。
        $requestOne = Order::findOrFail($order->id);
        $requestTwo = Order::findOrFail($order->id);

        $this->assertTrue((new OrderService($requestOne))->cancel());
        $this->assertFalse((new OrderService($requestTwo))->cancel());
        $this->assertSame(Order::STATUS_CANCELLED, (int) $order->fresh()->status);
        $this->assertSame(500, (int) $user->fresh()->balance);
    }

    public function test_paid_rechecks_gateway_under_order_lock(): void
    {
        Bus::fake([OrderHandleJob::class]);
        $user = $this->makeUser();
        $bound = $this->makePayment();
        $other = $this->makePayment();
        $order = $this->makeOrder($user, ['payment_id' => $bound->id]);

        $this->assertFalse((new OrderService($order))->paid('gateway-txn-1', $other->id, true));
        $this->assertSame(Order::STATUS_PENDING, (int) $order->fresh()->status);
        $this->assertDatabaseCount('v2_payment_callback', 0);
    }

    public function test_gateway_callback_cannot_be_bound_to_two_orders(): void
    {
        Bus::fake([OrderHandleJob::class]);
        $payment = $this->makePayment();
        $first = $this->makeOrder($this->makeUser(), ['payment_id' => $payment->id]);
        $second = $this->makeOrder($this->makeUser(), ['payment_id' => $payment->id]);

        $this->assertTrue((new OrderService($first))->paid('gateway-txn-reused', $payment->id, true));
        $this->assertFalse((new OrderService($second))->paid('gateway-txn-reused', $payment->id, true));

        $this->assertSame(Order::STATUS_PROCESSING, (int) $first->fresh()->status);
        $this->assertSame(Order::STATUS_PENDING, (int) $second->fresh()->status);
        $this->assertDatabaseCount('v2_payment_callback', 1);
    }

    public function test_paid_does_not_ack_cancelled_order_as_duplicate(): void
    {
        Bus::fake([OrderHandleJob::class]);
        $payment = $this->makePayment();
        $order = $this->makeOrder($this->makeUser(), [
            'payment_id' => $payment->id,
            'status' => Order::STATUS_CANCELLED,
        ]);

        $this->assertFalse((new OrderService($order))->paid('late-race-txn', $payment->id, true));
        $this->assertSame(Order::STATUS_CANCELLED, (int) $order->fresh()->status);
    }

    public function test_payment_guard_requires_exact_strict_positive_amount(): void
    {
        $order = $this->makeOrder($this->makeUser(), [
            'total_amount' => 1234,
            'handling_amount' => 66,
        ]);

        $this->assertSame(1300, PaymentGuard::decimalToMinor('13.00'));
        $this->assertSame(1300, PaymentGuard::decimalToMinor('13'));
        $this->assertNull(PaymentGuard::decimalToMinor('13.000'));
        $this->assertNull(PaymentGuard::decimalToMinor('1.3e1'));
        $this->assertNull(PaymentGuard::decimalToMinor(13.0));
        $this->assertTrue(PaymentGuard::ensureAmount('test', $order->trade_no, 1300, 'enforce'));
        $this->assertFalse(PaymentGuard::ensureAmount('test', $order->trade_no, 1299, 'enforce'));
        $this->assertFalse(PaymentGuard::ensureAmount('test', $order->trade_no, 1301, 'enforce'));
        $this->assertFalse(PaymentGuard::ensureAmount('test', $order->trade_no, null, 'enforce'));
    }

    public function test_epay_requires_success_status_pid_sign_type_and_exact_amount(): void
    {
        $order = $this->makeOrder($this->makeUser(), [
            'total_amount' => 1200,
            'handling_amount' => 100,
        ]);
        $plugin = new EpayPlugin('epay');
        $plugin->setConfig(['key' => 'epay-secret', 'pid' => 'merchant-1']);

        $valid = [
            'pid' => 'merchant-1',
            'out_trade_no' => $order->trade_no,
            'trade_no' => 'EPAY-TXN-1',
            'trade_status' => 'TRADE_SUCCESS',
            'money' => '13.00',
        ];

        $this->assertIsArray($plugin->notify($this->signEpay($valid)));
        $this->assertFalse($plugin->notify($this->signEpay(array_diff_key($valid, ['trade_status' => true]))));
        $this->assertFalse($plugin->notify($this->signEpay(array_merge($valid, ['pid' => 'merchant-2']))));
        $this->assertFalse($plugin->notify($this->signEpay(array_merge($valid, ['money' => '0.01']))));

        $withoutSignType = $this->signEpay($valid);
        unset($withoutSignType['sign_type']);
        $this->assertFalse($plugin->notify($withoutSignType));
    }

    private function signEpay(array $params): array
    {
        ksort($params);
        $params['sign'] = md5(stripslashes(urldecode(http_build_query($params))) . 'epay-secret');
        $params['sign_type'] = 'MD5';
        return $params;
    }
}
