<?php

namespace Tests\Feature;

use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use App\Support\PaymentGatewayBinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 回归测试：支付回调「网关绑定」的同源判定。
 *
 * 背景（2026-08-03 生产）：面板里「支付宝支付」「微信支付」是同一个易支付商户的两条
 * v2_payment 记录，url + pid + key 完全相同。用户在收银台切换按钮时订单绑定的
 * payment_id 与他实际付款的收款会话错位，合法回调被「payment_id 必须精确相等」的闸门
 * 连拒 6 次，订单晚开通 2 分钟；两周内 155 单命中同一形态。
 *
 * 本测试锁定两侧边界：
 *   - 放行：同插件 + 同一套凭证的多条配置互为同源，回调必须被接受（含迟到支付复活）；
 *   - 拒收：密钥 / 商户号 / 回调域名任一不同，或跨插件，或 config 无从判定，一律拒收
 *     ——即「仅凭插件类相同就等价」的旧放宽（PR #13，#15 已收回）不得复活。
 */
class PaymentGatewayBindingTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    /** 生产上那对同商户双通道配置的等价形态：url/pid/key 相同，只有名字与预选通道不同。 */
    private const MERCHANT_CONFIG = [
        'url' => 'https://gateway.example.com/',
        'pid' => '80657',
        'key' => '5e39ec1f594d8b5d69862d8e5b92ad02',
    ];

    private function makePayment(array $config, string $method = 'EPay', ?string $name = null): Payment
    {
        $this->sequence++;
        return Payment::create([
            'uuid' => Str::random(32),
            'payment' => $method,
            'name' => $name ?? "{$method} #{$this->sequence}",
            'config' => $config,
            'enable' => true,
        ]);
    }

    private function makeUser(): User
    {
        $this->sequence++;
        return User::create([
            'email' => "gateway-binding-{$this->sequence}@example.com",
            'password' => 'x',
            'uuid' => Str::uuid()->toString(),
            'token' => Str::random(32),
            'balance' => 0,
            'transfer_enable' => 0,
            'expired_at' => 0,
            'plan_id' => null,
            'group_id' => null,
        ]);
    }

    private function makeOrder(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'user_id' => $this->makeUser()->id,
            'plan_id' => 1,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => Str::upper(Str::random(16)),
            'total_amount' => 1000,
            'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_PENDING,
        ], $overrides));
    }

    // ---- 放行：同商户多通道 ----

    public function test_same_payment_id_is_equivalent(): void
    {
        $payment = $this->makePayment(self::MERCHANT_CONFIG);

        $this->assertTrue(PaymentGatewayBinding::equivalent($payment->id, $payment->id));
    }

    public function test_same_merchant_different_display_records_are_equivalent(): void
    {
        // 生产形态：同一商户配成「支付宝支付」「微信支付」两条记录
        $alipay = $this->makePayment(self::MERCHANT_CONFIG, 'EPay', '支付宝支付');
        $wechat = $this->makePayment(self::MERCHANT_CONFIG, 'EPay', '微信支付');

        $this->assertNotSame($alipay->id, $wechat->id);
        $this->assertTrue(PaymentGatewayBinding::equivalent($alipay->id, $wechat->id));
        $this->assertTrue(PaymentGatewayBinding::equivalent($wechat->id, $alipay->id));
    }

    public function test_preselected_channel_does_not_break_equivalence(): void
    {
        // 管理员把两条记录的 type 分别设成 alipay / wxpay 预选通道后，商户与密钥并未改变，
        // 仍须视为同源——否则「切过按钮的用户」会再次被拒收。
        $alipay = $this->makePayment(self::MERCHANT_CONFIG + ['type' => 'alipay']);
        $wechat = $this->makePayment(self::MERCHANT_CONFIG + ['type' => 'wxpay']);
        $unset = $this->makePayment(self::MERCHANT_CONFIG + ['type' => null]);

        $this->assertTrue(PaymentGatewayBinding::equivalent($alipay->id, $wechat->id));
        $this->assertTrue(PaymentGatewayBinding::equivalent($alipay->id, $unset->id));
    }

    public function test_config_key_order_does_not_affect_fingerprint(): void
    {
        $a = $this->makePayment(['url' => 'https://g.example/', 'pid' => '1', 'key' => 'k']);
        $b = $this->makePayment(['key' => 'k', 'url' => 'https://g.example/', 'pid' => '1']);

        $this->assertTrue(PaymentGatewayBinding::equivalent($a->id, $b->id));
    }

    public function test_nested_config_key_order_does_not_affect_fingerprint(): void
    {
        $a = $this->makePayment(['key' => 'k', 'extra' => ['b' => 2, 'a' => 1]]);
        $b = $this->makePayment(['key' => 'k', 'extra' => ['a' => 1, 'b' => 2]]);

        $this->assertTrue(PaymentGatewayBinding::equivalent($a->id, $b->id));
    }

    // ---- 拒收：不同商户 / 不同插件 / 无从判定 ----

    public function test_different_signing_key_is_not_equivalent(): void
    {
        // 核心安全断言：密钥不同 = 不同商户，攻击者掌握的网关不得用来翻转他人订单
        $mine = $this->makePayment(self::MERCHANT_CONFIG);
        $attacker = $this->makePayment(array_merge(self::MERCHANT_CONFIG, ['key' => 'attacker-key']));

        $this->assertFalse(PaymentGatewayBinding::equivalent($mine->id, $attacker->id));
    }

    public function test_different_merchant_id_is_not_equivalent(): void
    {
        $mine = $this->makePayment(self::MERCHANT_CONFIG);
        $other = $this->makePayment(array_merge(self::MERCHANT_CONFIG, ['pid' => '99999']));

        $this->assertFalse(PaymentGatewayBinding::equivalent($mine->id, $other->id));
    }

    public function test_different_gateway_url_is_not_equivalent(): void
    {
        $mine = $this->makePayment(self::MERCHANT_CONFIG);
        $other = $this->makePayment(array_merge(self::MERCHANT_CONFIG, ['url' => 'https://evil.example/']));

        $this->assertFalse(PaymentGatewayBinding::equivalent($mine->id, $other->id));
    }

    public function test_extra_config_field_is_not_equivalent(): void
    {
        // 失败闭合：未知新字段一律计入指纹，宁可判成不同源
        $mine = $this->makePayment(self::MERCHANT_CONFIG);
        $other = $this->makePayment(self::MERCHANT_CONFIG + ['sub_merchant' => 'x']);

        $this->assertFalse(PaymentGatewayBinding::equivalent($mine->id, $other->id));
    }

    public function test_different_plugin_with_identical_config_is_not_equivalent(): void
    {
        $epay = $this->makePayment(self::MERCHANT_CONFIG, 'EPay');
        $mgate = $this->makePayment(self::MERCHANT_CONFIG, 'Mgate');

        $this->assertFalse(PaymentGatewayBinding::equivalent($epay->id, $mgate->id));
    }

    public function test_empty_config_is_never_equivalent(): void
    {
        // 无从判断商户归属时必须失败闭合，绝不能退化成「都为空所以相等」
        $a = $this->makePayment([]);
        $b = $this->makePayment([]);

        $this->assertFalse(PaymentGatewayBinding::equivalent($a->id, $b->id));
        $this->assertNull(PaymentGatewayBinding::fingerprint($a));
    }

    public function test_config_with_only_non_identity_keys_is_never_equivalent(): void
    {
        $a = $this->makePayment(['type' => 'alipay']);
        $b = $this->makePayment(['type' => 'wxpay']);

        $this->assertFalse(PaymentGatewayBinding::equivalent($a->id, $b->id));
    }

    public function test_null_ids_and_missing_records_are_not_equivalent(): void
    {
        $payment = $this->makePayment(self::MERCHANT_CONFIG);

        $this->assertFalse(PaymentGatewayBinding::equivalent(null, $payment->id));
        $this->assertFalse(PaymentGatewayBinding::equivalent($payment->id, null));
        $this->assertFalse(PaymentGatewayBinding::equivalent(null, null));
        $this->assertFalse(PaymentGatewayBinding::equivalent($payment->id, $payment->id + 9999));
    }

    // ---- paid()：锁内复核走同一套判定 ----

    public function test_paid_accepts_callback_from_same_merchant_alias(): void
    {
        Bus::fake([OrderHandleJob::class]);
        $bound = $this->makePayment(self::MERCHANT_CONFIG, 'EPay', '支付宝支付');
        $collecting = $this->makePayment(self::MERCHANT_CONFIG, 'EPay', '微信支付');
        $order = $this->makeOrder(['payment_id' => $bound->id]);

        $this->assertTrue((new OrderService($order))->paid('alias-txn-1', $collecting->id, true));
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);

        // 台账记录的必须是「真正收款的那条配置」，订单 payment_id 保持 checkout 时的绑定
        // （handling_amount 与之配套，不能事后错位）。
        $this->assertDatabaseHas('v2_payment_callback', [
            'order_id' => $order->id,
            'payment_id' => $collecting->id,
        ]);
        $this->assertSame($bound->id, (int) $order->fresh()->payment_id);
    }

    public function test_paid_still_rejects_callback_from_foreign_merchant(): void
    {
        Bus::fake([OrderHandleJob::class]);
        $bound = $this->makePayment(self::MERCHANT_CONFIG);
        $attacker = $this->makePayment(array_merge(self::MERCHANT_CONFIG, ['key' => 'attacker-key']));
        $order = $this->makeOrder(['payment_id' => $bound->id]);

        $this->assertFalse((new OrderService($order))->paid('forged-txn-1', $attacker->id, true));
        $this->assertSame(Order::STATUS_PENDING, (int) $order->fresh()->status);
        $this->assertDatabaseCount('v2_payment_callback', 0);
    }

    // ---- reopenFromCancelled()：迟到支付复活走同一套判定 ----

    public function test_reopen_accepts_same_merchant_alias(): void
    {
        // 2026-07-14 事故形态 + 切按钮：超时取消后用户才付款，且收款会话属于同商户的
        // 另一条配置。凭证同源即可自动复活，不该一律转人工。
        $bound = $this->makePayment(self::MERCHANT_CONFIG, 'EPay', '支付宝支付');
        $collecting = $this->makePayment(self::MERCHANT_CONFIG, 'EPay', '微信支付');

        $plan = Plan::create([
            'group_id' => 1,
            'transfer_enable' => 100,
            'name' => 'Gateway Binding Plan',
            'speed_limit' => null,
            'device_limit' => null,
            'reset_traffic_method' => 2,
        ]);
        $user = $this->makeUser();
        $order = $this->makeOrder([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'payment_id' => $bound->id,
            'status' => Order::STATUS_CANCELLED,
            'balance_amount' => 0,
            'coupon_id' => null,
        ]);

        $result = (new OrderService($order))->reopenFromCancelled('late-alias-txn', $collecting->id);

        $this->assertSame('reopened', $result);
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
    }

    public function test_reopen_still_rejects_foreign_merchant(): void
    {
        $bound = $this->makePayment(self::MERCHANT_CONFIG);
        $attacker = $this->makePayment(array_merge(self::MERCHANT_CONFIG, ['key' => 'attacker-key']));
        $order = $this->makeOrder([
            'payment_id' => $bound->id,
            'status' => Order::STATUS_CANCELLED,
        ]);

        $result = (new OrderService($order))->reopenFromCancelled('forged-late-txn', $attacker->id);

        $this->assertSame('manual', $result);
        $this->assertSame(Order::STATUS_CANCELLED, (int) $order->fresh()->status);
    }
}
