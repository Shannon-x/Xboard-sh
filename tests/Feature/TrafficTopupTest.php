<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Http\Resources\PlanResource;
use App\Models\Order;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Services\AdvanceCycleService;
use App\Services\OrderService;
use App\Services\PlanCustomizationService;
use App\Services\TrafficResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 流量加购包：本周期内按 GB 追加流量。套餐级配置、默认关闭、没有站点级默认。
 *
 *   customization.traffic_topup = {mode: on, price_per_gb: 分, selection: range|choices, min/max/step, choices[{gb,price?}]}
 *   单价硬下限 = 套餐自身每 GB 到手价（topupPriceFloor）：低于它用户买最低档再加购就能套利。
 *   增值组规则 topup_price_per_gb 给持有该组的用户按 GB 加价 —— 买了 10x 组的人每 GB 更贵。
 *   开通：transfer_enable += N GB 且 transfer_topup += N GB。
 *   不变量：凡是把 u/d 清零的动作（月度重置、重置包、提前周期、换套餐、新购、管理员手动重置）
 *   都会把 transfer_topup 从 transfer_enable 扣回并归零 —— 加购只活在买它的那个周期；
 *   普通续费只叠时长、周期继续，加购保留。
 */
class TrafficTopupTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1073741824;

    private ServerGroup $base;
    private ServerGroup $premium;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = $this->group('基础');
        $this->premium = $this->group('10x 高速');
    }

    private function group(string $name): ServerGroup
    {
        $group = new ServerGroup();
        $group->name = $name;
        $group->save();
        return $group;
    }

    private const FIXED = [
        'transfer_enable' => ['mode' => 'fixed'], 'device_limit' => ['mode' => 'fixed'], 'speed_limit' => ['mode' => 'fixed'],
    ];

    /** 旧式套餐：没有 customization → 不卖加购。月付 ¥10 / 100GB → 到手价 10 分/GB。 */
    private function legacyPlan(array $overrides = []): Plan
    {
        return Plan::create($overrides + [
            'name' => 'Legacy', 'capacity_limit' => null, 'group_id' => $this->base->id,
            'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => 100,
            'reset_traffic_method' => 1,
            'prices' => ['monthly' => 10, 'reset_traffic' => 3],
        ]);
    }

    /** 开了加购的普通套餐（无增值组）：默认 50 分/GB 滑杆。 */
    private function topupPlan(array $topup = [], array $overrides = []): Plan
    {
        return $this->legacyPlan(['name' => 'Topup', 'customization' => self::FIXED + [
            'traffic_topup' => $topup + ['mode' => 'on', 'price_per_gb' => 50],
        ]] + $overrides);
    }

    /** 带可选购增值组的套餐；premium 组对加购每 GB 再加 30 分。 */
    private function addonPlan(array $topup = ['mode' => 'on', 'price_per_gb' => 50]): Plan
    {
        return $this->legacyPlan(['name' => 'Addon', 'customization' => self::FIXED + [
            'addon_groups' => [(string) $this->premium->id => ['mode' => 'optional', 'price' => 500, 'topup_price_per_gb' => 30]],
            'traffic_topup' => $topup,
        ]]);
    }

    private function user(Plan $plan, array $attributes = []): User
    {
        return User::create($attributes + [
            'email' => Str::random(12) . '@example.test', 'password' => 'test',
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'plan_id' => $plan->id, 'group_id' => $plan->group_id, 'balance' => 0,
            'transfer_enable' => 100 * self::GB, 'u' => 0, 'd' => 0,
            'device_limit' => 2, 'speed_limit' => 100,
            'expired_at' => time() + 20 * 86400, 'next_reset_at' => time() + 10 * 86400,
        ]);
    }

    private function resources(): array
    {
        return ['transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => 100];
    }

    private function buyTopup(User $user, Plan $plan, int $gb): Order
    {
        return $this->open(OrderService::createFromRequest($user, $plan, 'traffic_topup', null, ['topup_gb' => $gb]));
    }

    private function buy(User $user, Plan $plan, string $period, ?array $options = null): Order
    {
        return $this->open(OrderService::createFromRequest($user, $plan, $period, null, $options));
    }

    private function open(Order $order): Order
    {
        $order->status = Order::STATUS_PROCESSING;
        $order->save();
        (new OrderService($order))->open();
        return $order->fresh();
    }

    // ───────────────────────── 规则：套餐级、默认关、有下限 ─────────────────────────

    public function test_topup_is_off_unless_the_plan_turns_it_on(): void
    {
        $service = new PlanCustomizationService();
        $this->assertNull($service->topupRule($this->legacyPlan()), '旧套餐：没配置 = 不卖');
        $this->assertNull($service->topupRule($this->topupPlan(['mode' => 'off'])));
        $this->assertNull($service->topupRule($this->topupPlan(['mode' => 'inherit'])), '早期草案里的 inherit 已无站点默认可继承，视为关闭');
        $this->assertNull(PlanResource::make($this->legacyPlan())->resolve()['traffic_topup']);

        $rule = $service->topupRule($this->topupPlan());
        $this->assertSame(['range', 50, 1, 1000, 1, [10, 50, 100, 200]],
            [$rule['selection'], $rule['price_per_gb'], $rule['min_gb'], $rule['max_gb'], $rule['step_gb'], $rule['presets']]);
        $this->assertSame(['price_per_gb' => 50, 'min_gb' => 1, 'max_gb' => 1000],
            PlanResource::make($this->topupPlan())->resolve()['traffic_topup']);
        $this->assertArrayNotHasKey('traffic_topup', PlanResource::make($this->topupPlan())->resolve()['customization'] ?? [], '原始配置不下发');
    }

    public function test_price_floor_is_the_plans_own_per_gb_price_and_blocks_arbitrage(): void
    {
        $service = new PlanCustomizationService();
        $this->assertSame(10, $service->topupPriceFloor($this->legacyPlan()), '¥10 / 100GB = 10 分/GB');
        $this->assertSame(9, $service->topupPriceFloor($this->legacyPlan(['prices' => ['yearly' => 100]])), '只有年付：¥100/12 月/100GB = 8.33 → 向上取整 9');
        $this->assertSame(0, $service->topupPriceFloor($this->legacyPlan(['prices' => []])), '没定价的套餐无下限');
        // 购买时自选流量每 100GB 加 ¥20（= 20 分/GB）比基础价贵：下限跟着抬到 20
        $configurable = $this->legacyPlan(['customization' => ['transfer_enable' => ['mode' => 'range', 'max' => 1000, 'step' => 100, 'price_per_step' => 2000],
            'device_limit' => ['mode' => 'fixed'], 'speed_limit' => ['mode' => 'fixed']]]);
        $this->assertSame(20, $service->topupPriceFloor($configurable));

        // 单价低于下限 → 保存被拒，错误信息把两个数都摆出来
        try {
            $service->validateConfiguration($this->topupPlan(['price_per_gb' => 8]));
            $this->fail('低于到手价的单价应被拒绝');
        } catch (ApiException $e) {
            $this->assertStringContainsString('¥0.08/GB', $e->getMessage());
            $this->assertStringContainsString('¥0.10/GB', $e->getMessage());
            $this->assertStringContainsString('最低档', $e->getMessage());
        }
        $service->validateConfiguration($this->topupPlan(['price_per_gb' => 10]));   // 恰好等于下限：允许

        // 档位专价折合单价也不能低于下限
        try {
            $service->validateConfiguration($this->topupPlan(['selection' => 'choices', 'choices' => [['gb' => 100, 'price' => 900]]]));
            $this->fail('档位专价折合 9 分/GB 应被拒绝');
        } catch (ApiException $e) {
            $this->assertStringContainsString('100 GB 档位价', $e->getMessage());
        }
        $service->validateConfiguration($this->topupPlan(['selection' => 'choices', 'choices' => [['gb' => 100, 'price' => 1000]]]));
    }

    public function test_topup_config_is_validated_and_never_turns_a_plan_into_a_configurable_one(): void
    {
        $service = new PlanCustomizationService();
        $plan = $this->topupPlan();
        $service->validateConfiguration($plan);
        $this->assertFalse($service->isEnabled($plan), '只配了加购的套餐仍是普通套餐：报价与快照形状不变');
        $this->assertNull($service->quote($plan, 'monthly')['snapshot']);

        foreach ([
            [['mode' => 'weird', 'price_per_gb' => 50], '模式'],
            [['mode' => 'on'], '单价'],
            [['mode' => 'on', 'price_per_gb' => 50, 'min_gb' => 10, 'max_gb' => 5], '上下限'],
            [['mode' => 'on', 'price_per_gb' => 50, 'foo' => 1], '未知字段'],
            [['mode' => 'on', 'price_per_gb' => 50, 'selection' => 'choices'], '至少要填一档'],
            [['mode' => 'on', 'price_per_gb' => 50, 'choices' => [['gb' => 50], ['gb' => 10]]], '递增'],
        ] as [$rule, $keyword]) {
            try {
                $plan->customization = self::FIXED + ['traffic_topup' => $rule];
                $service->validateConfiguration($plan);
                $this->fail('应拒绝：' . json_encode($rule));
            } catch (ApiException $e) {
                $this->assertStringContainsString($keyword, $e->getMessage(), json_encode($rule));
            }
        }
    }

    // ───────────────────────── 报价 ─────────────────────────

    public function test_range_quote_is_gb_times_price_with_step_alignment(): void
    {
        $plan = $this->topupPlan(['min_gb' => 10, 'step_gb' => 10, 'choices' => [['gb' => 10], ['gb' => 25], ['gb' => 50]]]);
        $user = $this->user($plan);
        $service = new PlanCustomizationService();
        $rule = $service->topupRule($plan, $user);
        $this->assertSame([10, 50], $rule['presets'], '25 不在步长格点上，不作快捷按钮');

        $quote = $service->quote($plan, 'traffic_topup', ['topup_gb' => 50], $user);
        $this->assertSame(2500, $quote['amount']);
        $this->assertSame(['topup_gb' => 50], $quote['options']);
        $this->assertSame('traffic_topup', $quote['snapshot']['kind']);
        $this->assertSame($user->next_reset_at, $quote['snapshot']['valid_until'], '有效期到下次重置日');

        foreach ([0, 1001, 25, '50', null] as $bad) {
            try {
                $service->quote($plan, 'traffic_topup', ['topup_gb' => $bad], $user);
                $this->fail('应拒绝 ' . var_export($bad, true));
            } catch (ApiException $e) {
                $this->assertStringContainsString('GB', $e->getMessage());
            }
        }
    }

    public function test_choices_mode_sells_listed_tiers_with_tier_prices_and_addon_surcharge(): void
    {
        $service = new PlanCustomizationService();
        $plan = $this->addonPlan(['mode' => 'on', 'price_per_gb' => 50, 'selection' => 'choices',
            'choices' => [['gb' => 10], ['gb' => 50, 'price' => 2000], ['gb' => 100, 'price' => 3500]]]);
        $plain = $this->user($plan);
        $rule = $service->topupRule($plan, $plain);
        $this->assertSame('choices', $rule['selection']);
        $this->assertSame([[10, 500, 50], [50, 2000, 40], [100, 3500, 35]],
            array_map(fn ($c) => [$c['gb'], $c['amount'], $c['unit']], $rule['choices']), '未定价的档按单价，定价的档按专价');
        $this->assertSame([10, 100], [$rule['min_gb'], $rule['max_gb']]);

        $this->assertSame(2000, $service->quote($plan, 'traffic_topup', ['topup_gb' => 50], $plain)['amount']);
        try {
            $service->quote($plan, 'traffic_topup', ['topup_gb' => 30], $plain);
            $this->fail('不在档位里的 GB 应被拒绝');
        } catch (ApiException $e) {
            $this->assertStringContainsString('档位', $e->getMessage());
        }
        // 持有增值组的用户：档位专价 + 每 GB 加价 0.30
        $premium = $this->user($plan, ['admin_group_ids' => [$this->premium->id]]);
        $this->assertSame(2000 + 30 * 50, $service->quote($plan, 'traffic_topup', ['topup_gb' => 50], $premium)['amount']);
    }

    public function test_users_holding_an_addon_group_pay_the_surcharge_per_gb(): void
    {
        $plan = $this->addonPlan();
        $plain = $this->user($plan, ['plan_options' => $this->resources() + ['addon_groups' => [], 'granted_groups' => []]]);
        $premium = $this->user($plan, ['plan_options' => $this->resources() + ['addon_groups' => [$this->premium->id], 'granted_groups' => [$this->premium->id]]]);
        $service = new PlanCustomizationService();
        $this->assertSame(50 * 10, $service->quote($plan, 'traffic_topup', ['topup_gb' => 10], $plain)['amount']);
        $this->assertSame(80 * 10, $service->quote($plan, 'traffic_topup', ['topup_gb' => 10], $premium)['amount'], '买了 10x 组：每 GB 50 + 30');
        $summary = $service->topupSummary($plan, $premium);
        $this->assertSame([80, 50, 30], [$summary['price_per_gb'], $summary['base_price_per_gb'], $summary['addon_surcharge_per_gb']]);
    }

    public function test_only_the_current_active_subscription_can_top_up(): void
    {
        $plan = $this->topupPlan();
        $other = $this->topupPlan([], ['name' => 'Other']);
        $service = new PlanCustomizationService();
        foreach ([
            [$other, $this->user($plan), '当前订阅'],
            [$plan, $this->user($plan, ['expired_at' => time() - 1]), '到期'],
            [$plan, null, '当前订阅'],
            [$this->legacyPlan(['name' => 'NoTopup']), null, '当前订阅'],
        ] as [$p, $u, $keyword]) {
            try {
                $service->quote($p, 'traffic_topup', ['topup_gb' => 10], $u);
                $this->fail();
            } catch (ApiException $e) {
                $this->assertStringContainsString($keyword, $e->getMessage());
            }
        }
        try {
            $service->quote($this->legacyPlan(['name' => 'Off']), 'traffic_topup', ['topup_gb' => 10], $this->user($this->legacyPlan(['name' => 'Off2'])));
            $this->fail();
        } catch (ApiException $e) {
            $this->assertTrue(str_contains($e->getMessage(), '当前订阅') || str_contains($e->getMessage(), '不支持加购'));
        }
    }

    // ───────────────────────── 下单与开通 ─────────────────────────

    public function test_http_quote_order_and_open_add_traffic_without_touching_anything_else(): void
    {
        $plan = $this->addonPlan();
        $options = $this->resources() + ['addon_groups' => [$this->premium->id], 'granted_groups' => [$this->premium->id]];
        $user = $this->user($plan, ['plan_options' => $options]);
        Sanctum::actingAs($user);

        $payload = ['plan_id' => $plan->id, 'period' => 'traffic_topup', 'topup_gb' => 50];
        $quote = $this->postJson('/api/v1/user/plan/quote', $payload)->assertOk()->json('data');
        $this->assertSame(80 * 50, $quote['amount']);
        $trade = $this->postJson('/api/v1/user/order/save', $payload + ['expected_amount' => $quote['amount']])->assertOk()->json('data');

        $order = Order::where('trade_no', $trade)->firstOrFail();
        $this->assertSame(Order::TYPE_TRAFFIC_TOPUP, (int) $order->type);
        $this->assertSame('traffic_topup', $order->period);
        $this->assertSame(4000, (int) $order->total_amount);
        $this->getJson('/api/v1/user/order/detail?trade_no=' . $trade)->assertOk()
            ->assertJsonPath('data.period', 'traffic_topup')->assertJsonPath('data.plan.name', 'Addon');

        $this->open($order);
        $user->refresh();
        $this->assertSame(150 * self::GB, (int) $user->transfer_enable);
        $this->assertSame(50 * self::GB, (int) $user->transfer_topup);
        $this->assertSame([2, 100, $plan->id], [(int) $user->device_limit, (int) $user->speed_limit, (int) $user->plan_id]);
        $this->assertEquals($options, $user->plan_options, '增值组与规格原样保留');
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);

        $sub = $this->getJson('/api/v1/user/getSubscribe?include_addon_groups=1')->assertOk()->json('data');
        $this->assertSame(150 * self::GB, $sub['transfer_enable']);
        $this->assertSame(50 * self::GB, $sub['traffic_topup']['active_bytes']);
        $this->assertTrue($sub['traffic_topup']['enabled']);
        $this->assertSame(80, $sub['traffic_topup']['price_per_gb']);
        $this->assertSame('range', $sub['traffic_topup']['selection']);
        $this->assertSame([$this->premium->id], $sub['plan_options']['granted_groups'], 'granted_groups 是此刻生效的集合');
        $this->assertSame('10x 高速', $sub['addon_groups'][0]['name']);
    }

    public function test_old_client_without_the_capability_flag_still_gets_three_key_plan_options(): void
    {
        $plan = $this->addonPlan();
        $user = $this->user($plan, ['plan_options' => $this->resources() + ['addon_groups' => [$this->premium->id], 'granted_groups' => [$this->premium->id]]]);
        Sanctum::actingAs($user);
        $sub = $this->getJson('/api/v1/user/getSubscribe')->assertOk()->json('data');
        $this->assertEquals($this->resources(), $sub['plan_options']);
        $this->assertArrayHasKey('traffic_topup', $sub, '新增顶层键对旧前端无害');
        $this->postJson('/api/v1/user/order/save', ['plan_id' => $plan->id, 'period' => 'month_price', 'options' => null, 'expected_amount' => null])->assertOk();
    }

    public function test_topup_order_requires_the_gb_field_and_a_plan_that_sells_it(): void
    {
        $plan = $this->topupPlan();
        Sanctum::actingAs($this->user($plan));
        $this->postJson('/api/v1/user/order/save', ['plan_id' => $plan->id, 'period' => 'traffic_topup'])->assertStatus(422);
        $off = $this->legacyPlan(['name' => 'Off']);
        Sanctum::actingAs($this->user($off));
        $resp = $this->postJson('/api/v1/user/plan/quote', ['plan_id' => $off->id, 'period' => 'traffic_topup', 'topup_gb' => 10]);
        $this->assertNotSame(200, $resp->status());
        $this->assertStringContainsString('不支持加购', $resp->json('message') ?? '');
    }

    // ───────────────────────── 失效：任何清零动作 ─────────────────────────

    public function test_monthly_reset_reclaims_the_topup(): void
    {
        $plan = $this->topupPlan();
        $user = $this->user($plan, ['u' => 90 * self::GB]);
        $this->buyTopup($user, $plan, 50);
        $user->refresh();
        $this->assertSame(150 * self::GB, (int) $user->transfer_enable);

        $user->update(['next_reset_at' => time() - 1]);
        $this->assertTrue(app(TrafficResetService::class)->checkAndReset($user->fresh()->load('plan'), TrafficResetLog::SOURCE_CRON));
        $user->refresh();
        $this->assertSame([100 * self::GB, 0, 0], [(int) $user->transfer_enable, (int) $user->transfer_topup, (int) $user->u]);
        $this->assertSame(50 * self::GB, TrafficResetLog::latest('id')->first()->metadata['expired_topup']);
    }

    public function test_reset_package_advance_cycle_and_manual_reset_all_reclaim_the_topup(): void
    {
        $plan = $this->topupPlan();
        $expect = function (User $user) {
            $this->assertSame([100 * self::GB, 0], [(int) $user->fresh()->transfer_enable, (int) $user->fresh()->transfer_topup]);
        };
        $a = $this->user($plan);
        $this->buyTopup($a, $plan, 50);
        $this->buy($a->fresh(), $plan, 'reset_traffic');
        $expect($a);

        $b = $this->user($plan, ['u' => 149 * self::GB, 'expired_at' => time() + 90 * 86400]);
        $this->buyTopup($b, $plan, 50);
        $b->fresh()->update(['u' => 149 * self::GB]);
        $result = app(AdvanceCycleService::class)->advance($b->fresh());
        $this->assertTrue($result['advanced'] ?? false, json_encode($result));
        $expect($b);

        $c = $this->user($plan);
        $this->buyTopup($c, $plan, 50);
        $this->assertTrue(app(TrafficResetService::class)->manualReset($c->fresh()->load('plan')));
        $expect($c);
    }

    public function test_plan_change_and_new_purchase_drop_the_topup(): void
    {
        $plan = $this->topupPlan();
        $other = $this->legacyPlan(['name' => 'Other', 'transfer_enable' => 200]);

        $changer = $this->user($plan);
        $this->buyTopup($changer, $plan, 50);
        $order = $this->buy($changer->fresh(), $other, 'monthly');
        $this->assertSame(Order::TYPE_UPGRADE, (int) $order->type);
        $this->assertSame([200 * self::GB, 0], [(int) $changer->fresh()->transfer_enable, (int) $changer->fresh()->transfer_topup]);

        $expired = $this->user($plan, ['expired_at' => time() + 86400]);
        $this->buyTopup($expired, $plan, 50);
        $expired->fresh()->update(['expired_at' => time() - 10]);
        $order = $this->buy($expired->fresh(), $plan, 'monthly');
        $this->assertSame(Order::TYPE_NEW_PURCHASE, (int) $order->type);
        $this->assertSame([100 * self::GB, 0], [(int) $expired->fresh()->transfer_enable, (int) $expired->fresh()->transfer_topup]);
    }

    // ───────────────────────── 续费：周期继续则保留 ─────────────────────────

    public function test_plain_renewal_keeps_the_topup_and_is_not_mistaken_for_a_plan_change(): void
    {
        $plan = $this->addonPlan();
        $options = $this->resources() + ['addon_groups' => [$this->premium->id], 'granted_groups' => [$this->premium->id]];
        $user = $this->user($plan, ['plan_options' => $options, 'u' => 10 * self::GB]);
        $this->buyTopup($user, $plan, 50);
        $oldExpiredAt = (int) $user->fresh()->expired_at;

        $order = $this->buy($user->fresh(), $plan, 'monthly', $options);
        $this->assertSame(Order::TYPE_RENEWAL, (int) $order->type, '加购过的人续同一套餐不能被判成改规格');
        $user->refresh();
        $this->assertSame(150 * self::GB, (int) $user->transfer_enable, '周期继续，加购保留');
        $this->assertSame(50 * self::GB, (int) $user->transfer_topup);
        $this->assertGreaterThan($oldExpiredAt, (int) $user->expired_at);
        $this->assertSame(10 * self::GB, (int) $user->u, '普通续费不清零');
    }

    public function test_renewal_that_restarts_the_cycle_reclaims_the_topup(): void
    {
        $plan = $this->topupPlan();
        $user = $this->user($plan, ['expired_at' => time() + 5 * 86400]);
        $this->buyTopup($user, $plan, 50);
        $user->fresh()->update(['u' => 149 * self::GB]);
        $order = $this->buy($user->fresh(), $plan, 'monthly');
        $this->assertSame(Order::TYPE_RENEWAL, (int) $order->type);
        $user->refresh();
        $this->assertSame([100 * self::GB, 0, 0], [(int) $user->transfer_enable, (int) $user->transfer_topup, (int) $user->u]);
    }
}
