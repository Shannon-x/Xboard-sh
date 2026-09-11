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
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 流量加购包：本周期内按 GB 追加流量。
 *
 *   站点设置 traffic_topup_price_per_gb（分/GB）为默认单价；套餐 customization.traffic_topup 可覆盖 / 关闭；
 *   增值组规则 topup_price_per_gb 给持有该组的用户加价 —— 买了 10x 组的人每 GB 更贵。
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
    /** admin_setting() 的桩数据：每个用例在 setUp 里重置，用例内可直接改。 */
    public static array $settings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = $this->group('基础');
        $this->premium = $this->group('10x 高速');
        self::$settings = ['traffic_topup_price_per_gb' => 50, 'traffic_topup_presets' => '10,50,100,200,5000'];
        $this->app->instance(Setting::class, new class extends Setting {
            public function __construct() {}
            public function get(string $key, mixed $default = null): mixed { return TrafficTopupTest::$settings[$key] ?? null; }
        });
    }

    private function group(string $name): ServerGroup
    {
        $group = new ServerGroup();
        $group->name = $name;
        $group->save();
        return $group;
    }

    /** 旧式套餐：没有 customization，加购规则完全跟随站点设置。 */
    private function legacyPlan(array $overrides = []): Plan
    {
        return Plan::create($overrides + [
            'name' => 'Legacy', 'capacity_limit' => null, 'group_id' => $this->base->id,
            'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => 100,
            'reset_traffic_method' => 1,
            'prices' => ['monthly' => 3, 'reset_traffic' => 3],
        ]);
    }

    /** 带可选购增值组的套餐；premium 组对加购每 GB 再加 30 分。 */
    private function addonPlan(?array $topupRule = null): Plan
    {
        $customization = [
            'transfer_enable' => ['mode' => 'fixed'], 'device_limit' => ['mode' => 'fixed'], 'speed_limit' => ['mode' => 'fixed'],
            'addon_groups' => [(string) $this->premium->id => ['mode' => 'optional', 'price' => 500, 'topup_price_per_gb' => 30]],
        ];
        if ($topupRule !== null) $customization['traffic_topup'] = $topupRule;
        return $this->legacyPlan(['name' => 'Addon', 'customization' => $customization]);
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
        $order = OrderService::createFromRequest($user, $plan, 'traffic_topup', null, ['topup_gb' => $gb]);
        return $this->open($order);
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

    // ───────────────────────── 规则与报价 ─────────────────────────

    public function test_legacy_plan_inherits_site_price_and_presets_within_bounds(): void
    {
        $service = new PlanCustomizationService();
        $rule = $service->topupRule($this->legacyPlan());
        $this->assertSame(50, $rule['price_per_gb']);
        $this->assertSame([1, 1000], [$rule['min_gb'], $rule['max_gb']]);
        $this->assertSame([10, 50, 100, 200], $rule['presets'], '超出上限的 5000 档位被过滤');

        self::$settings['traffic_topup_price_per_gb'] = 0;
        $this->assertNull($service->topupRule($this->legacyPlan()), '站点未设单价 = 不开放，旧套餐行为不变');
    }

    public function test_plan_can_override_or_switch_off_the_site_rule(): void
    {
        $service = new PlanCustomizationService();
        $this->assertNull($service->topupRule($this->addonPlan(['mode' => 'off'])));
        $custom = $service->topupRule($this->addonPlan(['mode' => 'custom', 'price_per_gb' => 80, 'min_gb' => 5, 'max_gb' => 50]));
        $this->assertSame([80, 5, 50, [10, 50]], [$custom['price_per_gb'], $custom['min_gb'], $custom['max_gb'], $custom['presets']]);
        $partial = $service->topupRule($this->addonPlan(['mode' => 'custom', 'price_per_gb' => 80]));
        $this->assertSame([80, 1, 1000], [$partial['price_per_gb'], $partial['min_gb'], $partial['max_gb']], '未填的项回落站点设置');
    }

    public function test_topup_config_is_validated_and_never_turns_a_plan_into_a_configurable_one(): void
    {
        $service = new PlanCustomizationService();
        $plan = $this->legacyPlan(['customization' => [
            'transfer_enable' => ['mode' => 'fixed'], 'device_limit' => ['mode' => 'fixed'], 'speed_limit' => ['mode' => 'fixed'],
            'traffic_topup' => ['mode' => 'custom', 'price_per_gb' => 80],
        ]]);
        $service->validateConfiguration($plan);
        $this->assertFalse($service->isEnabled($plan), '只配了加购的套餐仍是普通套餐：报价与快照形状不变');
        $this->assertNull($service->quote($plan, 'monthly')['snapshot']);
        $this->assertArrayNotHasKey('traffic_topup', PlanResource::make($plan)->resolve()['customization'] ?? []);

        foreach ([
            [['mode' => 'weird'], '模式'],
            [['mode' => 'custom', 'price_per_gb' => 0], '单价'],
            [['mode' => 'custom', 'min_gb' => 10, 'max_gb' => 5], '上下限'],
            [['mode' => 'custom', 'foo' => 1], '未知字段'],
        ] as [$rule, $keyword]) {
            try {
                $plan->customization = ['transfer_enable' => ['mode' => 'fixed'], 'device_limit' => ['mode' => 'fixed'],
                    'speed_limit' => ['mode' => 'fixed'], 'traffic_topup' => $rule];
                $service->validateConfiguration($plan);
                $this->fail('应拒绝：' . json_encode($rule));
            } catch (ApiException $e) {
                $this->assertStringContainsString($keyword, $e->getMessage());
            }
        }
    }

    public function test_quote_is_gb_times_price_and_bounded(): void
    {
        $plan = $this->legacyPlan();
        $user = $this->user($plan);
        $quote = (new PlanCustomizationService())->quote($plan, 'traffic_topup', ['topup_gb' => 50], $user);
        $this->assertSame(2500, $quote['amount']);
        $this->assertSame(['topup_gb' => 50], $quote['options']);
        $this->assertSame('traffic_topup', $quote['snapshot']['kind']);
        $this->assertSame(50, $quote['snapshot']['topup_gb']);
        $this->assertSame($user->next_reset_at, $quote['snapshot']['valid_until'], '有效期到下次重置日');

        foreach ([0, 1001, '50', null] as $bad) {
            try {
                (new PlanCustomizationService())->quote($plan, 'traffic_topup', ['topup_gb' => $bad], $user);
                $this->fail('应拒绝 ' . var_export($bad, true));
            } catch (ApiException $e) {
                $this->assertStringContainsString('GB 之间', $e->getMessage());
            }
        }
    }

    public function test_users_holding_an_addon_group_pay_the_surcharge_per_gb(): void
    {
        $plan = $this->addonPlan();
        $plain = $this->user($plan, ['plan_options' => $this->resources() + ['addon_groups' => [], 'granted_groups' => []]]);
        $premium = $this->user($plan, ['plan_options' => $this->resources() + ['addon_groups' => [$this->premium->id], 'granted_groups' => [$this->premium->id]]]);
        $granted = $this->user($plan, ['admin_group_ids' => [$this->premium->id]]);   // 管理员手动授予，同样计入
        $service = new PlanCustomizationService();
        $this->assertSame(50 * 10, $service->quote($plan, 'traffic_topup', ['topup_gb' => 10], $plain)['amount']);
        $this->assertSame(80 * 10, $service->quote($plan, 'traffic_topup', ['topup_gb' => 10], $premium)['amount'], '买了 10x 组：每 GB 50 + 30');
        $this->assertSame(80 * 10, $service->quote($plan, 'traffic_topup', ['topup_gb' => 10], $granted)['amount']);
        $summary = $service->topupSummary($plan, $premium);
        $this->assertSame([80, 50, 30], [$summary['price_per_gb'], $summary['base_price_per_gb'], $summary['addon_surcharge_per_gb']]);
    }

    public function test_only_the_current_active_subscription_can_top_up(): void
    {
        $plan = $this->legacyPlan();
        $other = $this->legacyPlan(['name' => 'Other']);
        $service = new PlanCustomizationService();
        try {
            $service->quote($other, 'traffic_topup', ['topup_gb' => 10], $this->user($plan));
            $this->fail();
        } catch (ApiException $e) {
            $this->assertStringContainsString('当前订阅', $e->getMessage());
        }
        try {
            $service->quote($plan, 'traffic_topup', ['topup_gb' => 10], $this->user($plan, ['expired_at' => time() - 1]));
            $this->fail();
        } catch (ApiException $e) {
            $this->assertStringContainsString('到期', $e->getMessage());
        }
        try {
            $service->quote($plan, 'traffic_topup', ['topup_gb' => 10], null);
            $this->fail();
        } catch (ApiException $e) {
            $this->assertStringContainsString('当前订阅', $e->getMessage());
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
        // 旧前端订单列表 / 详情照常渲染：周期原样透传，plan 里只回填名称与本单金额
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
        $this->assertSame([$this->premium->id], $sub['plan_options']['granted_groups'], 'granted_groups 是此刻生效的集合');
        $this->assertSame('10x 高速', $sub['addon_groups'][0]['name']);
        $this->assertSame('purchased', $sub['addon_groups'][0]['source']);
    }

    public function test_old_client_without_the_capability_flag_still_gets_three_key_plan_options(): void
    {
        $plan = $this->addonPlan();
        $user = $this->user($plan, ['plan_options' => $this->resources() + ['addon_groups' => [$this->premium->id], 'granted_groups' => [$this->premium->id]]]);
        Sanctum::actingAs($user);
        $sub = $this->getJson('/api/v1/user/getSubscribe')->assertOk()->json('data');
        $this->assertEquals($this->resources(), $sub['plan_options']);
        $this->assertArrayHasKey('traffic_topup', $sub, '新增顶层键对旧前端无害');
        $this->postJson('/api/v1/user/order/save', ['plan_id' => $plan->id, 'period' => 'month_price', 'options' => null, 'expected_amount' => null])
            ->assertOk();
    }

    public function test_topup_order_requires_the_gb_field_and_a_sellable_rule(): void
    {
        $plan = $this->legacyPlan();
        Sanctum::actingAs($this->user($plan));
        $this->postJson('/api/v1/user/order/save', ['plan_id' => $plan->id, 'period' => 'traffic_topup'])->assertStatus(422);
        self::$settings['traffic_topup_price_per_gb'] = 0;
        $resp = $this->postJson('/api/v1/user/plan/quote', ['plan_id' => $plan->id, 'period' => 'traffic_topup', 'topup_gb' => 10]);
        $this->assertNotSame(200, $resp->status());
        $this->assertStringContainsString('不支持加购', $resp->json('message') ?? '');
    }

    // ───────────────────────── 失效：任何清零动作 ─────────────────────────

    public function test_monthly_reset_reclaims_the_topup(): void
    {
        $plan = $this->legacyPlan();
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
        $plan = $this->legacyPlan();
        $expect = function (User $user) {
            $this->assertSame([100 * self::GB, 0], [(int) $user->fresh()->transfer_enable, (int) $user->fresh()->transfer_topup]);
        };

        $a = $this->user($plan);
        $this->buyTopup($a, $plan, 50);
        $this->buy($a->fresh(), $plan, 'reset_traffic');
        $expect($a);

        $b = $this->user($plan, ['u' => 149 * self::GB, 'expired_at' => time() + 90 * 86400]);
        $this->buyTopup($b, $plan, 50);
        $b->refresh();
        $b->update(['u' => 149 * self::GB]);
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
        $plan = $this->legacyPlan();
        $other = $this->legacyPlan(['name' => 'Other', 'transfer_enable' => 200]);

        $changer = $this->user($plan);
        $this->buyTopup($changer, $plan, 50);
        $order = $this->buy($changer->fresh(), $other, 'monthly');
        $this->assertSame(Order::TYPE_UPGRADE, (int) $order->type);
        $this->assertSame([200 * self::GB, 0], [(int) $changer->fresh()->transfer_enable, (int) $changer->fresh()->transfer_topup]);

        $expired = $this->user($plan, ['expired_at' => time() + 86400]);
        $this->buyTopup($expired, $plan, 50);
        $expired->fresh()->update(['expired_at' => time() - 10]);   // 过期后再买 = 新购
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
        $plan = $this->legacyPlan();
        $user = $this->user($plan, ['expired_at' => time() + 5 * 86400]);
        $this->buyTopup($user, $plan, 50);
        $user->fresh()->update(['u' => 149 * self::GB]);   // 150GB 全用完 → 续费重开周期
        $order = $this->buy($user->fresh(), $plan, 'monthly');
        $this->assertSame(Order::TYPE_RENEWAL, (int) $order->type);
        $user->refresh();
        $this->assertSame([100 * self::GB, 0, 0], [(int) $user->transfer_enable, (int) $user->transfer_topup, (int) $user->u]);
    }
}
