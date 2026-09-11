<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The same test also runs unchanged against the September 5 baseline. */
class LegacyApiContractTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $plan = Plan::create([
            'name' => 'Legacy contract', 'group_id' => 1, 'sort' => 1,
            'show' => true, 'sell' => true, 'renew' => true, 'capacity_limit' => null,
            'transfer_enable' => 100, 'device_limit' => null, 'speed_limit' => null,
            'reset_traffic_method' => 1,
            'prices' => array_fill_keys(array_values(Plan::LEGACY_PERIOD_MAPPING), '3.99'),
        ]);
        $user = User::create([
            'email' => Str::random(12) . '@example.test', 'password' => 'test',
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'plan_id' => $plan->id, 'group_id' => 1, 'balance' => 0,
            'transfer_enable' => 100 * 1024 ** 3, 'expired_at' => time() + 86400 * 30,
            'u' => 0, 'd' => 0, 'device_limit' => null, 'speed_limit' => null,
        ]);
        return [$plan, $user];
    }

    public static function periods(): array
    {
        $cases = [];
        foreach (Plan::LEGACY_PERIOD_MAPPING as $legacy => $canonical) {
            $cases[$legacy] = [$legacy, $canonical];
        }
        return $cases;
    }

    #[DataProvider('periods')]
    public function test_old_order_request_detail_list_and_cancel_contract(string $period, string $canonical): void
    {
        [$plan, $user] = $this->fixtures();
        Sanctum::actingAs($user);
        // Some clients send explicit null, others omit the optional fields entirely.
        $trade = $this->postJson('/api/v1/user/order/save', [
            'plan_id' => $plan->id, 'period' => $period, 'coupon_code' => null,
            'options' => null, 'expected_amount' => null,
        ])->assertOk()->assertJsonPath('status', 'success')->json('data');
        $this->assertIsString($trade);
        $order = Order::where('trade_no', $trade)->firstOrFail();
        $this->assertSame($canonical, $order->period);
        $this->assertSame(399, $order->total_amount);
        $this->getJson('/api/v1/user/order/detail?trade_no=' . $trade)->assertOk()
            ->assertJsonPath('data.period', array_search($canonical, Plan::LEGACY_PERIOD_MAPPING, true))
            ->assertJsonPath('data.plan.id', $plan->id);
        $list = json_decode($this->getJson('/api/v1/user/order/fetch')->assertOk()->getContent());
        $this->assertIsArray($list->data);
        $this->assertCount(1, $list->data);
        $this->postJson('/api/v1/user/order/cancel', ['trade_no' => $trade])->assertOk();
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
    }

    public function test_bootstrap_and_existing_session_contract(): void
    {
        [$plan, $user] = $this->fixtures();
        $this->getJson('/api/v1/guest/comm/config')->assertOk()->assertJsonPath('status', 'success');
        $plans = json_decode($this->getJson('/api/v1/guest/plan/fetch')->assertOk()->getContent());
        $this->assertIsArray($plans->data);
        $this->assertEquals(399, $plans->data[0]->month_price);
        $this->assertNull($plans->data[0]->speed_limit);
        $this->assertNull($plans->data[0]->device_limit);
        // Exercise the real Bearer authentication middleware, not actingAs().
        $token = $user->createToken('compatibility')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer ' . $token);
        $this->getJson('/api/v1/user/checkLogin')->assertOk()->assertJsonPath('data.is_login', true);
        $this->getJson('/api/v1/user/info')->assertOk()->assertJsonPath('data.email', $user->email);
        $this->getJson('/api/v1/user/getSubscribe')->assertOk()
            ->assertJsonPath('data.plan.id', $plan->id)->assertJsonPath('data.transfer_enable', 100 * 1024 ** 3);
        $this->getJson('/api/v1/user/getStat')->assertOk()->assertJsonPath('data', [0, 0, 0]);
        $this->getJson('/api/v1/user/server/fetch')->assertOk()->assertJsonPath('data', []);
    }
}
