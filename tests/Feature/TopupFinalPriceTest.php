<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\{Order, Plan, ServerGroup, User};
use App\Services\{OrderService, PlanCustomizationService as Pricing};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TopupFinalPriceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(int $monthly = 240, int $gb = 1000, int $unit = 24): array
    {
        $base = new ServerGroup(); $base->name = 'Base'; $base->save();
        $group = new ServerGroup(); $group->name = 'Premium'; $group->save();
        $plan = Plan::create(['name' => 'Final price', 'group_id' => $base->id,
            'sell' => true, 'show' => true, 'renew' => true, 'capacity_limit' => null,
            'transfer_enable' => $gb, 'device_limit' => 2, 'speed_limit' => 100,
            'reset_traffic_method' => 1, 'prices' => ['monthly' => $monthly, 'quarterly' => $monthly * 2.85],
            'customization' => [
                'transfer_enable' => ['mode' => 'choices', 'max' => $gb + 2000, 'step' => 100,
                    'price_per_step' => $unit * 100, 'choices' => [$gb, $gb + 100, $gb + 300, $gb + 500, $gb + 1000, $gb + 2000]],
                'device_limit' => ['mode' => 'fixed'], 'speed_limit' => ['mode' => 'fixed'],
                'addon_groups' => [$group->id => ['mode' => 'optional', 'price' => 1000,
                    'topup_price_per_gb' => 0, 'topup_final_price_per_gb' => 12]],
                'traffic_topup' => ['mode' => 'on', 'price_per_gb' => $unit, 'max_gb' => 2000],
            ]]);
        $user = User::create(['email' => Str::random(15).'@example.test', 'password' => 'test',
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'balance' => 0,
            'plan_id' => $plan->id, 'group_id' => $base->id,
            'plan_options' => ['transfer_enable' => $gb, 'device_limit' => 2, 'speed_limit' => 100, 'addon_groups' => [$group->id]],
            'transfer_enable' => $gb * 1073741824, 'u' => 0, 'd' => 0,
            'device_limit' => 2, 'speed_limit' => 100, 'expired_at' => time() + 86400 * 20]);
        return [$plan, $user, $group->id, new Pricing()];
    }

    public function test_three_high_tiers_keep_package_prices_and_use_final_topup_price(): void
    {
        foreach ([[198, 1600, 13], [240, 1000, 24], [240, 1200, 20]] as [$monthly, $gb, $unit]) {
            [$p, $u, $id, $svc] = $this->fixture($monthly, $gb, $unit);
            $svc->validateConfiguration($p);
            $before = $u->fresh()->getAttributes();
            $this->assertSame(($monthly + 10) * 100, $svc->quote($p, 'monthly', null, $u)['amount']);
            $options = $u->plan_options; $options['transfer_enable'] += 100;
            $this->assertSame(($monthly + 10) * 100 + $unit * 100, $svc->quote($p, 'monthly', $options, $u)['amount']);
            foreach ([50, 100, 200, 300, 500, 1000, 2000] as $extra) {
                $this->assertSame($extra * 12, $svc->quoteTopup($p, $u, ['topup_gb' => $extra])['amount']);
                $ordinary = clone $u; $ordinary->plan_options = array_replace($u->plan_options, ['addon_groups' => []]);
                $this->assertSame($extra * $unit, $svc->quoteTopup($p, $ordinary, ['topup_gb' => $extra])['amount']);
            }
            $this->assertSame($before, $u->fresh()->getAttributes());
            $this->assertTrue($svc->topupSummary($p, $u)['price_overridden']);
        }
    }

    public function test_final_price_ignores_ordinary_tier_specials_and_does_not_add_twice(): void
    {
        [$p, $u, $id, $svc] = $this->fixture();
        $c = $p->customization;
        $c['traffic_topup']['selection'] = 'choices';
        $c['traffic_topup']['choices'] = [['gb' => 100, 'price' => 3000], ['gb' => 500]];
        $p->customization = $c; $svc->validateConfiguration($p);
        $u->admin_group_ids = [$id];
        $this->assertSame(1200, $svc->quoteTopup($p, $u, ['topup_gb' => 100])['amount']);
        $this->assertSame(6000, $svc->quoteTopup($p, $u, ['topup_gb' => 500])['amount']);
        $u->admin_group_ids = []; $u->plan_options = array_replace($u->plan_options, ['addon_groups' => []]);
        $this->assertSame(3000, $svc->quoteTopup($p, $u, ['topup_gb' => 100])['amount']);
    }

    public function test_pricing_only_admin_group_does_not_grant_or_sell_access(): void
    {
        [$p, $u, $id, $svc] = $this->fixture();
        $legacy = new ServerGroup(); $legacy->name = 'Legacy'; $legacy->save();
        $c = $p->customization; $c['traffic_topup']['group_prices'] = [$legacy->id => 12];
        $p->customization = $c; $p->save(); $svc->validateConfiguration($p);
        $this->assertSame([$id], $svc->optionalAddonIds($p));
        $this->assertSame([], $svc->includedAddonIds($p));
        $u->plan_options = array_replace($u->plan_options, ['addon_groups' => []]);
        $this->assertSame(2400, $svc->quoteTopup($p, $u, ['topup_gb' => 100])['amount']);
        $u->admin_group_ids = [$legacy->id];
        $this->assertSame(1200, $svc->quoteTopup($p, $u, ['topup_gb' => 100])['amount']);
        $this->assertSame([$legacy->id], $svc->purchaseTopupGroups($u));
    }

    public function test_multiple_final_groups_use_highest_rate_and_other_surcharges_are_preserved(): void
    {
        [$p, $u, $id, $svc] = $this->fixture();
        $other = new ServerGroup(); $other->name = 'Other'; $other->save();
        $c = $p->customization;
        $c['addon_groups'][$other->id] = ['mode' => 'included', 'price' => 0, 'topup_final_price_per_gb' => 15];
        $p->customization = $c; $p->save();
        $this->assertSame(1500, $svc->quoteTopup($p, $u, ['topup_gb' => 100])['amount']);
        unset($c['addon_groups'][$other->id]['topup_final_price_per_gb']);
        $c['addon_groups'][$other->id]['topup_price_per_gb'] = 5;
        $p->customization = $c; $p->save();
        $this->assertSame(1700, $svc->quoteTopup($p, $u, ['topup_gb' => 100])['amount']);
    }

    public function test_invalid_final_prices_and_conflicting_surcharges_are_rejected(): void
    {
        [$p, $u, $id, $svc] = $this->fixture();
        foreach ([0, -1, 12.5, '12', true, Pricing::MAX_AMOUNT + 1] as $bad) {
            $clone = clone $p; $c = $p->customization;
            $c['addon_groups'][$id]['topup_final_price_per_gb'] = $bad; $clone->customization = $c;
            try {$svc->validateConfiguration($clone); $this->fail('Accepted invalid final price');}
            catch(ApiException $e) {$this->assertStringContainsString('最终单价', $e->getMessage());}
        }
        $c = $p->customization; $c['addon_groups'][$id]['topup_price_per_gb'] = 1; $p->customization = $c;
        $this->expectException(ApiException::class); $svc->validateConfiguration($p);
    }

    public function test_invalid_pricing_only_groups_are_rejected_even_when_topups_are_off(): void
    {
        [$p, $u, $id, $svc] = $this->fixture();
        foreach ([[$id => 12], [$p->group_id => 12], [999999 => 12], ['bad' => 12]] as $bad) {
            $clone = clone $p; $c = $p->customization;
            $c['traffic_topup']['group_prices'] = $bad; $c['traffic_topup']['mode'] = 'off'; $clone->customization = $c;
            try {$svc->validateConfiguration($clone); $this->fail('Accepted invalid pricing-only group');}
            catch(ApiException $e) {$this->assertStringContainsString('授权组', $e->getMessage());}
        }
    }

    public function test_ordinary_floor_is_not_disabled_by_a_premium_override(): void
    {
        [$p, $u, $id, $svc] = $this->fixture();
        $c = $p->customization; $c['traffic_topup']['price_per_gb'] = 12; $p->customization = $c;
        $this->expectException(ApiException::class); $this->expectExceptionMessage('低于');
        $svc->validateConfiguration($p);
    }

    public function test_unknown_paid_final_group_is_not_priced_as_ordinary_after_plan_change(): void
    {
        [$p, $u, $id, $svc] = $this->fixture();
        $other = $p->replicate(); $c = $p->customization; unset($c['addon_groups']);
        $other->customization = $c; $other->save();
        $u->plan_id = $other->id;
        $this->assertFalse($svc->topupSummary($other, $u)['enabled']);
        $this->expectException(ApiException::class); $svc->quoteTopup($other, $u, ['topup_gb' => 100]);
    }

    public function test_final_order_honors_snapshot_after_rate_change_and_keeps_entitlements(): void
    {
        [$p, $u, $id, $svc] = $this->fixture();
        $u->update(['discount' => 50]);
        $before = $u->only(['plan_id', 'plan_options', 'expired_at', 'device_limit', 'speed_limit']);
        $order = OrderService::createFromRequest($u, $p, 'traffic_topup', null, ['topup_gb' => 100], 1200);
        $this->assertSame(1200, (int) $order->total_amount, 'Final-price topups do not stack VIP discounts');
        $this->assertTrue($order->plan_snapshot['price_overridden']);
        $c = $p->customization; $c['addon_groups'][$id]['topup_final_price_per_gb'] = 15; $p->update(['customization' => $c]);
        $order->update(['status' => Order::STATUS_PROCESSING]); (new OrderService($order))->open();
        $this->assertSame(1100 * 1073741824, (int) $u->fresh()->transfer_enable);
        $this->assertSame($before, $u->fresh()->only(array_keys($before)));
    }

    public function test_fixed_final_topup_rejects_coupons_and_wrong_expected_price_without_orders(): void
    {
        [$p, $u] = $this->fixture(); Sanctum::actingAs($u);
        $this->postJson('/api/v1/user/order/save', ['plan_id' => $p->id, 'period' => 'traffic_topup', 'topup_gb' => 100, 'expected_amount' => 2400])->assertStatus(400);
        $this->assertSame(0, Order::count());
        try {OrderService::createFromRequest($u, $p, 'traffic_topup', 'ANY', ['topup_gb' => 100]); $this->fail('Coupon accepted');}
        catch(ApiException $e) {$this->assertStringContainsString('固定最终价', $e->getMessage());}
        $this->assertSame(0, Order::count());
        $this->getJson('/api/v1/user/getSubscribe?include_addon_groups=1')->assertOk()
            ->assertJsonPath('data.traffic_topup.price_per_gb', 12)->assertJsonPath('data.traffic_topup.price_overridden', true);
    }

    public function test_late_final_order_is_held_if_line_access_was_removed(): void
    {
        [$p, $u] = $this->fixture();
        $order = OrderService::createFromRequest($u, $p, 'traffic_topup', null, ['topup_gb' => 100]);
        $o = $u->plan_options; $o['addon_groups'] = []; $u->update(['plan_options' => $o]);
        $order->update(['status' => Order::STATUS_PROCESSING]);
        $this->expectException(\RuntimeException::class); $this->expectExceptionMessage('线路权限已变化');
        (new OrderService($order))->open();
    }

    public function test_legacy_cheap_order_without_context_is_held_for_final_price_access(): void
    {
        [$p, $u] = $this->fixture();
        $order = OrderService::createFromRequest($u, $p, 'traffic_topup', null, ['topup_gb' => 100]);
        $snapshot = $order->plan_snapshot; unset($snapshot['pricing_context']); $snapshot['amount'] = 800;
        $order->update(['plan_snapshot' => $snapshot, 'status' => Order::STATUS_PROCESSING]);
        $this->expectException(\RuntimeException::class); $this->expectExceptionMessage('旧流量加购订单');
        (new OrderService($order))->open();
    }
}
