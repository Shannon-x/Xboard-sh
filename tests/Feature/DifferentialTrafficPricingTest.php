<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PlanCustomizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DifferentialTrafficPricingTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1073741824;
    private Plan $plan;
    private int $premium;
    private PlanCustomizationService $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $base = new ServerGroup();
        $base->name = '普通线路';
        $base->save();
        $group = new ServerGroup();
        $group->name = '三网优化线路';
        $group->save();
        $this->premium = $group->id;
        $this->pricing = new PlanCustomizationService();
        $this->plan = Plan::create([
            'name' => 'Differential', 'group_id' => $base->id,
            'capacity_limit' => null, 'sell' => true, 'show' => true, 'renew' => true,
            'transfer_enable' => 250, 'device_limit' => 2, 'speed_limit' => 100,
            'reset_traffic_method' => 1, 'prices' => ['monthly' => 20, 'quarterly' => 57],
            'customization' => [
                'transfer_enable' => ['mode' => 'choices', 'max' => 2250, 'step' => 100,
                    'price_per_step' => 800, 'choices' => [250, 350, 550, 750, 1250, 2250]],
                'device_limit' => ['mode' => 'fixed'], 'speed_limit' => ['mode' => 'fixed'],
                'addon_groups' => [(string) $this->premium => ['mode' => 'optional', 'price' => 1000,
                    'transfer_price_per_gb' => 4, 'topup_price_per_gb' => 4]],
                'traffic_topup' => ['mode' => 'on', 'price_per_gb' => 8, 'max_gb' => 2000],
            ],
        ]);
    }

    private function selection(int $gb = 250, bool $premium = false): array
    {
        return ['transfer_enable' => $gb, 'device_limit' => 2, 'speed_limit' => 100,
            'addon_groups' => $premium ? [$this->premium] : []];
    }

    private function user(bool $premium = false): User
    {
        return User::create([
            'email' => Str::random(12) . '@example.test', 'password' => 'test',
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'balance' => 0,
            'plan_id' => $this->plan->id, 'group_id' => $this->plan->group_id,
            'plan_options' => $this->selection(250, $premium),
            'transfer_enable' => 250 * self::GB, 'u' => 0, 'd' => 0,
            'device_limit' => 2, 'speed_limit' => 100,
            'expired_at' => time() + 20 * 86400, 'next_reset_at' => time() + 10 * 86400,
        ]);
    }

    private function configure(array $patch): void
    {
        $this->plan->customization = array_replace_recursive($this->plan->customization, $patch);
        $this->plan->save();
    }

    private function open(Order $order): void
    {
        $order->status = Order::STATUS_PROCESSING;
        $order->save();
        (new OrderService($order))->open();
    }

    public function test_all_five_extra_quota_choices_use_the_correct_rate(): void
    {
        foreach ([100, 300, 500, 1000, 2000] as $extra) {
            foreach ([false, true] as $premium) {
                $quote = $this->pricing->quote($this->plan, 'monthly', $this->selection(250 + $extra, $premium));
                $this->assertSame(2000 + ($premium ? 1000 : 0) + $extra * ($premium ? 12 : 8), $quote['amount']);
                $this->assertSame($extra * ($premium ? 12 : 8), $quote['snapshot']['breakdown']['transfer_enable']);
            }
        }
        $this->assertSame(3000, $this->pricing->quote($this->plan, 'monthly', $this->selection(250, true))['amount'], '基础额度不重复收差价');
    }

    public function test_period_discount_applies_once_to_both_extra_quota_and_fixed_line_fee(): void
    {
        $this->assertSame(11970, $this->pricing->quote($this->plan, 'quarterly', $this->selection(350, true))['amount']);
        $this->assertSame(7980, $this->pricing->quote($this->plan, 'quarterly', $this->selection(350))['amount']);
    }

    public function test_zero_surcharge_keeps_legacy_prices(): void
    {
        $this->configure(['addon_groups' => [$this->premium => ['transfer_price_per_gb' => 0]]]);
        $this->assertSame(3800, $this->pricing->quote($this->plan, 'monthly', $this->selection(350, true))['amount']);
    }

    public function test_included_and_admin_grants_are_charged_without_double_counting(): void
    {
        $user = $this->user(true);
        $user->update(['admin_group_ids' => [$this->premium]]);
        $this->assertSame(4200, $this->pricing->quote($this->plan, 'monthly', $this->selection(350, true), $user)['amount']);
        $this->assertSame(3200, $this->pricing->quote($this->plan, 'monthly', $this->selection(350), $user)['amount'], '取消付费选择不能绕过管理员仍授予的线路单价');
        $this->assertSame(1200, $this->pricing->quoteTopup($this->plan, $user, ['topup_gb' => 100])['amount']);
        $this->configure(['addon_groups' => [$this->premium => ['mode' => 'included', 'price' => 0]]]);
        $this->assertSame(3200, $this->pricing->quote($this->plan, 'monthly', $this->selection(350), $user)['amount']);
    }

    public function test_removing_optional_access_uses_future_not_current_selection(): void
    {
        $user = $this->user(true);
        $this->assertSame(2800, $this->pricing->quote($this->plan, 'monthly', $this->selection(350), $user)['amount']);
        $this->assertSame(1200, $this->pricing->quoteTopup($this->plan, $user, ['topup_gb' => 100])['amount'], '当前加购仍按尚未撤销的授权计费');
    }

    public function test_topup_range_and_special_tier_prices_both_add_the_line_surcharge(): void
    {
        $plain = $this->user();
        $premium = $this->user(true);
        foreach ([100, 300, 500, 1000, 2000] as $gb) {
            $this->assertSame($gb * 8, $this->pricing->quoteTopup($this->plan, $plain, ['topup_gb' => $gb])['amount']);
            $this->assertSame($gb * 12, $this->pricing->quoteTopup($this->plan, $premium, ['topup_gb' => $gb])['amount']);
        }
        $this->configure(['traffic_topup' => ['price_per_gb' => 10, 'selection' => 'choices', 'choices' => [['gb' => 100, 'price' => 800]]]]);
        $this->assertSame(800, $this->pricing->quoteTopup($this->plan, $plain, ['topup_gb' => 100])['amount']);
        $this->assertSame(1200, $this->pricing->quoteTopup($this->plan, $premium, ['topup_gb' => 100])['amount']);
        $this->pricing->validateConfiguration($this->plan);
    }

    public function test_backend_rejects_wrong_expected_amount_and_exposes_effective_topup_price(): void
    {
        $user = $this->user(true);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/user/order/save', ['plan_id' => $this->plan->id, 'period' => 'traffic_topup',
            'topup_gb' => 100, 'expected_amount' => 800])->assertStatus(400);
        $this->assertSame(0, Order::count());
        $this->getJson('/api/v1/user/getSubscribe?include_addon_groups=1')->assertOk()
            ->assertJsonPath('data.traffic_topup.price_per_gb', 12);
    }

    public function test_configuration_rejects_invalid_or_insufficient_surcharges(): void
    {
        foreach ([-1, '4', 1.5, PlanCustomizationService::MAX_AMOUNT + 1] as $bad) {
            $plan = clone $this->plan;
            $config = $plan->customization;
            $config['addon_groups'][$this->premium]['transfer_price_per_gb'] = $bad;
            $plan->customization = $config;
            try { $this->pricing->validateConfiguration($plan); $this->fail('Invalid surcharge accepted'); }
            catch (ApiException $e) { $this->assertStringContainsString('套餐加量', $e->getMessage()); }
        }
        $this->configure(['addon_groups' => [$this->premium => ['topup_price_per_gb' => 3]]]);
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('附加价');
        $this->pricing->validateConfiguration($this->plan);
    }

    public function test_new_topup_snapshot_blocks_late_payment_after_permissions_change(): void
    {
        $user = $this->user();
        $order = OrderService::createFromRequest($user, $this->plan, 'traffic_topup', null, ['topup_gb' => 100]);
        $this->assertSame(['addon_group_ids' => []], $order->plan_snapshot['pricing_context']);
        $user->update(['plan_options' => $this->selection(250, true)]);
        try { $this->open($order); $this->fail('Should hold the paid order for review'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('线路权限已变化', $e->getMessage()); }
        $this->assertSame(250 * self::GB, (int) $user->fresh()->transfer_enable);
        $this->assertSame(Order::STATUS_PROCESSING, $order->fresh()->status);
    }

    public function test_legacy_cheap_topup_cannot_be_delivered_to_premium_access(): void
    {
        $user = $this->user();
        $order = OrderService::createFromRequest($user, $this->plan, 'traffic_topup', null, ['topup_gb' => 100]);
        $snapshot = $order->plan_snapshot;
        unset($snapshot['pricing_context']);
        $order->update(['plan_snapshot' => $snapshot]);
        $user->update(['admin_group_ids' => [$this->premium]]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('旧流量加购订单');
        $this->open($order);
    }

    public function test_changed_plan_cannot_receive_an_old_topup(): void
    {
        $user = $this->user();
        $order = OrderService::createFromRequest($user, $this->plan, 'traffic_topup', null, ['topup_gb' => 100]);
        $user->update(['plan_id' => null]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('订阅状态已变化');
        $this->open($order);
    }

    public function test_price_changes_alone_do_not_reprice_an_existing_order_or_subscription(): void
    {
        $user = $this->user(true);
        $before = [$user->transfer_enable, $user->expired_at, $user->plan_options];
        $order = OrderService::createFromRequest($user, $this->plan, 'traffic_topup', null, ['topup_gb' => 100]);
        $this->configure(['addon_groups' => [$this->premium => ['transfer_price_per_gb' => 8, 'topup_price_per_gb' => 8]]]);
        $user->refresh();
        $this->assertSame($before, [$user->transfer_enable, $user->expired_at, $user->plan_options]);
        $this->open($order);
        $this->assertSame(1200, $order->fresh()->total_amount);
        $this->assertSame(350 * self::GB, (int) $user->fresh()->transfer_enable);
    }

    public function test_admin_permission_is_exposed_as_server_derived_preview_metadata(): void
    {
        $user = $this->user();
        $user->update(['admin_group_ids' => [$this->premium]]);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/user/plan/fetch?id=' . $this->plan->id . '&include_customization=1&include_addon_groups=1')
            ->assertOk()->assertJsonPath('data.customization.addon_groups.' . $this->premium . '.admin_granted', true)
            ->assertJsonPath('data.customization.addon_groups.' . $this->premium . '.transfer_price_per_gb', 4);
    }

    public function test_late_extra_quota_order_is_held_when_admin_access_changes(): void
    {
        $user = $this->user();
        $order = OrderService::createFromRequest($user, $this->plan, 'monthly', null, $this->selection(350));
        $user->update(['admin_group_ids' => [$this->premium]]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('套餐加量的线路权限已变化');
        $this->open($order);
    }

    public function test_legacy_resource_base_is_preserved_and_extra_quota_is_priced_above_it(): void
    {
        $user = $this->user(true);
        $options = $this->selection(300, true);
        $options['grandfathered_resources'] = ['plan_id' => $this->plan->id,
            'resources' => ['transfer_enable' => 300, 'device_limit' => 2, 'speed_limit' => 100]];
        $user->update(['transfer_enable' => 300 * self::GB, 'plan_options' => $options]);
        $this->assertSame(3000, $this->pricing->quote($this->plan, 'monthly', null, $user)['amount']);
        $this->assertSame(4200, $this->pricing->quote($this->plan, 'monthly', $this->selection(400, true), $user)['amount']);
        $this->assertSame(300 * self::GB, (int) $user->fresh()->transfer_enable);
    }

    public function test_one_time_and_range_variants_follow_the_same_pricing_rule(): void
    {
        $this->configure(['transfer_enable' => ['mode' => 'range']]);
        $this->assertSame(4200, $this->pricing->quote($this->plan, 'monthly', $this->selection(350, true))['amount']);
        $this->plan->update(['prices' => ['onetime' => 20], 'reset_traffic_method' => Plan::RESET_TRAFFIC_NEVER]);
        $this->assertSame(4200, $this->pricing->quote($this->plan, 'onetime', $this->selection(350, true))['amount']);
    }

    public function test_out_of_plan_paid_admin_group_cannot_silently_use_ordinary_prices(): void
    {
        $other = $this->plan->replicate();
        $other->name = '普通套餐';
        $config = $other->customization;
        unset($config['addon_groups']);
        $other->customization = $config;
        $other->save();
        $user = $this->user();
        $user->update(['plan_id' => $other->id, 'admin_group_ids' => [$this->premium]]);
        $summary = $this->pricing->topupSummary($other, $user);
        $this->assertFalse($summary['enabled']);
        $this->assertStringContainsString('缺少本套餐', $summary['unavailable_reason']);
        foreach (['monthly', 'traffic_topup'] as $period) {
            try {
                $this->pricing->quote($other, $period, $period === 'monthly' ? $this->selection(350) : ['topup_gb' => 100], $user);
                $this->fail('Missing group-specific prices must not default to the ordinary rate');
            } catch (ApiException $e) {
                $this->assertStringContainsString('缺少本套餐', $e->getMessage());
            }
        }
        $this->assertSame(250 * self::GB, (int) $user->fresh()->transfer_enable);
    }
}
