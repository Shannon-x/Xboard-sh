<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\StatUser;
use App\Models\User;
use App\Services\Mcp\McpTokenService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AI 助手「帮我选套餐」：按客户自己的真实用量（分线路、已按倍率）把包月各档和按量都算成每月多少钱。
 *
 * 价格照生产的上网目录：四档包月 ¥5 / 50G、¥10 / 150G、¥20 / 400G、¥39 / 1000G，三网是每档的可选加购
 * （¥3 / ¥8 / ¥28 / ¥81，×10 扣量），超出按 ¥0.15/GB 加购（50 / 100 / 200 / 500 / 1000 GB 几档）；
 * 按量 ¥0.15/GB，一次充 100 / 200 / 700 / 2000 GB（¥15 / ¥30 / ¥105 / ¥300），三网随包含，月度保底 ¥5。
 */
class McpPlanAdvisorTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1073741824;

    private ServerGroup $base;
    private ServerGroup $sanwang;
    /** @var array<string, Plan> */
    private array $tiers = [];
    private Plan $payg;
    private Plan $static;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        app()->forgetScopedInstances();
        config(['v2board.app_url' => 'https://panel.example.test/', 'v2board.email_verify_nudge_enable' => 0]);

        $this->base = $this->group('上网·基础');
        $this->sanwang = $this->group('上网·三网优化');
        $this->server('香港直连', $this->base, 1);
        $this->server('香港中转', $this->base, 3);
        $this->server('香港三网', $this->sanwang, 10);

        foreach ([['体验', 50, 5, 300], ['轻享', 150, 10, 800], ['悦享', 400, 20, 2800], ['畅享', 1000, 39, 8100]] as [$name, $gb, $price, $sanwang]) {
            $this->tiers[$name] = Plan::create([
                'name' => "{$name} {$gb}G", 'capacity_limit' => null, 'group_id' => $this->base->id,
                'show' => true, 'sell' => true, 'renew' => true,
                'transfer_enable' => $gb, 'device_limit' => 3, 'speed_limit' => null,
                'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
                'tags' => ['segment:basic'],
                'prices' => ['monthly' => $price, 'quarterly' => round($price * 3 * 0.95, 2), 'yearly' => round($price * 12 * 0.85, 2)],
                'customization' => [
                    'transfer_enable' => ['mode' => 'fixed'], 'device_limit' => ['mode' => 'fixed'], 'speed_limit' => ['mode' => 'fixed'],
                    'addon_groups' => [(string) $this->sanwang->id => ['mode' => 'optional', 'price' => $sanwang, 'label' => '三网优化']],
                    'traffic_topup' => ['mode' => 'on', 'price_per_gb' => 15, 'selection' => 'choices',
                        'choices' => [['gb' => 50], ['gb' => 100], ['gb' => 200], ['gb' => 500], ['gb' => 1000]]],
                ],
            ]);
        }
        $this->payg = Plan::create([
            'name' => '上网按量', 'capacity_limit' => null, 'group_id' => $this->base->id,
            'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 100, 'device_limit' => 3, 'speed_limit' => null,
            'reset_traffic_method' => Plan::RESET_TRAFFIC_NEVER,
            'tags' => ['segment:basic'],
            'prices' => ['onetime' => 15],
            'customization' => [
                'transfer_enable' => ['mode' => 'choices', 'max' => 2000, 'step' => 100, 'price_per_step' => 1500, 'choices' => [100, 200, 700, 2000]],
                'device_limit' => ['mode' => 'fixed'], 'speed_limit' => ['mode' => 'fixed'],
                'addon_groups' => [(string) $this->sanwang->id => ['mode' => 'included']],
            ],
        ]);
        $this->static = Plan::create([
            'name' => '美国 AT&T 静态家宽', 'capacity_limit' => null, 'group_id' => $this->base->id,
            'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => null,
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'tags' => ['segment:static', 'carrier:美国 AT&T'],
            'prices' => ['monthly' => 45],
        ]);
        config(['v2board.payg_plan_ids' => [$this->payg->id]]);
        admin_setting(['mcp_enable' => 1]);
    }

    private function group(string $name): ServerGroup
    {
        $group = new ServerGroup();
        $group->name = $name;
        $group->save();
        return $group;
    }

    private function server(string $name, ServerGroup $group, float $rate): void
    {
        $server = new Server();
        $server->type = 'trojan';
        $server->name = $name;
        $server->rate = $rate;
        $server->host = 'example.test';
        $server->port = '443';
        $server->server_port = 443;
        $server->group_ids = [(string) $group->id];
        $server->show = true;
        $server->save();
    }

    private function user(Plan $plan, array $attributes = []): User
    {
        $user = User::create($attributes + [
            'email' => Str::random(10) . '@example.test', 'password' => 'x',
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'plan_id' => $plan->id, 'group_id' => $plan->group_id, 'balance' => 0,
            'transfer_enable' => $plan->transfer_enable * self::GB, 'u' => 0, 'd' => 0, 'device_limit' => 3,
            'expired_at' => time() + 20 * 86400,
        ]);
        Order::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'period' => 'monthly', 'type' => Order::TYPE_NEW_PURCHASE,
            'trade_no' => Helper::generateOrderNo(), 'total_amount' => 1000, 'status' => Order::STATUS_COMPLETED,
            'created_at' => time() - 40 * 86400, 'updated_at' => time() - 40 * 86400,
        ]);
        return $user;
    }

    /** 某天在某倍率的线路上用了多少实际流量（落库时乘过倍率，与 StatUserJob 同口径） */
    private function used(User $user, int $daysAgo, float $realGb, float $rate): void
    {
        StatUser::create([
            'user_id' => $user->id, 'server_rate' => $rate, 'u' => 0, 'd' => (int) round($realGb * $rate * self::GB),
            'record_type' => 'd', 'record_at' => now()->startOfDay()->subDays($daysAgo)->getTimestamp(),
        ]);
    }

    /** @return array{error: bool, text: string, data: array|null} */
    private function tool(string $token, string $tool, array $arguments = []): array
    {
        $this->app['auth']->forgetGuards();
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-06-18',
        ])->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]]);
        $result = $response->assertOk()->json('result');
        return [
            'error' => (bool) ($result['isError'] ?? false),
            'text' => (string) ($result['content'][0]['text'] ?? ''),
            'data' => $result['structuredContent'] ?? null,
        ];
    }

    private function token(User $user, bool $canOrder = false): string
    {
        return McpTokenService::make()->create($user, 'Claude', $canOrder)['token'];
    }

    public function test_recommends_from_real_usage_counting_the_line_multiplier(): void
    {
        // 轻享 150G 用户：近 30 天直连 100 GB + 三网 10 GB（×10 = 扣 100 GB），每月要扣 200 GB，150G 不够
        $user = $this->user($this->tiers['轻享']);
        for ($i = 0; $i < 10; $i++) {
            $this->used($user, $i * 3, 10, 1);
            $this->used($user, $i * 3 + 1, 1, 10);
        }
        $this->used($user, 40, 5, 1);   // 更早的用量：只用来判断「数据够不够一个月」

        $r = $this->tool($this->token($user), 'recommend_plan');
        $this->assertFalse($r['error'], $r['text']);
        $d = $r['data'];

        $this->assertSame('客户最近的真实用量', $d['basis']['source']);
        $this->assertEquals(110, $d['basis']['monthly_gb']);
        $this->assertEquals(200, $d['basis']['monthly_quota_needed_gb']);
        $this->assertSame(['直连线路（×1）', '三网优化线路（×10）'], array_column($d['basis']['line_mix'], 'line'));
        $this->assertSame('basic', $d['segment']);

        // 省心的选项里按量最便宜：200 GB × ¥0.15 = ¥30；悦享 + 三网 ¥48
        $this->assertSame('按量', $d['recommended']['kind']);
        $this->assertSame('30.00', $d['recommended']['monthly_cost_yuan']);
        $this->assertSame(['mode' => 'purchase', 'plan_id' => $this->payg->id, 'period' => 'onetime', 'traffic_gb' => 200], $d['recommended']['quote_args']);
        // 充多少：够一个月的 200 GB ¥30；一次 700 GB ¥105 满 ¥100 多送；2000 GB ¥300 一年内用得完也列出
        $this->assertSame([200, 700, 2000], array_column($d['recommended']['recharge_suggestions'], 'traffic_gb'));
        $first = $d['recommended']['recharge_suggestions'][0];
        $this->assertSame('30.00', $first['price_yuan']);
        $this->assertEquals(10, $first['bonus_gb'], '首充送 10 GB');
        $this->assertEquals(1.1, $first['lasts_months']);
        $this->assertSame('105.00', $d['recommended']['recharge_suggestions'][1]['price_yuan']);

        // 备选按每月花费排：轻享 + 三网 + 每月加购 50 GB = ¥10 + ¥8 + ¥7.5
        $alt = $d['alternatives'][0];
        $this->assertSame('轻享 150G', $alt['plan_name']);
        $this->assertTrue($alt['is_current_plan']);
        $this->assertSame('25.50', $alt['monthly_cost_yuan']);
        $this->assertSame(['gb' => 50, 'cost_yuan' => '7.50'], $alt['topup_needed']);
        $this->assertSame([['group_id' => $this->sanwang->id, 'name' => '三网优化', 'monthly_fee_yuan' => '8.00']], $alt['addon_lines']);
        $this->assertSame([$this->sanwang->id], $alt['quote_args']['addon_group_ids']);
        $this->assertStringContainsString('每个周期都要记得加购', implode("\n", $d['notes']));

        $names = array_column($d['alternatives'], 'plan_name');
        $this->assertNotContains('美国 AT&T 静态家宽', $names, '家宽不和普通上网放在一起比');
        $this->assertSame('轻享 150G', $d['current_plan']['plan_name']);
        $this->assertSame([], $d['not_suitable']);
    }

    public function test_customer_estimate_and_line_choice_change_the_tier(): void
    {
        $user = $this->user($this->payg, ['expired_at' => null]);
        $token = $this->token($user);

        // 电信直连 120 GB：轻享 150G 够用，¥10 比按量 ¥18 省；年付再省 15%
        $d = $this->tool($token, 'recommend_plan', ['monthly_gb' => 120, 'line' => 'direct'])['data'];
        $this->assertSame('客户自己的估计', $d['basis']['source']);
        $this->assertSame('轻享 150G', $d['recommended']['plan_name']);
        $this->assertSame('10.00', $d['recommended']['monthly_cost_yuan']);
        $this->assertSame(25, $d['recommended']['headroom_percent']);
        $this->assertSame([], $d['recommended']['addon_lines'], '不走三网就不加三网');
        $this->assertSame(['mode' => 'purchase', 'plan_id' => $this->tiers['轻享']->id, 'period' => 'monthly'], $d['recommended']['quote_args']);
        $this->assertSame('yearly', $d['recommended']['longer_period']['period']);
        $this->assertSame(15, $d['recommended']['longer_period']['saving_percent']);
        $this->assertNotEmpty($d['usage_guide']);

        // 同样 120 GB 走联通 / 移动的中转（×3）：每月扣 360 GB，要悦享 400G
        $d = $this->tool($token, 'recommend_plan', ['monthly_gb' => 120, 'line' => 'relay'])['data'];
        $this->assertEquals(360, $d['basis']['monthly_quota_needed_gb']);
        $this->assertSame('悦享 400G', $d['recommended']['plan_name']);
        $this->assertSame('20.00', $d['recommended']['monthly_cost_yuan']);

        // 用得很少：按量按保底 ¥5 收，和体验版同价；同价时先推客户现在用的，不让客户白折腾
        $d = $this->tool($token, 'recommend_plan', ['monthly_gb' => 10])['data'];
        $all = array_merge([$d['recommended']], $d['alternatives']);
        $payg = collect($all)->firstWhere('kind', '按量');
        $this->assertSame('5.00', $payg['monthly_cost_yuan']);
        $this->assertStringContainsString('保底', $payg['why']);
        $this->assertSame('5.00', $d['recommended']['monthly_cost_yuan']);
        $this->assertTrue($d['current_plan']['is_current_plan']);
    }

    public function test_new_customer_without_usage_is_asked_for_an_estimate(): void
    {
        $user = $this->user($this->tiers['体验']);
        $this->used($user, 1, 2, 1);   // 只有两天数据

        $d = $this->tool($this->token($user), 'recommend_plan')['data'];
        $this->assertTrue($d['need_customer_estimate']);
        $this->assertNotEmpty($d['usage_guide']);
        $this->assertSame(['direct', 'relay', 'dedicated', 'triple'], array_column($d['line_guide'], 'line'));
        $this->assertArrayNotHasKey('recommended', $d);
    }

    public function test_residential_customers_get_their_own_segment(): void
    {
        $user = $this->user($this->static);
        $d = $this->tool($this->token($user), 'recommend_plan', ['monthly_gb' => 60])['data'];
        $this->assertSame('static', $d['segment']);
        $this->assertSame('美国 AT&T 静态家宽', $d['recommended']['plan_name']);
        $this->assertSame([], $d['alternatives'], '按量和普通上网不在家宽里比');
    }

    public function test_quote_and_order_take_the_recommended_traffic_and_lines(): void
    {
        $user = $this->user($this->tiers['体验']);
        $reader = $this->token($user);

        $q = $this->tool($reader, 'quote_plan', ['mode' => 'purchase', 'plan_id' => $this->tiers['轻享']->id, 'period' => 'monthly', 'addon_group_ids' => [$this->sanwang->id]]);
        $this->assertFalse($q['error'], $q['text']);
        $this->assertSame('18.00', $q['data']['price_yuan']);
        $this->assertSame('8.00', $q['data']['addon_fee_yuan']);
        $this->assertSame([['group_id' => $this->sanwang->id, 'name' => '三网优化']], $q['data']['addon_lines']);

        // 按量：traffic_gb 就是这次充多少；首充送 10 GB
        $q = $this->tool($reader, 'quote_plan', ['mode' => 'purchase', 'plan_id' => $this->payg->id, 'period' => 'onetime', 'traffic_gb' => 700]);
        $this->assertSame('105.00', $q['data']['price_yuan']);
        $this->assertSame(700, $q['data']['traffic_gb']);
        $this->assertEquals(76.67, $q['data']['payg_bonus_gb'], '满 ¥100 送 ¥10（66.67 GB）+ 首充 10 GB');
        $this->assertTrue($this->tool($reader, 'quote_plan', ['mode' => 'purchase', 'plan_id' => $this->payg->id, 'period' => 'onetime', 'traffic_gb' => 300])['error'], '只卖几档的不能随便填');

        // 说得让 AI 能改对
        $q = $this->tool($reader, 'quote_plan', ['mode' => 'purchase', 'plan_id' => $this->tiers['悦享']->id, 'period' => 'monthly', 'traffic_gb' => 500]);
        $this->assertTrue($q['error']);
        $this->assertStringContainsString('流量是固定的 400 GB', $q['text']);
        $q = $this->tool($reader, 'quote_plan', ['mode' => 'purchase', 'plan_id' => $this->static->id, 'period' => 'monthly', 'addon_group_ids' => [$this->sanwang->id]]);
        $this->assertTrue($q['error']);
        $this->assertStringContainsString('没有可以加选的增值线路', $q['text']);

        // list_plans 里看得到三网能加选、按量能选多少
        $plans = collect($this->tool($reader, 'list_plans')['data']['plans'])->keyBy('plan_id');
        $this->assertSame([['group_id' => $this->sanwang->id, 'name' => '三网优化', 'mode' => 'optional', 'monthly_fee_yuan' => '8.00', 'rate' => 10]], $plans[$this->tiers['轻享']->id]['lines']);
        $this->assertSame(['choices_gb' => [100, 200, 700, 2000]], $plans[$this->payg->id]['traffic_choices']);

        // 下单带同样的参数：订单金额、快照里的增值组与报价一致
        $r = $this->tool($this->token($user, true), 'create_order', ['mode' => 'purchase', 'plan_id' => $this->tiers['轻享']->id, 'period' => 'monthly', 'addon_group_ids' => [$this->sanwang->id], 'expected_price_yuan' => '18.00']);
        $this->assertFalse($r['error'], $r['text']);
        $order = Order::where('trade_no', $r['data']['trade_no'])->firstOrFail();
        $this->assertSame(1800, (int) $order->plan_snapshot['amount'], '应付另算：体验版剩余时间会折抵');
        $this->assertSame([$this->sanwang->id], $order->plan_snapshot['options']['addon_groups']);
    }

    public function test_traffic_options_project_the_shortfall_until_reset(): void
    {
        // 150 GB 用了 120 GB，10 天后重置；最近 7 天每天扣 7 GB → 还要 70 GB，差 40 GB，多留 10% 是 44 GB → 够用的最小一档 50 GB
        $user = $this->user($this->tiers['轻享'], ['d' => 120 * self::GB]);
        $user->update(['next_reset_at' => time() + 10 * 86400]);   // 新建用户时观察者会按到期日重算，建好再改
        for ($i = 1; $i <= 7; $i++) {
            $this->used($user, $i, 7, 1);
        }

        $d = $this->tool($this->token($user), 'get_traffic_options')['data'];
        $p = $d['projection'];
        $this->assertEquals(7, $p['avg_daily_gb_last_7_days']);
        $this->assertEqualsWithDelta(70, $p['projected_need_gb'], 0.1);
        $this->assertEqualsWithDelta(40, $p['projected_shortfall_gb'], 0.1);
        $this->assertSame(['gb' => 50, 'cost_yuan' => '7.50'], $p['suggested_topup']);
        $this->assertStringContainsString('建议加购 50 GB', $p['advice']);
    }
}
