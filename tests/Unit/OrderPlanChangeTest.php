<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use App\Services\UserService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 套餐变更核心规则的单元测试。
 *
 * 设计：
 *   - 同 plan_id 续费：延长时间，不重置当前周期流量。
 *   - 不同 plan_id 变更：旧套餐立即终止，新套餐从付款时间重新开始周期。
 *   - 开启折抵时按旧套餐未消耗价值抵扣/退回余额，但不保留旧 next_reset_at。
 */
class OrderPlanChangeTest extends TestCase
{
    private const BYTES_PER_GB = 1073741824;
    private const DAY_SECONDS = 86400;

    public function test_reset_day_uses_stored_next_reset_at(): void
    {
        $user = new User([
            'next_reset_at' => time() + 3600,
            'expired_at' => time() + (31 * self::DAY_SECONDS),
        ]);

        $this->assertSame(1, (new UserService())->getResetDay($user));
    }

    public function test_plan_change_does_not_trigger_open_event(): void
    {
        $order = new Order(['type' => Order::TYPE_UPGRADE]);
        $service = new OrderService($order);

        $this->assertSame(0, $this->invokePrivate($service, 'getOpenEventId', [$order]));
    }

    public function test_plan_change_starts_new_cycle_from_now_not_old_expiration(): void
    {
        $oldExpiredAt = time() + (24 * self::DAY_SECONDS);
        $now = time();

        $targetPlan = new Plan([
            'prices' => [Plan::PERIOD_MONTHLY => 20],
            'transfer_enable' => 250,
        ]);
        $targetPlan->id = 2;

        $user = new User([
            'plan_id' => 1,
            'expired_at' => $oldExpiredAt,
            'next_reset_at' => $oldExpiredAt,
            'transfer_enable' => 250 * self::BYTES_PER_GB,
            'u' => 260 * self::BYTES_PER_GB,
            'd' => 0,
        ]);

        $order = new Order([
            'type' => Order::TYPE_UPGRADE,
            'period' => Plan::PERIOD_MONTHLY,
        ]);
        $service = new OrderService($order);
        $service->user = $user;

        $this->invokePrivate($service, 'applyPlanChangeCycle', [$order, $targetPlan]);

        $expectedMin = Carbon::createFromTimestamp($now)->addMonth()->timestamp - 10;
        $expectedMax = Carbon::createFromTimestamp($now + 5)->addMonth()->timestamp + 10;

        $this->assertGreaterThanOrEqual($expectedMin, $user->expired_at);
        $this->assertLessThanOrEqual($expectedMax, $user->expired_at);
        $this->assertLessThan(
            Carbon::createFromTimestamp($oldExpiredAt)->addMonth()->timestamp,
            $user->expired_at,
            '套餐变更不能从旧到期日后叠加新周期'
        );
        $this->assertSame(2, $user->plan_id);
        $this->assertSame(250 * self::BYTES_PER_GB, $user->transfer_enable);
    }

    public function test_upgrade_does_not_stack_old_cycle_remainder_with_new_full_cycle(): void
    {
        $oldExpiredAt = time() + (26 * self::DAY_SECONDS);
        $now = time();

        $targetPlan = new Plan([
            'prices' => [Plan::PERIOD_MONTHLY => 50],
            'transfer_enable' => 800,
        ]);
        $targetPlan->id = 24;

        $user = new User([
            'plan_id' => 12,
            'expired_at' => $oldExpiredAt,
            'next_reset_at' => $oldExpiredAt,
            'transfer_enable' => 550 * self::BYTES_PER_GB,
            'u' => 333 * self::BYTES_PER_GB,
            'd' => 216 * self::BYTES_PER_GB,
        ]);

        $order = new Order([
            'type' => Order::TYPE_UPGRADE,
            'period' => Plan::PERIOD_MONTHLY,
        ]);
        $service = new OrderService($order);
        $service->user = $user;

        $this->invokePrivate($service, 'applyPlanChangeCycle', [$order, $targetPlan]);

        $this->assertSame(800 * self::BYTES_PER_GB, $user->transfer_enable);
        $this->assertSame(24, $user->plan_id);
        $this->assertLessThan(
            Carbon::createFromTimestamp($oldExpiredAt)->addMonth()->timestamp,
            $user->expired_at,
            '升级不能保留旧周期剩余时间再叠加一整月新套餐'
        );
        $this->assertGreaterThanOrEqual(Carbon::createFromTimestamp($now)->addMonth()->timestamp - 10, $user->expired_at);
    }

    public function test_yearly_to_monthly_plan_change_does_not_preserve_yearly_time_remainder(): void
    {
        $oldExpiredAt = time() + (358 * self::DAY_SECONDS);
        $now = time();

        $targetPlan = new Plan([
            'prices' => [Plan::PERIOD_MONTHLY => 30],
            'transfer_enable' => 250,
        ]);
        $targetPlan->id = 2;

        $user = new User([
            'plan_id' => 1,
            'expired_at' => $oldExpiredAt,
            'transfer_enable' => 500 * self::BYTES_PER_GB,
            'u' => 100 * self::BYTES_PER_GB,
            'd' => 0,
        ]);

        $order = new Order([
            'type' => Order::TYPE_UPGRADE,
            'period' => Plan::PERIOD_MONTHLY,
        ]);
        $service = new OrderService($order);
        $service->user = $user;

        $this->invokePrivate($service, 'applyPlanChangeCycle', [$order, $targetPlan]);

        $expectedMin = Carbon::createFromTimestamp($now)->addMonth()->timestamp - 10;
        $expectedMax = Carbon::createFromTimestamp($now + 5)->addMonth()->timestamp + 10;

        $this->assertGreaterThanOrEqual($expectedMin, $user->expired_at);
        $this->assertLessThanOrEqual($expectedMax, $user->expired_at);
        $this->assertSame(250 * self::BYTES_PER_GB, $user->transfer_enable);
    }

    public function test_surplus_exceeding_order_total_is_refunded_to_balance(): void
    {
        $order = new Order([
            'total_amount' => 3000,
            'surplus_amount' => 22000,
        ]);

        $this->invokePrivate(new OrderService($order), 'applySurplusDiscount', [$order]);

        $this->assertSame(3000, $order->surplus_amount);
        $this->assertSame(19000, $order->refund_amount);
        $this->assertSame(0, $order->total_amount);
    }

    public function test_surplus_below_order_total_only_discounts_no_refund(): void
    {
        $order = new Order([
            'total_amount' => 5000,
            'surplus_amount' => 2000,
        ]);

        $this->invokePrivate(new OrderService($order), 'applySurplusDiscount', [$order]);

        $this->assertSame(2000, $order->surplus_amount);
        $this->assertSame(0, $order->refund_amount);
        $this->assertSame(3000, $order->total_amount);
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
