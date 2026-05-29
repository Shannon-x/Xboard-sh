<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use App\Services\UserService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class OrderPlanChangeTest extends TestCase
{
    private const BYTES_PER_GB = 1073741824;

    public function test_reset_day_uses_stored_next_reset_at(): void
    {
        $user = new User([
            'next_reset_at' => time() + 3600,
            'expired_at' => time() + (31 * 86400),
        ]);

        $this->assertSame(1, (new UserService())->getResetDay($user));
    }

    public function test_plan_change_does_not_trigger_open_event(): void
    {
        $order = new Order(['type' => Order::TYPE_UPGRADE]);
        $service = new OrderService($order);

        $this->assertSame(0, $this->invokePrivate($service, 'getOpenEventId', [$order]));
    }

    public function test_surplus_exceeding_order_total_is_refunded_to_balance(): void
    {
        // surplus_amount 已被 cap 在 orderAmountSum 之内（见 getSurplusValueByPeriod），
        // 多于新订单总额的部分进入 refund_amount，open() 时回到 user.balance。
        $order = new Order([
            'total_amount' => 10000,
            'surplus_amount' => 110500,
        ]);

        $this->invokePrivate(new OrderService($order), 'applySurplusDiscount', [$order]);

        $this->assertSame(10000, $order->surplus_amount);
        $this->assertSame(100500, $order->refund_amount);
        $this->assertSame(0, $order->total_amount);
    }

    public function test_surplus_below_order_total_only_discounts_no_refund(): void
    {
        $order = new Order([
            'total_amount' => 10000,
            'surplus_amount' => 3000,
        ]);

        $this->invokePrivate(new OrderService($order), 'applySurplusDiscount', [$order]);

        $this->assertSame(3000, $order->surplus_amount);
        $this->assertSame(0, $order->refund_amount);
        $this->assertSame(7000, $order->total_amount);
    }

    public function test_combined_discounts_cannot_make_order_amount_negative(): void
    {
        $order = new Order([
            'total_amount' => 10000,
            'discount_amount' => 10000,
        ]);
        $user = new User(['discount' => 50]);

        (new OrderService($order))->setVipDiscount($user);

        $this->assertSame(10000, $order->discount_amount);
        $this->assertSame(0, $order->total_amount);
    }

    public function test_surplus_ratio_uses_plan_limit_before_extra_user_traffic(): void
    {
        $plan = new Plan(['transfer_enable' => 800]);
        $user = new User([
            'transfer_enable' => 1800 * self::BYTES_PER_GB,
            'u' => 625 * self::BYTES_PER_GB,
            'd' => 0,
        ]);
        $user->setRelation('plan', $plan);

        $ratio = $this->invokePrivate(
            new OrderService(new Order()),
            'getCurrentCycleTrafficRatio',
            [$user]
        );

        $this->assertEqualsWithDelta(175 / 800, $ratio, 0.000001);
    }

    private function invokePrivate(object $object, string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionClass($object);
        $reflectedMethod = $reflection->getMethod($method);
        $reflectedMethod->setAccessible(true);

        return $reflectedMethod->invokeArgs($object, $arguments);
    }
}
