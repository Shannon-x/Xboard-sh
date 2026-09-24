<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\OrderService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 套餐变更折抵：按「下单日+周期」筛不到订单、但用户实际没到期（提前续费 / 补偿延期）时，
 * 不能再把剩余天数清零还折抵 ¥0；同时兜底折抵绝不能让站点多付钱。
 */
class SurplusEarlyRenewalTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1073741824;
    private const DAY = 86400;

    private ServerGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->group = new ServerGroup();
        $this->group->name = 'base';
        $this->group->save();
    }

    private function plan(int $monthlyYuan, array $extraPrices = []): Plan
    {
        return Plan::create([
            'name' => 'P' . $monthlyYuan, 'capacity_limit' => null, 'group_id' => $this->group->id,
            'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => 100,
            'reset_traffic_method' => 1,
            'prices' => ['monthly' => $monthlyYuan] + $extraPrices,
        ]);
    }

    private function user(Plan $plan, int $expiresInSeconds, int $usedGb = 0): User
    {
        return User::create([
            'email' => Str::random(12) . '@example.test', 'password' => 'test',
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'plan_id' => $plan->id, 'group_id' => $plan->group_id, 'balance' => 0,
            'transfer_enable' => 100 * self::GB, 'u' => $usedGb * self::GB, 'd' => 0,
            'device_limit' => 2, 'speed_limit' => 100,
            'expired_at' => time() + $expiresInSeconds,
            'next_reset_at' => time() + $expiresInSeconds,
        ]);
    }

    private function paidOrder(User $user, Plan $plan, string $period, int $cents, int $createdDaysAgo): Order
    {
        $order = Order::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'period' => $period, 'type' => Order::TYPE_RENEWAL,
            'trade_no' => Helper::generateOrderNo(), 'total_amount' => $cents, 'status' => Order::STATUS_COMPLETED,
        ]);
        $order->created_at = time() - $createdDaysAgo * self::DAY;
        $order->save();
        return $order;
    }

    /** 模拟下单：setOrderType 之后再过 applySurplusDiscount 的最终结果。 */
    private function changeTo(User $user, Plan $target, string $period = 'monthly'): Order
    {
        $order = new Order([
            'user_id' => $user->id, 'plan_id' => $target->id, 'period' => $period,
            'total_amount' => (int) round($target->prices[$period] * 100),
        ]);
        (new OrderService($order))->setOrderType($user->fresh());
        return $order;
    }

    public function test_early_renewal_user_gets_surplus_instead_of_zero(): void
    {
        // #2977 形态：8-11 提前续费（覆盖 8-17→9-17）+ 补偿 7 天，9-22 换规格时还剩 1.5 天
        $plan = $this->plan(40);
        $user = $this->user($plan, (int) (1.5 * self::DAY));
        $renewal = $this->paidOrder($user, $plan, 'monthly', 4000, 42);

        $order = $this->changeTo($user, $this->plan(36));

        $this->assertSame(Order::TYPE_UPGRADE, (int) $order->type);
        $this->assertGreaterThan(0, $order->surplus_amount, '剩 1.5 天且流量没用：必须有折抵');
        $this->assertLessThanOrEqual(4000, $order->surplus_amount);
        $this->assertSame([$renewal->id], $order->surplus_order_ids, '折抵源要登记，开通时标记已折抵防重复');
    }

    public function test_fallback_never_exceeds_what_the_last_order_actually_paid(): void
    {
        // 最近一单 ¥40 付于 60 天前，之后靠赠送/补偿撑到 200 天后：再多剩余天数也只按这 ¥40 封顶
        $plan = $this->plan(40);
        $user = $this->user($plan, 200 * self::DAY);
        $this->paidOrder($user, $plan, 'monthly', 4000, 60);

        $order = $this->changeTo($user, $this->plan(100));

        $this->assertLessThanOrEqual(4000, $order->surplus_amount);
        $this->assertGreaterThanOrEqual(6000, $order->total_amount, '新单 ¥100 至少还要付 ¥60');
    }

    public function test_fallback_never_refunds_to_balance(): void
    {
        // 高价旧单 → 低价新单：常规路径会把超出部分退余额，兜底路径只抵不退
        $plan = $this->plan(100);
        $user = $this->user($plan, 25 * self::DAY);
        $this->paidOrder($user, $plan, 'monthly', 10000, 40);

        $order = $this->changeTo($user, $this->plan(10));

        $this->assertSame(1000, $order->surplus_amount, '折抵封顶到新单价格');
        $this->assertSame(0, (int) $order->total_amount);
        $this->assertSame(0, (int) $order->refund_amount, '兜底折抵不产生余额退款');
    }

    public function test_used_up_traffic_still_caps_fallback_value(): void
    {
        // 当前周期流量用光：当前周期价值为 0，只剩未来周期（这里没有）→ 折抵 0，与常规路径同口径
        $plan = $this->plan(40);
        $user = $this->user($plan, 3 * self::DAY, 100);
        $this->paidOrder($user, $plan, 'monthly', 4000, 35);

        $order = $this->changeTo($user, $this->plan(36));

        $this->assertSame(0, (int) $order->surplus_amount);
    }

    public function test_in_window_orders_keep_original_behaviour_including_refund(): void
    {
        // 常规路径不受影响：年付 ¥1000 两天前刚买，换 ¥10 月付，超出部分照旧退余额
        $plan = $this->plan(100, ['yearly' => 1000]);
        $user = $this->user($plan, 363 * self::DAY);
        $this->paidOrder($user, $plan, 'yearly', 100000, 2);

        $order = $this->changeTo($user, $this->plan(10));

        $this->assertSame(1000, $order->surplus_amount);
        $this->assertGreaterThan(0, (int) $order->refund_amount);
    }

    public function test_discounted_orders_are_never_reused_by_fallback(): void
    {
        $plan = $this->plan(40);
        $user = $this->user($plan, 2 * self::DAY);
        $old = $this->paidOrder($user, $plan, 'monthly', 4000, 40);
        $old->status = Order::STATUS_DISCOUNTED;
        $old->save();

        $order = $this->changeTo($user, $this->plan(36));

        $this->assertSame(0, (int) $order->surplus_amount, '已折抵过的订单不能再作计价基准');
        $this->assertSame([], $order->surplus_order_ids);
    }
}
