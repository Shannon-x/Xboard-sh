<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PlanCustomizationService;
use App\Services\TrafficResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConfigurablePlanTest extends TestCase
{
    use RefreshDatabase;

    private function plan(): Plan
    {
        return Plan::create([
            'name' => 'Configurable', 'capacity_limit' => null, 'group_id' => 17, 'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => 100,
            'reset_traffic_method' => 1,
            'prices' => ['monthly' => 3, 'quarterly' => 8.55, 'reset_traffic' => 3],
            'customization' => [
                'transfer_enable' => ['max' => 2000, 'step' => 100, 'price_per_step' => 60],
                'device_limit' => ['max' => 10, 'step' => 1, 'price_per_step' => 50],
                'speed_limit' => ['max' => 1000, 'step' => 100, 'price_per_step' => 20],
            ],
        ]);
    }

    private function user(array $attributes = []): User
    {
        return User::create($attributes + [
            'email' => Str::random(12).'@example.test', 'password' => 'test',
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'balance' => 0, 'transfer_enable' => 0, 'expired_at' => 0,
        ]);
    }

    private function selected(): array
    {
        return ['transfer_enable' => 500, 'device_limit' => 3, 'speed_limit' => 400];
    }

    public function test_quote_adds_resources_and_inherits_actual_period_discount(): void
    {
        $plan = $this->plan();
        $calculator = new PlanCustomizationService();
        $monthly = $calculator->quote($plan, 'month_price', $this->selected());
        $this->assertSame(650, $monthly['amount']);
        $this->assertSame(['base' => 300, 'transfer_enable' => 240, 'device_limit' => 50, 'speed_limit' => 60], $monthly['breakdown']);
        $this->assertSame(1853, $calculator->quote($plan, 'quarter_price', $this->selected())['amount']);
        $this->assertSame(300, $calculator->quote($plan, 'monthly')['amount']);
    }

    public static function invalidSelections(): array
    {
        return [
            'unlimited device' => ['device_limit', 0], 'negative' => ['transfer_enable', -100],
            'too much traffic' => ['transfer_enable', 2100], 'wrong step' => ['transfer_enable', 101],
            'too many devices' => ['device_limit', 101], 'speed ceiling' => ['speed_limit', 10001],
            'fraction' => ['device_limit', 2.5], 'null' => ['speed_limit', null],
            'string' => ['device_limit', '3'], 'infinite string' => ['transfer_enable', 'Infinity'],
            'unknown field' => ['total_amount', 1],
        ];
    }

    #[DataProvider('invalidSelections')]
    public function test_invalid_selection_is_rejected(string $field, mixed $value): void
    {
        $plan = $this->plan();
        $options = $this->selected();
        $options[$field] = $value;
        $this->expectException(ApiException::class);
        (new PlanCustomizationService())->quote($plan, 'monthly', $options);
    }

    public function test_unbounded_configuration_is_rejected(): void
    {
        $plan = $this->plan();
        $rules = $plan->customization;
        $rules['device_limit']['max'] = null;
        $plan->customization = $rules;
        $this->expectException(ApiException::class);
        (new PlanCustomizationService())->validateConfiguration($plan);
    }

    public function test_most_expensive_combination_is_checked_when_saving(): void
    {
        $plan = $this->plan();
        $rules = $plan->customization;
        $rules['transfer_enable']['price_per_step'] = 100000000;
        $plan->customization = $rules;
        $this->expectException(ApiException::class);
        (new PlanCustomizationService())->validateConfiguration($plan);
    }

    public function test_order_uses_quote_and_snapshot_survives_admin_changes(): void
    {
        $plan = $this->plan();
        $user = $this->user();
        $order = OrderService::createFromRequest($user, $plan, 'monthly', null, $this->selected(), 650);
        $this->assertSame(650, $order->total_amount);
        $this->assertEquals($this->selected(), $order->fresh()->plan_snapshot['options']);
        $plan->update(['transfer_enable' => 1000, 'device_limit' => 8, 'speed_limit' => 800, 'prices' => ['monthly' => 99]]);
        (new OrderService($order))->open();
        $user->refresh();
        $this->assertSame(500 * 1073741824, (int) $user->transfer_enable);
        $this->assertSame(3, (int) $user->device_limit);
        $this->assertSame(400, (int) $user->speed_limit);
        $this->assertEquals($this->selected(), $user->plan_options);
    }

    public function test_repricing_aborts_before_balance_is_debited(): void
    {
        $user = $this->user(['balance' => 10000]);
        try {
            OrderService::createFromRequest($user, $this->plan(), 'monthly', null, $this->selected(), 1);
            $this->fail('Stale price accepted');
        } catch (ApiException $e) {
            $this->assertSame(10000, (int) $user->fresh()->balance);
            $this->assertDatabaseCount('v2_order', 0);
        }
    }

    public function test_same_plan_different_specs_are_a_plan_change(): void
    {
        $plan = $this->plan();
        $user = $this->user([
            'plan_id' => $plan->id, 'expired_at' => time() + 300 * 86400,
            'transfer_enable' => 100 * 1073741824, 'device_limit' => 2, 'speed_limit' => 100,
        ]);
        $order = OrderService::createFromRequest($user, $plan, 'monthly', null, $this->selected());
        $this->assertSame(Order::TYPE_UPGRADE, $order->type);
        (new OrderService($order))->open();
        $this->assertLessThan(time() + 35 * 86400, (int) $user->fresh()->expired_at);
    }

    public function test_renewal_without_options_keeps_purchased_specs(): void
    {
        $plan = $this->plan();
        $user = $this->user([
            'plan_id' => $plan->id, 'expired_at' => time() + 300 * 86400,
            'transfer_enable' => 500 * 1073741824, 'device_limit' => 3, 'speed_limit' => 400,
            'plan_options' => $this->selected(),
        ]);
        $order = OrderService::createFromRequest($user, $plan, 'monthly');
        $this->assertSame(Order::TYPE_RENEWAL, $order->type);
        $this->assertSame(650, $order->total_amount);
        (new OrderService($order))->open();
        $this->assertGreaterThan(time() + 320 * 86400, (int) $user->fresh()->expired_at);
        $this->assertEquals($this->selected(), $user->fresh()->plan_options);
    }

    public function test_reset_only_charges_traffic_and_preserves_speed_devices_and_schedule(): void
    {
        $plan = $this->plan();
        $scheduled = time() + 3 * 86400;
        $user = $this->user([
            'plan_id' => $plan->id, 'expired_at' => time() + 20 * 86400,
            'next_reset_at' => $scheduled, 'u' => 400 * 1073741824,
            'transfer_enable' => 500 * 1073741824, 'device_limit' => 3, 'speed_limit' => 400,
            'plan_options' => $this->selected(),
        ]);
        $user->forceFill(['next_reset_at' => $scheduled])->saveQuietly();
        $order = OrderService::createFromRequest($user, $plan, 'reset_traffic');
        $this->assertSame(540, $order->total_amount);
        (new OrderService($order))->open();
        $user->refresh();
        $this->assertSame(3, (int) $user->device_limit);
        $this->assertSame(400, (int) $user->speed_limit);
        $this->assertSame(0, (int) $user->u);
        $this->assertSame($scheduled, (int) $user->next_reset_at);
    }

    public function test_reset_rejects_a_specification_change(): void
    {
        $plan = $this->plan();
        $user = $this->user(['plan_id' => $plan->id, 'plan_options' => $this->selected()]);
        $this->expectException(ApiException::class);
        (new PlanCustomizationService())->quote($plan, 'reset_traffic', ['transfer_enable' => 2000, 'device_limit' => 3, 'speed_limit' => 400], $user);
    }

    public function test_one_time_options_use_one_time_base_price(): void
    {
        $plan = $this->plan();
        $plan->prices = ['onetime' => 25];
        $plan->reset_traffic_method = Plan::RESET_TRAFFIC_NEVER;
        $quote = (new PlanCustomizationService())->quote($plan, 'onetime', $this->selected());
        $this->assertSame(2850, $quote['amount']);
    }

    public function test_legacy_plan_cannot_accept_custom_options(): void
    {
        $plan = $this->plan();
        $plan->customization = null;
        $this->assertNull((new PlanCustomizationService())->quote($plan, 'monthly')['snapshot']);
        $this->expectException(ApiException::class);
        (new PlanCustomizationService())->quote($plan, 'monthly', $this->selected());
    }

    public function test_guest_quote_does_not_create_an_order(): void
    {
        $plan = $this->plan();
        $this->postJson('/api/v1/guest/plan/quote', [
            'plan_id' => $plan->id, 'period' => 'month_price', 'options' => $this->selected(),
        ])->assertOk()->assertJsonPath('data.amount', 650);
        $this->assertDatabaseCount('v2_order', 0);
    }
    public function test_saved_options_are_compared_independent_of_json_key_order(): void
    {
        $plan = $this->plan();
        $options = $this->selected();
        $reordered = ['device_limit' => 3, 'speed_limit' => 400, 'transfer_enable' => 500];
        $user = $this->user(['plan_id' => $plan->id, 'plan_options' => $reordered]);
        $this->assertSame(540, (new PlanCustomizationService())->quote($plan, 'reset_traffic', $options, $user)['amount']);
    }

    public function test_admin_cannot_exclude_existing_purchased_options(): void
    {
        $plan = $this->plan();
        $this->user(['plan_id' => $plan->id, 'plan_options' => $this->selected()]);
        $rules = $plan->customization;
        $rules['transfer_enable']['max'] = 200;
        $plan->customization = $rules;
        $this->expectException(ApiException::class);
        (new PlanCustomizationService())->validateExistingSubscribers($plan);
    }

    public function test_plan_change_credit_uses_the_purchased_quota(): void
    {
        $plan = $this->plan();
        $user = $this->user(['plan_id' => $plan->id, 'plan_options' => $this->selected(), 'transfer_enable' => 500 * 1073741824]);
        $method = new \ReflectionMethod(OrderService::class, 'getSurplusTrafficLimit');
        $this->assertSame(500 * 1073741824, $method->invoke(new OrderService(new Order()), $user));
    }

    public function test_admin_cannot_exclude_pending_purchased_options(): void
    {
        $plan = $this->plan();
        OrderService::createFromRequest($this->user(), $plan, 'monthly', null, $this->selected());
        $rules = $plan->customization;
        $rules['device_limit']['max'] = 2;
        $plan->customization = $rules;
        $this->expectException(ApiException::class);
        (new PlanCustomizationService())->validateExistingSubscribers($plan);
    }

    public function test_consolidation_preview_and_publish_preserve_old_users_and_renewals(): void
    {
        $one = $this->plan();
        $two = $this->plan();
        $user = $this->user(['plan_id' => $one->id, 'transfer_enable' => 100 * 1073741824]);
        $definition = $one->only(['transfer_enable', 'device_limit', 'speed_limit', 'prices', 'customization']);
        $definition['name'] = 'Merged plan';
        $path = tempnam(sys_get_temp_dir(), 'plan-merge-');
        file_put_contents($path, json_encode($definition));
        try {
            $args = ['--source' => [$one->id, $two->id], '--config' => $path];
            $this->artisan('plan:consolidate', $args)->assertSuccessful();
            $this->assertDatabaseCount('v2_plan', 2);
            $this->artisan('plan:consolidate', $args + ['--apply' => true, '--publish' => true])->assertSuccessful();
            $this->assertDatabaseCount('v2_plan', 3);
            $this->assertFalse($one->fresh()->show);
            $this->assertTrue($one->fresh()->renew);
            $this->assertSame($one->id, $user->fresh()->plan_id);
            $this->assertSame(100 * 1073741824, (int) $user->fresh()->transfer_enable);
        } finally {
            unlink($path);
        }
    }

    public function test_user_order_endpoint_saves_options_and_modern_period(): void
    {
        $plan = $this->plan();
        $user = $this->user();
        \Laravel\Sanctum\Sanctum::actingAs($user);
        $this->postJson('/api/v1/user/order/save', [
            'plan_id' => $plan->id, 'period' => 'monthly', 'options' => $this->selected(), 'expected_amount' => 650,
        ])->assertOk();
        $this->assertSame(650, Order::first()->total_amount);
        $this->assertEquals($this->selected(), Order::first()->plan_snapshot['options']);
    }

    public function test_subscription_gateway_cannot_silently_drop_specs(): void
    {
        $plan = $this->plan();
        $user = $this->user();
        $order = OrderService::createFromRequest($user, $plan, 'monthly', null, $this->selected());
        $payment = \App\Models\Payment::create(['uuid' => Str::random(32), 'name' => 'Recurring', 'payment' => 'StripeSubscription', 'config' => [], 'enable' => true]);
        \Laravel\Sanctum\Sanctum::actingAs($user);
        $this->postJson('/api/v1/user/order/checkout', ['trade_no' => $order->trade_no, 'method' => $payment->id])->assertStatus(400);
        $this->assertNull($order->fresh()->payment_id);
    }

    public static function resourceModes(): array
    {
        return array_map(fn ($mask) => [$mask], range(0, 7));
    }

    #[DataProvider('resourceModes')]
    public function test_each_resource_can_be_fixed_independently(int $mask): void
    {
        $plan = $this->plan();
        $rules = $plan->customization;
        $options = (new PlanCustomizationService())->baseOptions($plan);
        $expected = 300;
        foreach (['transfer_enable' => 240, 'device_limit' => 50, 'speed_limit' => 60] as $field => $extra) {
            $bit = array_search($field, array_keys($rules));
            if ($mask & (1 << $bit)) {
                $options[$field] = $this->selected()[$field];
                $expected += $extra;
            } else {
                $rules[$field] = ['mode' => 'fixed'];
            }
        }
        $plan->customization = $rules;
        $quote = (new PlanCustomizationService())->quote($plan, 'monthly', $options);
        $this->assertSame($expected, $quote['amount']);
        $this->assertSame($mask !== 0, $quote['snapshot'] !== null);
    }

    public function test_all_fixed_preserves_every_legacy_period_price_and_unlimited_values(): void
    {
        $plan = $this->plan();
        $plan->device_limit = null;
        $plan->speed_limit = 0;
        $plan->prices = ['monthly' => 1.15, 'quarterly' => 8.55, 'half_yearly' => 17.00,
            'yearly' => 29.99, 'two_yearly' => 55, 'three_yearly' => 80, 'onetime' => 100, 'reset_traffic' => 2];
        $plan->customization = array_fill_keys(array_keys(PlanCustomizationService::LIMITS), ['mode' => 'fixed']);
        $calculator = new PlanCustomizationService();
        foreach (Plan::LEGACY_PERIOD_MAPPING as $legacy => $modern) {
            $quote = $calculator->quote($plan, $legacy);
            $this->assertSame((int) ($plan->prices[$modern] * 100), $quote['amount']);
            $this->assertNull($quote['snapshot']);
            $this->assertSame($quote, $calculator->quote($plan, $modern));
        }
        $resource = (new \App\Http\Resources\PlanResource($plan))->toArray(request());
        $this->assertNull($resource['customization']);
        $this->assertNull($resource['device_limit']);
        $this->assertSame(0, $resource['speed_limit']);
    }

    public function test_implicit_fixed_rules_keep_the_legacy_order_flow(): void
    {
        $plan = $this->plan();
        $rules = $plan->customization;
        foreach ($rules as $field => &$rule) $rule['max'] = (int) $plan->{$field};
        unset($rule);
        $plan->update(['customization' => $rules]);
        $user = $this->user();
        \Laravel\Sanctum\Sanctum::actingAs($user);
        $this->postJson('/api/v1/user/order/save', ['plan_id' => $plan->id, 'period' => 'month_price'])->assertOk();
        $order = Order::first();
        $this->assertNull($order->plan_snapshot);
        $this->assertSame(300, $order->total_amount);
        (new OrderService($order))->open();
        $this->assertNull($user->fresh()->plan_options);
        $this->assertSame(100 * 1073741824, (int) $user->fresh()->transfer_enable);
    }

    private function trafficChoices(Plan $plan): Plan
    {
        $plan->customization = [
            'transfer_enable' => ['mode' => 'choices', 'choices' => [100, 250, 500], 'max' => 500, 'step' => 100, 'price_per_step' => 60],
            'device_limit' => ['mode' => 'fixed'], 'speed_limit' => ['mode' => 'fixed'],
        ];
        return $plan;
    }

    public function test_discrete_capacity_pricing_and_one_time_delivery(): void
    {
        $plan = $this->trafficChoices($this->plan());
        $plan->prices = ['onetime' => 25];
        $plan->reset_traffic_method = Plan::RESET_TRAFFIC_NEVER;
        $plan->save();
        $user = $this->user();
        $options = ['transfer_enable' => 250, 'device_limit' => 2, 'speed_limit' => 100];
        $order = OrderService::createFromRequest($user, $plan, 'onetime_price', null, $options, 2590);
        (new OrderService($order))->open();
        $this->assertSame(2590, $order->total_amount);
        $this->assertSame(250 * 1073741824, (int) $user->fresh()->transfer_enable);
        $this->assertNull($user->fresh()->expired_at);
    }

    public function test_value_within_the_range_but_not_listed_cannot_be_bought(): void
    {
        $plan = $this->trafficChoices($this->plan());
        $this->expectException(ApiException::class);
        (new PlanCustomizationService())->quote($plan, 'monthly', ['transfer_enable' => 200, 'device_limit' => 2, 'speed_limit' => 100]);
    }

    public function test_fixed_resource_tampering_is_rejected(): void
    {
        $plan = $this->trafficChoices($this->plan());
        $this->expectException(ApiException::class);
        (new PlanCustomizationService())->quote($plan, 'monthly', ['transfer_enable' => 250, 'device_limit' => 3, 'speed_limit' => 100]);
    }

    public function test_fixed_unlimited_devices_and_speed_survive_custom_traffic_purchase(): void
    {
        $plan = $this->trafficChoices($this->plan());
        $plan->device_limit = null;
        $plan->speed_limit = 0;
        $plan->save();
        $user = $this->user();
        \Laravel\Sanctum\Sanctum::actingAs($user);
        $this->postJson('/api/v1/user/order/save', [
            'plan_id' => $plan->id, 'period' => 'month_price',
            'options' => ['transfer_enable' => 250, 'device_limit' => null, 'speed_limit' => 0],
        ])->assertOk();
        $order = Order::first();
        $this->assertSame(390, $order->total_amount);
        (new OrderService($order))->open();
        $this->assertNull($user->fresh()->device_limit);
        $this->assertSame(0, (int) $user->fresh()->speed_limit);
    }

    public function test_old_client_sees_its_purchased_specs_and_matching_renewal_price(): void
    {
        $plan = $this->plan();
        $user = $this->user(['plan_id' => $plan->id, 'plan_options' => $this->selected(), 'expired_at' => time() + 10 * 86400]);
        \Laravel\Sanctum\Sanctum::actingAs($user);
        $this->getJson('/api/v1/user/plan/fetch?id='.$plan->id)->assertOk()
            ->assertJsonPath('data.transfer_enable', 500)->assertJsonPath('data.month_price', 650)
            ->assertJsonPath('data.reset_price', 540)->assertJsonPath('data.customization', null);
        $this->getJson('/api/v1/user/plan/fetch?id='.$plan->id.'&include_customization=1')->assertOk()
            ->assertJsonPath('data.transfer_enable', 100)->assertJsonPath('data.month_price', 300);
        $this->getJson('/api/v1/user/getSubscribe')->assertOk()
            ->assertJsonPath('data.plan.transfer_enable', 500)->assertJsonPath('data.plan.prices.monthly', 6.5);
        $this->postJson('/api/v1/user/order/save', ['plan_id' => $plan->id, 'period' => 'month_price'])->assertOk();
        $this->assertSame(650, Order::first()->total_amount);
        $this->assertEquals($this->selected(), Order::first()->plan_snapshot['options']);
    }

    public function test_disabling_selection_is_allowed_when_purchased_specs_match_fixed_base(): void
    {
        $plan = $this->plan();
        $calculator = new PlanCustomizationService();
        $user = $this->user(['plan_id' => $plan->id, 'plan_options' => $calculator->baseOptions($plan)]);
        $plan->customization = null;
        $calculator->validateExistingSubscribers($plan);
        $this->assertNull($calculator->quote($plan, 'monthly', null, $user)['snapshot']);
    }

    public static function invalidCapacityLists(): array
    {
        return [[[250, 500]], [[100, 100, 500]], [[500, 100]], [[100, 1000001]], [[100, '500']], [[100, 250.5]], [[]]];
    }

    #[DataProvider('invalidCapacityLists')]
    public function test_invalid_capacity_lists_cannot_be_saved(array $choices): void
    {
        $plan = $this->trafficChoices($this->plan());
        $rules = $plan->customization;
        $rules['transfer_enable']['choices'] = $choices;
        $plan->customization = $rules;
        $this->expectException(ApiException::class);
        (new PlanCustomizationService())->validateConfiguration($plan);
    }

}
