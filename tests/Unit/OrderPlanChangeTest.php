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
 * 设计：buyByPeriod 中 TYPE_UPGRADE 的唯一规则是
 *   new_expired_at = max(old_expired_at, now) + new_period_duration
 * 其它一切（剩余价值折抵、refund 退余额、pending 下周期生效、STATUS_DISCOUNTED 旧单标记、
 * isEquivalentPlanSwitch 判定）全部移除，让套餐变更的语义保持单一。
 *
 * 测试不依赖绝对时间——所有 expired_at 都基于 time() 相对偏移，保证任何时刻运行都成立。
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

    /**
     * 942 用户场景：用户在旧到期日前 24 天换同档套餐，新到期日应从旧到期日往后叠加 1 个月，
     * 而不是从 now 起算。
     *
     * 原 bug：buyByPeriod 里 `$this->user->expired_at = time()` 把旧到期时间砍掉了，
     * 用户付 20 元只多 8 天（5/30 + 1 月 = 7/01，而非 6/23 + 1 月 = 7/23）。
     */
    public function test_plan_change_extends_from_old_expiration_not_now(): void
    {
        $oldExpiredAt = time() + (24 * self::DAY_SECONDS); // 24 天后到期，模拟 942 的"剩 24 天"

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

        $this->invokePrivate($service, 'buyByPeriod', [$order, $targetPlan]);

        // 关键：base = max(oldExpiredAt, now) = oldExpiredAt（旧到期未过）
        $expected = Carbon::createFromTimestamp($oldExpiredAt)->addMonths(1)->timestamp;
        $this->assertSame($expected, $user->expired_at, '套餐变更应从旧到期日起叠加新周期');
        $this->assertSame(2, $user->plan_id);
        $this->assertSame(250 * self::BYTES_PER_GB, $user->transfer_enable);
        // 已用流量必须保留（防止换套餐刷流量）
        $this->assertSame(260 * self::BYTES_PER_GB, $user->u);
        $this->assertSame(0, $user->d);
    }

    /**
     * 真升级：transfer_enable 立刻变大，u/d 保留。
     * 重置日前可用 = new_cap - used；重置日后获得全新 new_cap。
     */
    public function test_upgrade_immediately_grants_larger_transfer_enable_without_resetting_usage(): void
    {
        $oldExpiredAt = time() + (12 * self::DAY_SECONDS);

        $targetPlan = new Plan([
            'prices' => [Plan::PERIOD_MONTHLY => 50],
            'transfer_enable' => 500,
        ]);
        $targetPlan->id = 2;

        $user = new User([
            'plan_id' => 1,
            'expired_at' => $oldExpiredAt,
            'transfer_enable' => 250 * self::BYTES_PER_GB,
            'u' => 200 * self::BYTES_PER_GB,
            'd' => 0,
        ]);

        $order = new Order([
            'type' => Order::TYPE_UPGRADE,
            'period' => Plan::PERIOD_MONTHLY,
        ]);
        $service = new OrderService($order);
        $service->user = $user;

        $this->invokePrivate($service, 'buyByPeriod', [$order, $targetPlan]);

        $this->assertSame(500 * self::BYTES_PER_GB, $user->transfer_enable, '升级后流量上限立即生效');
        $this->assertSame(200 * self::BYTES_PER_GB, $user->u, '已用流量必须保留');
        $expected = Carbon::createFromTimestamp($oldExpiredAt)->addMonths(1)->timestamp;
        $this->assertSame($expected, $user->expired_at);
    }

    /**
     * 年付降级到月付：保留年付剩余 11 个月，再叠加 1 个月。不退余额（避免套利环路）。
     */
    public function test_yearly_to_monthly_downgrade_preserves_yearly_remainder(): void
    {
        $oldExpiredAt = time() + (358 * self::DAY_SECONDS); // 约还剩 12 个月

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

        $this->invokePrivate($service, 'buyByPeriod', [$order, $targetPlan]);

        $expected = Carbon::createFromTimestamp($oldExpiredAt)->addMonths(1)->timestamp;
        $this->assertSame($expected, $user->expired_at, '年付剩余时间保留，月付叠加之后');
        $this->assertSame(250 * self::BYTES_PER_GB, $user->transfer_enable);
        // 不能有 refund_amount 写回余额（堵死烧流量后降级套现）
        $this->assertEquals(0, (int) ($order->refund_amount ?? 0));
        $this->assertEquals(0, (int) ($order->surplus_amount ?? 0));
    }

    /**
     * 旧到期日已过去（不应该真的进入 setOrderType 的 UPGRADE 分支，但 buyByPeriod 直接调用时
     * 应该退化为 base=now，避免负数偏移）。
     */
    public function test_plan_change_falls_back_to_now_when_old_expiration_is_past(): void
    {
        $oldExpiredAt = time() - (3 * self::DAY_SECONDS); // 已过期 3 天
        $now = time();

        $targetPlan = new Plan([
            'prices' => [Plan::PERIOD_MONTHLY => 20],
            'transfer_enable' => 250,
        ]);
        $targetPlan->id = 2;

        $user = new User([
            'plan_id' => 1,
            'expired_at' => $oldExpiredAt,
            'transfer_enable' => 250 * self::BYTES_PER_GB,
        ]);

        $order = new Order([
            'type' => Order::TYPE_UPGRADE,
            'period' => Plan::PERIOD_MONTHLY,
        ]);
        $service = new OrderService($order);
        $service->user = $user;

        $this->invokePrivate($service, 'buyByPeriod', [$order, $targetPlan]);

        $expectedMin = Carbon::createFromTimestamp($now)->addMonths(1)->timestamp - 10;
        $expectedMax = Carbon::createFromTimestamp($now + 5)->addMonths(1)->timestamp + 10;
        $this->assertGreaterThanOrEqual($expectedMin, $user->expired_at);
        $this->assertLessThanOrEqual($expectedMax, $user->expired_at);
    }

    /**
     * VIP + 优惠券叠加不能让订单金额变成负数。
     */
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

    /**
     * 套餐变更必须保留 next_reset_at（流量重置日不漂移）。
     */
    public function test_plan_change_preserves_reset_schedule(): void
    {
        $order = new Order(['type' => Order::TYPE_UPGRADE]);
        $service = new OrderService($order);
        $service->user = new User(['expired_at' => time() + self::DAY_SECONDS]);

        $this->assertTrue(
            $this->invokePrivate($service, 'shouldPreserveResetSchedule', [$order]),
            'TYPE_UPGRADE 且 expired_at 非空时必须保留 next_reset_at'
        );
    }

    /**
     * 续费不走变更路径，不需要保留 reset 日。
     */
    public function test_renewal_does_not_preserve_reset_schedule(): void
    {
        $order = new Order(['type' => Order::TYPE_RENEWAL]);
        $service = new OrderService($order);
        $service->user = new User(['expired_at' => time() + self::DAY_SECONDS]);

        $this->assertFalse(
            $this->invokePrivate($service, 'shouldPreserveResetSchedule', [$order])
        );
    }

    private function invokePrivate(object $object, string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionClass($object);
        $reflectedMethod = $reflection->getMethod($method);
        $reflectedMethod->setAccessible(true);

        return $reflectedMethod->invokeArgs($object, $arguments);
    }
}
