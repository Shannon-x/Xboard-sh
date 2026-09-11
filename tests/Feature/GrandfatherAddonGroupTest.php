<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PlanCustomizationService;
use App\Services\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 迁移：把原本人人可用的一类节点（10x 专线）改成按增值组加价出售。
 *
 * 命令 xboard:grandfather-addon-group 把该组补进在订用户的 plan_options.addon_groups，
 * 等同于「他们上一周期已经买过」：本周期照常能用，下次续费报价自动含这项加价。
 */
class GrandfatherAddonGroupTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1073741824;

    private ServerGroup $base;
    private ServerGroup $premium;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = $this->group('基础');
        $this->premium = $this->group('10x 专线');
    }

    private function group(string $name): ServerGroup
    {
        $g = new ServerGroup();
        $g->name = $name;
        $g->save();
        return $g;
    }

    private function server(string $name, array $groupIds): Server
    {
        $s = new Server();
        $s->type = 'trojan';
        $s->name = $name;
        $s->rate = 1;
        $s->host = 'example.test';
        $s->port = '443';
        $s->server_port = 443;
        $s->group_ids = array_map('strval', $groupIds);
        $s->show = true;
        $s->save();
        return $s;
    }

    /** 迁移前的套餐：没有 customization，三项资源就是套餐字段。 */
    private function plan(array $overrides = []): Plan
    {
        return Plan::create($overrides + [
            'name' => '标准版', 'capacity_limit' => null, 'group_id' => $this->base->id,
            'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => 100,
            'reset_traffic_method' => 1,
            'prices' => ['monthly' => 10, 'yearly' => 100],
        ]);
    }

    /** 迁移第 2 步：把 10x 组配成「可选购 ¥5/月」。 */
    private function sellAddon(Plan $plan): Plan
    {
        $plan->update(['customization' => [
            'transfer_enable' => ['mode' => 'fixed'],
            'device_limit' => ['mode' => 'fixed'],
            'speed_limit' => ['mode' => 'fixed'],
            'addon_groups' => [(string) $this->premium->id => ['mode' => 'optional', 'price' => 500]],
        ]]);
        return $plan->fresh();
    }

    private function user(Plan $plan, array $attributes = []): User
    {
        return User::create($attributes + [
            'email' => Str::random(12) . '@example.test', 'password' => 'test',
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'plan_id' => $plan->id, 'group_id' => $plan->group_id, 'balance' => 0,
            'transfer_enable' => 100 * self::GB, 'u' => 0, 'd' => 0,
            'device_limit' => 2, 'speed_limit' => 100,
            'expired_at' => time() + 20 * 86400,
        ]);
    }

    public function test_full_migration_keeps_current_users_connected_and_charges_them_on_renewal(): void
    {
        $plan = $this->plan();
        // 迁移前：10x 节点和普通节点都挂在基础组，人人可见
        $normalNode = $this->server('香港 01', [$this->base->id]);
        $premiumNode = $this->server('10x 专线 01', [$this->base->id, $this->premium->id]);
        $oldUser = $this->user($plan);
        $this->assertSame(['10x 专线 01', '香港 01'], $this->visibleNames($oldUser));

        // 第 2 步：配成可选购
        $plan = $this->sellAddon($plan);
        // 第 3 步：补授权
        $this->artisan('xboard:grandfather-addon-group', ['group' => $this->premium->id])
            ->expectsOutputToContain('授予 1')
            ->assertSuccessful();
        // 第 4 步：把 10x 节点的基础组摘掉
        $premiumNode->update(['group_ids' => [(string) $this->premium->id]]);
        PlanCustomizationService::forgetAddonCaches();

        // 老用户照常看得到、节点端名单里也有他
        $this->assertSame(['10x 专线 01', '香港 01'], $this->visibleNames($oldUser->fresh()));
        $this->assertContains($oldUser->id, ServerService::getAvailableUsers($premiumNode->fresh())->pluck('id')->all());

        // 迁移后新注册、没买增值组的人：看不到 10x，也不在名单里
        $newUser = $this->user($plan);
        $this->assertSame(['香港 01'], $this->visibleNames($newUser));
        $this->assertNotContains($newUser->id, ServerService::getAvailableUsers($premiumNode->fresh())->pluck('id')->all());

        // 老用户续费：报价自动含 ¥5 加价，且仍判定为「续费」（叠时长，不是套餐变更）
        $quote = app(PlanCustomizationService::class)->quote($plan, 'monthly', null, $oldUser->fresh());
        $this->assertSame(1500, $quote['amount'], '¥10 基础 + ¥5 增值组');
        $order = OrderService::createFromRequest($oldUser->fresh(), $plan, 'monthly');
        $this->assertSame(Order::TYPE_RENEWAL, (int) $order->type);
        $this->assertSame(1500, (int) $order->total_amount);

        // 新用户续费：没勾选就没这项加价，也拿不到节点
        $this->assertSame(1000, app(PlanCustomizationService::class)->quote($plan, 'monthly', null, $newUser)['amount']);
    }

    public function test_dry_run_reports_without_writing_and_rerun_is_idempotent(): void
    {
        $plan = $this->sellAddon($this->plan());
        $user = $this->user($plan);

        $this->artisan('xboard:grandfather-addon-group', ['group' => $this->premium->id, '--dry-run' => true])
            ->expectsOutputToContain('演练')->assertSuccessful();
        $this->assertNull($user->fresh()->plan_options, '演练不写库');

        $this->artisan('xboard:grandfather-addon-group', ['group' => $this->premium->id])->assertSuccessful();
        $this->assertSame([$this->premium->id], $user->fresh()->plan_options['addon_groups']);
        $this->assertSame([$this->premium->id], $user->fresh()->plan_options['granted_groups']);

        // 再跑一次：已持有 → 不重复写
        $this->artisan('xboard:grandfather-addon-group', ['group' => $this->premium->id])
            ->expectsOutputToContain('授予 0')->assertSuccessful();

        // 撤销
        $this->artisan('xboard:grandfather-addon-group', ['group' => $this->premium->id, '--revert' => true])
            ->assertSuccessful();
        $this->assertSame([], $user->fresh()->plan_options['addon_groups']);
    }

    public function test_users_whose_resources_drifted_from_the_plan_are_skipped_and_reported(): void
    {
        $plan = $this->sellAddon($this->plan());
        $normal = $this->user($plan);
        $compensated = $this->user($plan, ['transfer_enable' => 150 * self::GB]);   // 客服补过流量

        $this->artisan('xboard:grandfather-addon-group', ['group' => $this->premium->id])
            ->expectsOutputToContain('规格不符跳过 1')->assertSuccessful();
        $this->assertSame([$this->premium->id], $normal->fresh()->plan_options['addon_groups']);
        $this->assertNull($compensated->fresh()->plan_options, '规格不符的用户默认跳过');

        $this->artisan('xboard:grandfather-addon-group', ['group' => $this->premium->id, '--allow-mismatched' => true])
            ->assertSuccessful();
        $this->assertSame([$this->premium->id], $compensated->fresh()->plan_options['addon_groups']);
    }

    public function test_refuses_to_run_before_the_group_is_configured_as_sellable(): void
    {
        $plan = $this->plan();   // 没配增值组
        $this->user($plan);
        $this->artisan('xboard:grandfather-addon-group', ['group' => $this->premium->id])
            ->expectsOutputToContain('没有任何套餐')->assertFailed();

        // 显式指定套餐也要拦：否则写进去的选择会让用户续不了费
        $this->artisan('xboard:grandfather-addon-group', ['group' => $this->premium->id, '--plan' => [$plan->id]])
            ->expectsOutputToContain('无法续费')->assertFailed();
    }

    public function test_only_targets_active_users_of_the_selling_plans(): void
    {
        $sellingPlan = $this->sellAddon($this->plan());
        $otherPlan = $this->plan(['name' => '别的套餐']);
        $active = $this->user($sellingPlan);
        $expired = $this->user($sellingPlan, ['expired_at' => time() - 86400]);
        $elsewhere = $this->user($otherPlan);

        $this->artisan('xboard:grandfather-addon-group', ['group' => $this->premium->id])->assertSuccessful();
        $this->assertNotNull($active->fresh()->plan_options);
        $this->assertNull($expired->fresh()->plan_options, '默认不处理已过期订阅');
        $this->assertNull($elsewhere->fresh()->plan_options, '不碰其他套餐的用户');

        $this->artisan('xboard:grandfather-addon-group', ['group' => $this->premium->id, '--include-expired' => true])
            ->assertSuccessful();
        $this->assertNotNull($expired->fresh()->plan_options);
    }

    private function visibleNames(User $user): array
    {
        $names = collect(ServerService::getAvailableServers($user))->pluck('name')->sort()->values()->all();
        return $names;
    }
}
