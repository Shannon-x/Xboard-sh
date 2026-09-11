<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Http\Resources\PlanResource;
use App\Jobs\NodeUserSyncJob;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PlanCustomizationService;
use App\Services\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionProperty;
use Tests\TestCase;

/**
 * 增值节点组：一个增值等级 = 一个 ServerGroup。
 *
 *   套餐 customization.addon_groups = { "<组id>": {mode: included|optional, price: 分/月} }
 *   客户 plan_options.addon_groups   = 勾选的 optional 组（续费重新报价用）
 *   客户 plan_options.granted_groups = included ∪ 已购（节点可见性 / 节点端名单直接读）
 *
 * 覆盖：配置校验、计价（按月定价随周期缩放、重置不重复计费）、选择校验、
 * 下单→开通→生效集合、节点可见性与节点端名单（安全关键）、续费保留、
 * 同套餐加购算变更、管理员不能撤掉已售组、旧配置零影响、同步任务携带失去的组。
 */
class AddonGroupTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1073741824;

    private ServerGroup $base;
    private ServerGroup $premium;   // optional, ¥5/月
    private ServerGroup $vip;       // included

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = $this->group('基础');
        $this->premium = $this->group('10x 高速');
        $this->vip = $this->group('VIP 专线');
    }

    private function group(string $name): ServerGroup
    {
        $group = new ServerGroup();
        $group->name = $name;
        $group->save();
        return $group;
    }

    private function server(string $name, array $groupIds, bool $show = true): Server
    {
        $server = new Server();
        $server->type = 'trojan';
        $server->name = $name;
        $server->rate = 1;
        $server->host = 'example.test';
        $server->port = '443';
        $server->server_port = 443;
        $server->group_ids = array_map('strval', $groupIds);
        $server->show = $show;
        $server->save();
        return $server;
    }

    /** 三项资源全部固定，只开放增值组，便于隔离验证增值组计价。 */
    private function plan(?array $addons = null, array $overrides = []): Plan
    {
        $customization = [
            'transfer_enable' => ['mode' => 'fixed'],
            'device_limit' => ['mode' => 'fixed'],
            'speed_limit' => ['mode' => 'fixed'],
        ];
        if ($addons !== null) {
            $customization['addon_groups'] = $addons;
        }
        return Plan::create($overrides + [
            'name' => 'Base', 'capacity_limit' => null, 'group_id' => $this->base->id,
            'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => 100,
            'reset_traffic_method' => 1,
            'prices' => ['monthly' => 3, 'quarterly' => 8.55, 'reset_traffic' => 3],
            'customization' => $customization,
        ]);
    }

    private function sellablePlan(): Plan
    {
        return $this->plan([
            (string) $this->premium->id => ['mode' => 'optional', 'price' => 500],
            (string) $this->vip->id => ['mode' => 'included'],
        ]);
    }

    private function user(array $attributes = []): User
    {
        return User::create($attributes + [
            'email' => Str::random(12) . '@example.test', 'password' => 'test',
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'balance' => 0, 'transfer_enable' => 100 * self::GB, 'expired_at' => time() + 30 * 86400,
        ]);
    }

    private function resources(): array
    {
        return ['transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => 100];
    }

    private function open(Order $order): Order
    {
        $order->status = Order::STATUS_PROCESSING;
        $order->save();
        (new OrderService($order))->open();
        return $order->fresh();
    }

    // ───────────────────────── 计价 ─────────────────────────

    public function test_addon_is_priced_per_month_and_scaled_by_the_period_discount(): void
    {
        $plan = $this->sellablePlan();
        $service = new PlanCustomizationService();
        $options = $this->resources() + ['addon_groups' => [$this->premium->id]];

        $monthly = $service->quote($plan, 'monthly', $options);
        $this->assertSame(800, $monthly['amount']);                       // 300 + 500
        $this->assertSame(500, $monthly['breakdown']['addon_groups']);

        // 季付 8.55 元 = 月付 3 元 × 2.85：增值组按同一比例缩放，年付折扣自动传导。
        $quarterly = $service->quote($plan, 'quarterly', $options);
        $this->assertSame(855 + 1425, $quarterly['amount']);
        $this->assertSame(1425, $quarterly['breakdown']['addon_groups']);

        $none = $service->quote($plan, 'monthly', $this->resources() + ['addon_groups' => []]);
        $this->assertSame(300, $none['amount']);
        $this->assertSame(0, $none['breakdown']['addon_groups']);
    }

    public function test_reset_package_does_not_rebill_addons_and_keeps_them(): void
    {
        $plan = $this->sellablePlan();
        $user = $this->user([
            'plan_id' => $plan->id, 'group_id' => $this->base->id,
            'plan_options' => $this->resources() + [
                'addon_groups' => [$this->premium->id],
                'granted_groups' => [$this->premium->id, $this->vip->id],
            ],
        ]);
        $quote = (new PlanCustomizationService())->quote($plan, 'reset_traffic', null, $user);
        $this->assertSame(300, $quote['amount']);
        $this->assertSame(0, $quote['breakdown']['addon_groups']);
        $this->assertSame([$this->premium->id], $quote['options']['addon_groups']);
    }

    // ───────────────────────── 配置校验 ─────────────────────────

    public function test_base_group_cannot_be_sold_as_an_addon_of_itself(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('基础权限组不能再作为增值组');
        (new PlanCustomizationService())->validateConfiguration(
            $this->plan([(string) $this->base->id => ['mode' => 'optional', 'price' => 100]])
        );
    }

    public function test_included_addon_cannot_carry_a_price(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('不能设置加价');
        (new PlanCustomizationService())->validateConfiguration(
            $this->plan([(string) $this->vip->id => ['mode' => 'included', 'price' => 100]])
        );
    }

    public function test_addon_must_reference_an_existing_group(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('不存在的权限组');
        (new PlanCustomizationService())->validateConfiguration(
            $this->plan(['999999' => ['mode' => 'optional', 'price' => 100]])
        );
    }

    public function test_addon_config_rejects_unknown_fields_and_list_form(): void
    {
        $service = new PlanCustomizationService();
        try {
            $service->validateConfiguration($this->plan([(string) $this->premium->id => ['mode' => 'optional', 'price' => 1, 'max' => 3]]));
            $this->fail('未知字段应被拒绝');
        } catch (ApiException $e) {
            $this->assertStringContainsString('未知字段', $e->getMessage());
        }
        try {
            $service->validateConfiguration($this->plan([['mode' => 'optional', 'price' => 1]]));
            $this->fail('列表形式应被拒绝');
        } catch (ApiException $e) {
            $this->assertStringContainsString('权限组 ID 为键', $e->getMessage());
        }
    }

    // ───────────────────────── 选择校验 ─────────────────────────

    public function test_included_group_cannot_be_selected_and_unlisted_group_is_rejected(): void
    {
        $plan = $this->sellablePlan();
        $service = new PlanCustomizationService();
        foreach ([[$this->vip->id], [$this->base->id], [999999], ['5']] as $bad) {
            try {
                $service->quote($plan, 'monthly', $this->resources() + ['addon_groups' => $bad]);
                $this->fail('应拒绝 ' . json_encode($bad));
            } catch (ApiException $e) {
                $this->assertStringContainsString('增值节点不在可售选项内', $e->getMessage());
            }
        }
    }

    public function test_legacy_plan_rejects_addon_selection_but_tolerates_an_empty_list(): void
    {
        $plan = $this->plan();   // 无 addon_groups：全固定 → 旧流程
        $service = new PlanCustomizationService();

        $quote = $service->quote($plan, 'monthly', $this->resources() + ['addon_groups' => []]);
        $this->assertSame(300, $quote['amount']);
        $this->assertNull($quote['snapshot'], '旧套餐不能因为一个空列表就被当成自选套餐');

        $this->expectException(ApiException::class);
        $service->quote($plan, 'monthly', $this->resources() + ['addon_groups' => [$this->premium->id]]);
    }

    // ───────────────────────── 下单 → 开通 → 生效集合 ─────────────────────────

    public function test_order_grants_included_and_purchased_groups_on_open(): void
    {
        $plan = $this->sellablePlan();
        $user = $this->user();

        $order = OrderService::createFromRequest($user, $plan, 'monthly', null, $this->resources() + ['addon_groups' => [$this->premium->id]]);
        $this->assertSame(800, $order->total_amount);
        $this->assertSame([$this->premium->id], $order->plan_snapshot['options']['addon_groups']);
        $this->assertEqualsCanonicalizing([$this->premium->id, $this->vip->id], $order->plan_snapshot['granted_groups']);

        $this->open($order);
        $user->refresh();
        $this->assertSame($this->base->id, (int) $user->group_id, '基础组不变');
        $this->assertSame([$this->premium->id], $user->plan_options['addon_groups']);
        $this->assertEqualsCanonicalizing([$this->premium->id, $this->vip->id], $user->plan_options['granted_groups']);
        $this->assertEqualsCanonicalizing([$this->base->id, $this->premium->id, $this->vip->id], $user->effectiveGroupIds());
    }

    public function test_buying_without_addons_still_grants_included_groups(): void
    {
        $plan = $this->sellablePlan();
        $user = $this->user();
        $order = OrderService::createFromRequest($user, $plan, 'monthly', null, $this->resources());
        $this->assertSame(300, $order->total_amount);
        $this->open($order);
        $user->refresh();
        $this->assertSame([], $user->plan_options['addon_groups']);
        $this->assertSame([$this->vip->id], $user->plan_options['granted_groups']);
    }

    // ───────────────────────── 可见性与节点端名单（安全关键） ─────────────────────────

    public function test_visibility_follows_granted_groups(): void
    {
        $baseNode = $this->server('base', [$this->base->id]);
        $premiumNode = $this->server('premium', [$this->premium->id]);
        $vipNode = $this->server('vip', [$this->vip->id]);
        $bothNode = $this->server('both', [$this->base->id, $this->premium->id]);
        $this->server('hidden', [$this->premium->id], show: false);

        $plan = $this->sellablePlan();
        $buyer = $this->user(['plan_id' => $plan->id, 'group_id' => $this->base->id, 'plan_options' => $this->resources() + [
            'addon_groups' => [$this->premium->id], 'granted_groups' => [$this->premium->id, $this->vip->id],
        ]]);
        $legacy = $this->user(['plan_id' => $plan->id, 'group_id' => $this->base->id]);   // plan_options = null

        $names = fn (User $u) => collect(ServerService::getAvailableServers($u))->pluck('name')->sort()->values()->all();
        $this->assertSame(['base', 'both', 'premium', 'vip'], $names($buyer));
        $this->assertSame(['base', 'both'], $names($legacy), '没买的看不到增值组独占的节点；同时挂在基础组的节点照常可见');
    }

    public function test_node_user_list_only_contains_users_entitled_to_that_node(): void
    {
        $baseNode = $this->server('base', [$this->base->id]);
        $premiumNode = $this->server('premium', [$this->premium->id]);

        $plan = $this->sellablePlan();
        $buyer = $this->user(['plan_id' => $plan->id, 'group_id' => $this->base->id, 'plan_options' => $this->resources() + [
            'addon_groups' => [$this->premium->id], 'granted_groups' => [$this->premium->id, $this->vip->id],
        ]]);
        $legacy = $this->user(['plan_id' => $plan->id, 'group_id' => $this->base->id]);
        $this->user(['plan_id' => $plan->id, 'group_id' => $this->base->id, 'banned' => 1, 'plan_options' => $this->resources() + [
            'addon_groups' => [$this->premium->id], 'granted_groups' => [$this->premium->id],
        ]]);

        $ids = fn (Server $node) => ServerService::getAvailableUsers($node)->pluck('id')->sort()->values()->all();
        $this->assertEqualsCanonicalizing([$buyer->id, $legacy->id], $ids($baseNode));
        $this->assertSame([$buyer->id], $ids($premiumNode), '10x 节点的名单里只能有买了的人（且封禁者不在）');
    }

    // ───────────────────────── 续费 / 变更 / 管理员 ─────────────────────────

    public function test_renewal_without_options_keeps_purchased_addons(): void
    {
        $plan = $this->sellablePlan();
        $user = $this->user(['plan_id' => $plan->id, 'group_id' => $this->base->id, 'plan_options' => $this->resources() + [
            'addon_groups' => [$this->premium->id], 'granted_groups' => [$this->premium->id, $this->vip->id],
        ]]);
        $quote = (new PlanCustomizationService())->quote($plan, 'monthly', null, $user);
        $this->assertSame(800, $quote['amount']);
        $this->assertSame([$this->premium->id], $quote['options']['addon_groups']);
        $this->assertArrayNotHasKey('granted_groups', $quote['options'], '派生键不能混进选择里');
    }

    public function test_adding_an_addon_on_the_same_plan_is_a_plan_change(): void
    {
        $plan = $this->sellablePlan();
        $user = $this->user(['plan_id' => $plan->id, 'group_id' => $this->base->id,
            'device_limit' => 2, 'speed_limit' => 100,
            'plan_options' => $this->resources() + ['addon_groups' => [], 'granted_groups' => [$this->vip->id]],
        ]);
        $order = OrderService::createFromRequest($user, $plan, 'monthly', null, $this->resources() + ['addon_groups' => [$this->premium->id]]);
        $this->assertSame(Order::TYPE_UPGRADE, (int) $order->type, '周期中途加购增值组 = 套餐变更，而不是叠时长的续费');
    }

    public function test_admin_cannot_drop_an_addon_a_subscriber_already_bought(): void
    {
        $plan = $this->sellablePlan();
        $this->user(['plan_id' => $plan->id, 'group_id' => $this->base->id, 'plan_options' => $this->resources() + [
            'addon_groups' => [$this->premium->id], 'granted_groups' => [$this->premium->id, $this->vip->id],
        ]]);
        $plan->customization = [
            'transfer_enable' => ['mode' => 'fixed'], 'device_limit' => ['mode' => 'fixed'], 'speed_limit' => ['mode' => 'fixed'],
            'addon_groups' => [(string) $this->vip->id => ['mode' => 'included']],   // 把 premium 撤了
        ];
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('已有用户或待处理订单无法续费');
        (new PlanCustomizationService())->validateExistingSubscribers($plan);
    }

    // ───────────────────────── 旧配置零影响 ─────────────────────────

    public function test_legacy_user_and_plan_are_untouched(): void
    {
        $baseNode = $this->server('base', [$this->base->id]);
        $this->server('premium', [$this->premium->id]);
        $plan = $this->plan();   // 无增值组
        $user = $this->user(['plan_id' => $plan->id, 'group_id' => $this->base->id]);

        $this->assertSame([$this->base->id], $user->effectiveGroupIds());
        $this->assertSame(['base'], collect(ServerService::getAvailableServers($user))->pluck('name')->all());
        $this->assertSame([$user->id], ServerService::getAvailableUsers($baseNode)->pluck('id')->all());

        $quote = (new PlanCustomizationService())->quote($plan, 'monthly', null, $user);
        $this->assertNull($quote['snapshot']);
        $this->assertSame(300, $quote['amount']);
    }

    public function test_configurable_plan_without_addons_keeps_the_exact_snapshot_shape(): void
    {
        // #26 的可自选套餐（有范围规则、无增值组）：快照 options 里不能多出 addon_groups 键。
        $plan = $this->plan(null, ['customization' => [
            'transfer_enable' => ['mode' => 'range', 'max' => 200, 'step' => 100, 'price_per_step' => 60],
            'device_limit' => ['mode' => 'fixed'], 'speed_limit' => ['mode' => 'fixed'],
        ]]);
        $quote = (new PlanCustomizationService())->quote($plan, 'monthly', ['transfer_enable' => 200, 'device_limit' => 2, 'speed_limit' => 100]);
        $this->assertSame(['transfer_enable' => 200, 'device_limit' => 2, 'speed_limit' => 100], $quote['snapshot']['options']);
        $this->assertArrayNotHasKey('granted_groups', $quote['snapshot']);
        $this->assertArrayNotHasKey('addon_groups', $quote['breakdown']);
    }

    // ───────────────────────── 展示与同步 ─────────────────────────

    public function test_plan_resource_embeds_group_name_and_count_but_no_node_details(): void
    {
        $this->server('premium-a', [$this->premium->id]);
        $this->server('premium-b', [$this->premium->id]);
        $plan = $this->sellablePlan();

        $payload = PlanResource::make($plan)->resolve();
        $addons = $payload['customization']['addon_groups'];
        $this->assertSame('10x 高速', $addons[(string) $this->premium->id]['name']);
        $this->assertSame(2, $addons[(string) $this->premium->id]['server_count']);
        $this->assertSame('optional', $addons[(string) $this->premium->id]['mode']);
        $this->assertSame(500, $addons[(string) $this->premium->id]['price']);
        $this->assertSame('included', $addons[(string) $this->vip->id]['mode']);
        $this->assertSame(['mode', 'price', 'name', 'server_count'], array_keys($addons[(string) $this->premium->id]), '不得暴露节点名称 / 地址');
    }

    // ───────────────────────── 前向兼容：旧客户端 + 新后端 ─────────────────────────

    public function test_guest_http_quote_accepts_optional_addon_groups(): void
    {
        $plan = $this->sellablePlan();
        $this->postJson('/api/v1/guest/plan/quote', [
            'plan_id' => $plan->id, 'period' => 'month_price',
            'options' => $this->resources() + ['addon_groups' => [$this->premium->id]],
        ])->assertOk()->assertJsonPath('data.amount', 800)
            ->assertJsonPath('data.options.addon_groups', [$this->premium->id]);
        $this->assertSame(0, Order::count());
    }

    public function test_old_client_can_quote_with_echoed_derived_groups_and_then_order(): void
    {
        $plan = $this->sellablePlan();
        $options = $this->resources() + [
            'addon_groups' => [$this->premium->id],
            'granted_groups' => [$this->premium->id, $this->vip->id, 999999],
        ];
        $user = $this->user(['plan_id' => $plan->id, 'group_id' => $this->base->id,
            'device_limit' => 2, 'speed_limit' => 100, 'plan_options' => $options]);
        \Laravel\Sanctum\Sanctum::actingAs($user);
        $payload = ['plan_id' => $plan->id, 'period' => 'month_price', 'options' => $options];
        $quote = $this->postJson('/api/v1/user/plan/quote', $payload)->assertOk()
            ->assertJsonPath('data.amount', 800)->json('data');
        $this->postJson('/api/v1/user/order/save', $payload + ['expected_amount' => $quote['amount']])->assertOk();
        $order = Order::firstOrFail();
        $this->assertSame(800, (int) $order->total_amount);
        $this->assertSame([$this->premium->id, $this->vip->id], $order->plan_snapshot['granted_groups']);
        $this->assertArrayNotHasKey('granted_groups', $quote['options']);
    }

    public function test_quote_and_order_accept_valid_integer_strings_from_form_clients(): void
    {
        $plan = $this->sellablePlan();
        \Laravel\Sanctum\Sanctum::actingAs($this->user());
        $payload = ['plan_id' => (string) $plan->id, 'period' => 'month_price', 'options' => [
            'transfer_enable' => '100', 'device_limit' => '2', 'speed_limit' => '100',
            'addon_groups' => [(string) $this->premium->id],
        ]];
        $this->postJson('/api/v1/user/plan/quote', $payload)->assertOk()->assertJsonPath('data.amount', 800);
        $this->postJson('/api/v1/user/order/save', $payload + ['expected_amount' => '800'])->assertOk();
        $this->assertSame([$this->premium->id], Order::firstOrFail()->plan_snapshot['options']['addon_groups']);
    }

    public function test_null_optional_selection_is_equivalent_to_omitting_it(): void
    {
        $plan = $this->plan();
        \Laravel\Sanctum\Sanctum::actingAs($this->user());
        $payload = ['plan_id' => $plan->id, 'period' => 'month_price', 'options' => null];
        $this->postJson('/api/v1/user/plan/quote', $payload)->assertOk()->assertJsonPath('data.amount', 300);
        $this->postJson('/api/v1/user/order/save', $payload + ['expected_amount' => null])->assertOk();
        $this->assertNull(Order::firstOrFail()->plan_snapshot);
    }

    public function test_first_generation_customization_client_gets_safe_options_and_actual_renewal_price(): void
    {
        $plan = $this->sellablePlan();
        $options = $this->resources() + [
            'addon_groups' => [$this->premium->id],
            'granted_groups' => [$this->premium->id, $this->vip->id],
        ];
        $user = $this->user(['plan_id' => $plan->id, 'group_id' => $this->base->id,
            'device_limit' => 2, 'speed_limit' => 100, 'plan_options' => $options]);
        \Laravel\Sanctum\Sanctum::actingAs($user);
        // The released theme iterates Object.keys(plan_options), assuming three resource keys.
        // JSON object member order is not a contract; MySQL normalizes JSON key order.
        $this->getJson('/api/v1/user/info')->assertOk()->assertJsonPath('data.plan_options', fn ($actual) => $actual == $this->resources());
        $this->getJson('/api/v1/user/getSubscribe')->assertOk()->assertJsonPath('data.plan_options', fn ($actual) => $actual == $this->resources());
        $this->getJson('/api/v1/user/plan/fetch?include_customization=1&id=' . $plan->id)->assertOk()
            ->assertJsonPath('data.month_price', 800)->assertJsonPath('data.customization', null);
        $this->assertEquals($options, $user->fresh()->plan_options, 'Compatibility views must never overwrite purchased entitlements');

        $this->getJson('/api/v1/user/info?include_addon_groups=1')->assertOk()->assertJsonPath('data.plan_options', fn ($actual) => $actual == $options);
        $this->getJson('/api/v1/user/plan/fetch?include_customization=1&include_addon_groups=1&id=' . $plan->id)->assertOk()
            ->assertJsonPath('data.month_price', 300)
            ->assertJsonPath('data.customization.addon_groups.' . $this->premium->id . '.price', 500);
        $payload = ['plan_id' => $plan->id, 'period' => 'month_price', 'options' => $this->resources()];
        $this->postJson('/api/v1/user/plan/quote', $payload)->assertOk()->assertJsonPath('data.amount', 800);
        $this->postJson('/api/v1/user/order/save', $payload + ['expected_amount' => 800])->assertOk();
        $order = Order::firstOrFail();
        $this->assertSame(Order::TYPE_RENEWAL, $order->type);
        $this->assertSame([$this->premium->id], $order->plan_snapshot['options']['addon_groups']);
    }

    public function test_node_pull_preserves_sold_included_groups_after_catalog_change(): void
    {
        $plan = $this->sellablePlan();
        $node = $this->server('Included group', [$this->vip->id]);
        $user = $this->user(['plan_id' => $plan->id, 'plan_options' => $this->resources() + [
            'addon_groups' => [], 'granted_groups' => [$this->vip->id],
        ]]);
        PlanCustomizationService::addonGroupIdsInUse();
        $config = $plan->customization;
        unset($config['addon_groups'][$this->vip->id]);
        $candidate = clone $plan;
        $candidate->customization = $config;
        (new PlanCustomizationService())->validateExistingSubscribers($candidate);
        $plan->update(['customization' => $config]);
        $this->assertContains($node->id, array_column(ServerService::getAvailableServers($user), 'id'));
        $this->assertContains($user->id, ServerService::getAvailableUsers($node)->pluck('id')->all());
    }

    public function test_imported_or_queued_entitlement_invalidates_membership_cache(): void
    {
        $node = $this->server('Historic grant', [$this->vip->id]);
        $this->assertSame([], PlanCustomizationService::addonGroupIdsInUse());
        $user = $this->user();
        $user->update(['plan_options' => $this->resources() + ['addon_groups' => [], 'granted_groups' => [$this->vip->id]]]);
        $this->assertContains($user->id, ServerService::getAvailableUsers($node)->pluck('id')->all());
    }

    /** 只认识三项资源键的旧前端给买过增值组的用户续费：不能静默退掉他的 10x 节点。 */
    public function test_old_client_omitting_addon_groups_keeps_purchased_addons_on_renewal(): void
    {
        $plan = $this->sellablePlan();
        $user = $this->user(['plan_id' => $plan->id, 'group_id' => $this->base->id, 'plan_options' => $this->resources() + [
            'addon_groups' => [$this->premium->id], 'granted_groups' => [$this->premium->id, $this->vip->id],
        ]]);
        $quote = (new PlanCustomizationService())->quote($plan, 'monthly', $this->resources(), $user);
        $this->assertSame(800, $quote['amount'], '缺席的 addon_groups 应继承已购，价格仍含增值组');
        $this->assertSame([$this->premium->id], $quote['options']['addon_groups']);
    }

    /** 旧前端给买过增值组的用户买流量重置包：不能被判成"更改规格"。 */
    public function test_old_client_can_buy_a_reset_package_without_knowing_about_addons(): void
    {
        $plan = $this->sellablePlan();
        $user = $this->user(['plan_id' => $plan->id, 'group_id' => $this->base->id, 'plan_options' => $this->resources() + [
            'addon_groups' => [$this->premium->id], 'granted_groups' => [$this->premium->id, $this->vip->id],
        ]]);
        $quote = (new PlanCustomizationService())->quote($plan, 'reset_traffic', $this->resources(), $user);
        $this->assertSame(300, $quote['amount']);
        $this->assertSame([$this->premium->id], $quote['options']['addon_groups']);
    }

    /**
     * 旧前端（sufe-my-theme #5 之前的 origin/main 第 329/514/531 行）续费时把
     * user.plan_options **原样**回传当 options —— 含服务端派生的 granted_groups。
     * 走真实 HTTP 端点必须 200，且派生键不能混进快照。
     */
    public function test_old_client_echoing_plan_options_with_granted_groups_is_accepted(): void
    {
        $plan = $this->sellablePlan();
        // device_limit / speed_limit 列必须与已购规格一致：hasChangedOptions 拿它们和快照比，
        // 真实用户这两列由 open() 写入；夹具不设会被判成"规格变了"而成为套餐变更。
        $user = $this->user(['plan_id' => $plan->id, 'group_id' => $this->base->id,
            'device_limit' => 2, 'speed_limit' => 100,
            'plan_options' => $this->resources() + [
                'addon_groups' => [$this->premium->id], 'granted_groups' => [$this->premium->id, $this->vip->id],
            ]]);
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $this->postJson('/api/v1/user/order/save', [
            'plan_id' => $plan->id, 'period' => 'monthly', 'options' => $user->plan_options,
        ])->assertOk();

        $order = Order::first();
        $this->assertSame(800, (int) $order->total_amount);
        $this->assertSame(Order::TYPE_RENEWAL, (int) $order->type, '原样回传 = 什么都没改 = 续费，不是套餐变更');
        $this->assertSame([$this->premium->id], $order->plan_snapshot['options']['addon_groups']);
        $this->assertArrayNotHasKey('granted_groups', $order->plan_snapshot['options']);
    }

    /** 与上面对照：显式传 [] 才是"退掉增值组"，且按套餐变更处理。 */
    public function test_explicit_empty_addon_list_drops_addons(): void
    {
        $plan = $this->sellablePlan();
        $user = $this->user(['plan_id' => $plan->id, 'group_id' => $this->base->id,
            'device_limit' => 2, 'speed_limit' => 100,
            'plan_options' => $this->resources() + [
                'addon_groups' => [$this->premium->id], 'granted_groups' => [$this->premium->id, $this->vip->id],
            ]]);
        $quote = (new PlanCustomizationService())->quote($plan, 'monthly', $this->resources() + ['addon_groups' => []], $user);
        $this->assertSame(300, $quote['amount']);
        $this->assertSame([], $quote['options']['addon_groups']);
        $order = OrderService::createFromRequest($user, $plan, 'monthly', null, $this->resources() + ['addon_groups' => []]);
        $this->assertSame(Order::TYPE_UPGRADE, (int) $order->type);
    }

    // ───────────────────────── 性能：查询形状 ─────────────────────────

    /** 套餐列表对增值组的查询数是常数，不随套餐数 × 组数增长（曾是每套餐 1 + 每组 1 条 COUNT）。 */
    public function test_plan_list_query_count_does_not_grow_with_addon_groups(): void
    {
        $this->server('a', [$this->premium->id]);
        $this->server('b', [$this->vip->id]);
        $one = collect([$this->sellablePlan()]);
        $three = collect([$this->sellablePlan(), $this->sellablePlan(), $this->sellablePlan()]);

        Cache::flush();
        DB::enableQueryLog();
        PlanResource::collection($one)->resolve();
        $queriesForOne = count(DB::getQueryLog());
        DB::flushQueryLog();

        Cache::flush();
        $payload = PlanResource::collection($three)->resolve();
        $queriesForThree = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($queriesForOne, $queriesForThree, '三个套餐不能比一个套餐多发查询');
        $this->assertSame(1, $payload[0]['customization']['addon_groups'][(string) $this->premium->id]['server_count'], '批量计数结果要与逐组 COUNT 一致');
    }

    /** 纯基础组的节点拉名单只发 1 条用户查询（走 group_id 索引）；只有增值组节点才多一条 JSON 查询。 */
    public function test_node_user_list_only_queries_json_branch_for_addon_groups(): void
    {
        $baseNode = $this->server('base', [$this->base->id]);
        $premiumNode = $this->server('premium', [$this->premium->id]);
        $plan = $this->sellablePlan();   // premium 因此成为「在售增值组」
        $this->user(['plan_id' => $plan->id, 'group_id' => $this->base->id]);
        Cache::flush();
        PlanCustomizationService::addonGroupIdsInUse(); // 预热，下面只数用户查询

        DB::enableQueryLog();
        ServerService::getAvailableUsers($baseNode);
        $baseQueries = count(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'v2_user')));
        DB::flushQueryLog();
        ServerService::getAvailableUsers($premiumNode);
        $premiumQueries = count(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'v2_user')));
        DB::disableQueryLog();

        $this->assertSame(1, $baseQueries, '基础组节点：与增值组功能上线前完全相同的一条查询');
        $this->assertSame(2, $premiumQueries, '增值组节点：索引分支 + JSON 分支各一条');
    }

    /** 管理员把某组配成增值组后，缓存立即失效，节点下一次拉名单就能看到买家。 */
    public function test_saving_a_plan_invalidates_the_addon_group_cache(): void
    {
        Cache::flush();
        $this->assertSame([], PlanCustomizationService::addonGroupIdsInUse());
        $this->sellablePlan();
        $this->assertEqualsCanonicalizing([$this->premium->id, $this->vip->id], PlanCustomizationService::addonGroupIdsInUse());
    }

    // ───────────────────────── 展示名 ─────────────────────────

    /** 管理员给组设了展示名后，用户端拿到的 name 就是展示名，内部组名不再下发。 */
    public function test_addon_label_replaces_internal_group_name_for_users(): void
    {
        $plan = $this->plan([
            (string) $this->premium->id => ['mode' => 'optional', 'price' => 500, 'label' => ' 高速通道 '],
            (string) $this->vip->id => ['mode' => 'included'],
        ]);
        $addons = PlanResource::make($plan)->resolve()['customization']['addon_groups'];
        $this->assertSame('高速通道', $addons[(string) $this->premium->id]['name'], '展示名要去首尾空白');
        $this->assertSame('VIP 专线', $addons[(string) $this->vip->id]['name'], '未设展示名回落到权限组名');
        $this->assertSame(['mode', 'price', 'name', 'server_count'], array_keys($addons[(string) $this->premium->id]), '不新增输出键，前端契约不变');
        $this->assertStringNotContainsString('10x', json_encode($addons, JSON_UNESCAPED_UNICODE), '设了展示名就不能泄露内部组名');
    }

    public function test_addon_label_must_be_short_text(): void
    {
        $service = new PlanCustomizationService();
        // 空串 / null 都合法（= 未设置）
        $service->validateConfiguration($this->plan([(string) $this->premium->id => ['mode' => 'optional', 'price' => 500, 'label' => '']]));
        $service->validateConfiguration($this->plan([(string) $this->premium->id => ['mode' => 'optional', 'price' => 500, 'label' => null]]));
        foreach ([str_repeat('长', 33), 123, ['x']] as $bad) {
            try {
                $service->validateConfiguration($this->plan([(string) $this->premium->id => ['mode' => 'optional', 'price' => 500, 'label' => $bad]]));
                $this->fail('应拒绝 ' . json_encode($bad, JSON_UNESCAPED_UNICODE));
            } catch (ApiException $e) {
                $this->assertStringContainsString('展示名', $e->getMessage());
            }
        }
    }

    public function test_user_observer_reports_lost_addon_groups_to_the_sync_job(): void
    {
        Bus::fake([NodeUserSyncJob::class]);
        $plan = $this->sellablePlan();
        $user = $this->user(['plan_id' => $plan->id, 'group_id' => $this->base->id, 'plan_options' => $this->resources() + [
            'addon_groups' => [$this->premium->id], 'granted_groups' => [$this->premium->id, $this->vip->id],
        ]]);

        // 退掉 premium：granted 从 [premium, vip] 变成 [vip]
        $user->plan_options = $this->resources() + ['addon_groups' => [], 'granted_groups' => [$this->vip->id]];
        $user->save();

        $premiumId = $this->premium->id;
        Bus::assertDispatched(NodeUserSyncJob::class, function (NodeUserSyncJob $job) use ($premiumId) {
            $lost = new ReflectionProperty($job, 'oldAddonGroups');
            return $lost->getValue($job) === [$premiumId];
        });
    }
}
