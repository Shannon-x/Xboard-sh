<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 余额足额时下单即扣款：客户以为没买成功再点一次，不能每次都直接扣，先要他确认。
 */
class RepeatOrderGuardTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        app()->forgetScopedInstances();
        config(['v2board.billing_receipt_enable' => 0]);
        $group = new ServerGroup();
        $group->name = '基础';
        $group->save();
        $this->plan = Plan::create([
            'name' => '静态家宽拼车', 'capacity_limit' => null, 'group_id' => $group->id, 'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => null, 'reset_traffic_method' => 1,
            'prices' => ['monthly' => 20, 'quarterly' => 57],
        ]);
    }

    private function user(array $attributes = []): User
    {
        return User::create($attributes + [
            'email' => Str::random(10) . '@example.test', 'password' => 'x', 'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'balance' => 10000, 'transfer_enable' => 0, 'expired_at' => 0,
        ]);
    }

    private function paidOrder(User $user, int $ageSeconds, int $status = Order::STATUS_COMPLETED, string $period = Plan::PERIOD_MONTHLY): Order
    {
        $order = Order::create([
            'user_id' => $user->id, 'plan_id' => $this->plan->id, 'period' => $period,
            'trade_no' => Str::upper(Str::random(16)), 'total_amount' => 0, 'balance_amount' => 2000,
            'type' => Order::TYPE_NEW_PURCHASE, 'status' => $status,
        ]);
        Order::where('id', $order->id)->update(['created_at' => time() - $ageSeconds]);
        return $order->refresh();
    }

    public function test_second_purchase_of_same_plan_within_window_needs_confirmation(): void
    {
        $user = $this->user();
        $previous = $this->paidOrder($user, 120);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/user/order/save', ['plan_id' => $this->plan->id, 'period' => 'month_price'])
            ->assertStatus(400)
            ->assertJsonPath('error.reason', 'repeat_order')
            ->assertJsonPath('error.trade_no', $previous->trade_no);
        $this->assertSame(1, Order::where('user_id', $user->id)->count());
        $this->assertSame(10000, (int) $user->refresh()->balance);

        $this->postJson('/api/v1/user/order/save', ['plan_id' => $this->plan->id, 'period' => 'month_price', 'confirm_repeat' => true])
            ->assertOk();
        $this->assertSame(2, Order::where('user_id', $user->id)->count());
    }

    public function test_guard_ignores_old_cancelled_and_other_period_orders(): void
    {
        $user = $this->user();
        $this->paidOrder($user, 11 * 60);
        $this->paidOrder($user, 60, Order::STATUS_CANCELLED);
        $this->paidOrder($user, 60, Order::STATUS_COMPLETED, Plan::PERIOD_QUARTERLY);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/user/order/save', ['plan_id' => $this->plan->id, 'period' => 'month_price'])
            ->assertOk();
    }
}
