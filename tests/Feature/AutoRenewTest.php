<?php

namespace Tests\Feature;

use App\Jobs\SendEmailJob;
use App\Models\Order;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\RenewService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 续费助手：快捷续费规格（上次配置 + 现价）与自动续费（余额足够时后台代下单）。
 */
class AutoRenewTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1073741824;

    private ServerGroup $base;
    private ServerGroup $premium;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([SendEmailJob::class]);   // 只拦邮件；OrderHandleJob::dispatchSync 照常执行
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

    private function addonPlan(): Plan
    {
        return $this->plan(['customization' => [
            'transfer_enable' => ['mode' => 'fixed'], 'device_limit' => ['mode' => 'fixed'], 'speed_limit' => ['mode' => 'fixed'],
            'addon_groups' => [(string) $this->premium->id => ['mode' => 'optional', 'price' => 500, 'label' => '高速通道']],
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
            'expired_at' => time() + 20 * 3600,   // 20 小时后到期：落在 24h 提前窗口内
        ]);
    }

    private function completedOrder(User $user, Plan $plan, string $period, int $type = Order::TYPE_NEW_PURCHASE): Order
    {
        return Order::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'period' => $period, 'type' => $type,
            'trade_no' => Helper::generateOrderNo(), 'total_amount' => 1000, 'status' => Order::STATUS_COMPLETED,
        ]);
    }

    // ───────────────────────── 续费规格 ─────────────────────────

    public function test_spec_follows_the_last_order_period_and_includes_addons_and_vip_discount(): void
    {
        $plan = $this->addonPlan();
        $user = $this->user($plan, ['discount' => 10, 'plan_options' => [
            'transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => 100,
            'addon_groups' => [$this->premium->id], 'granted_groups' => [$this->premium->id],
        ]]);
        $this->completedOrder($user, $plan, 'monthly');
        $this->completedOrder($user, $plan, 'yearly', Order::TYPE_RENEWAL);   // 最近一单是年付

        $spec = (new RenewService())->resolveSpec($user);
        $this->assertTrue($spec['available']);
        $this->assertSame('yearly', $spec['period']);
        $this->assertSame(10000 + 5000, $spec['list_amount'], '年付 ¥100 + 增值组 ¥5/月 × 10（年付折扣比例）');
        $this->assertSame(13500, $spec['amount'], '扣 10% 专属折扣');
        $this->assertSame([$this->premium->id], $spec['options']['addon_groups']);
        $this->assertSame(['高速通道'], $spec['summary']['addon_names']);
        $this->assertSame(100, $spec['summary']['transfer_enable']);
    }

    public function test_spec_falls_back_to_monthly_and_reports_unrenewable_plans(): void
    {
        $plan = $this->plan();
        $user = $this->user($plan);   // 没有任何订单
        $spec = (new RenewService())->resolveSpec($user);
        $this->assertTrue($spec['available']);
        $this->assertSame('monthly', $spec['period']);
        $this->assertSame(1000, $spec['amount']);

        $plan->update(['renew' => false]);
        $spec = (new RenewService())->resolveSpec($user->fresh());
        $this->assertFalse($spec['available']);
        $this->assertSame('not_purchasable', $spec['reason']);

        $lifetime = $this->user($this->plan(['name' => '永久']), ['expired_at' => null]);
        $this->assertSame('lifetime', (new RenewService())->resolveSpec($lifetime)['reason']);
    }

    public function test_subscribe_payload_and_toggle_via_existing_user_update_endpoint(): void
    {
        $plan = $this->plan();
        $user = $this->user($plan, ['balance' => 600]);
        Sanctum::actingAs($user);

        $renew = $this->getJson('/api/v1/user/getSubscribe')->assertOk()->json('data.renew');
        $this->assertSame(7, $renew['prompt_days']);
        $this->assertTrue($renew['spec']['available']);
        $this->assertSame(1000, $renew['spec']['amount']);
        $this->assertFalse($renew['auto']['enabled']);
        $this->assertFalse($renew['auto']['balance_enough']);
        $this->assertSame(400, $renew['auto']['shortfall']);

        $this->postJson('/api/v1/user/update', ['auto_renew' => 1])->assertOk();
        $this->assertSame(1, (int) $user->fresh()->auto_renew);
        $this->assertSame(1, (int) $this->getJson('/api/v1/user/info')->assertOk()->json('data.auto_renew'));
        // 旧前端只传两个提醒开关：照常工作，不碰 auto_renew
        $this->postJson('/api/v1/user/update', ['remind_expire' => 0])->assertOk();
        $this->assertSame(1, (int) $user->fresh()->auto_renew);
    }

    // ───────────────────────── 自动续费 ─────────────────────────

    public function test_renews_from_balance_and_marks_the_order(): void
    {
        $plan = $this->addonPlan();
        $user = $this->user($plan, ['auto_renew' => 1, 'balance' => 2000, 'plan_options' => [
            'transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => 100,
            'addon_groups' => [$this->premium->id], 'granted_groups' => [$this->premium->id],
        ]]);
        $this->completedOrder($user, $plan, 'monthly');
        $oldExpiredAt = (int) $user->expired_at;

        $result = (new RenewService())->attemptAutoRenew($user);
        $this->assertSame('renewed', $result['status'], json_encode($result));
        $this->assertSame(1500, $result['amount'], '¥10 + 增值组 ¥5');

        $order = Order::where('trade_no', $result['trade_no'])->firstOrFail();
        $this->assertSame(1, (int) $order->auto_renew);
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->status);
        $this->assertSame(Order::TYPE_RENEWAL, (int) $order->type);
        $this->assertSame(0, (int) $order->total_amount);
        $this->assertSame(1500, (int) $order->balance_amount);

        $user->refresh();
        $this->assertSame(500, (int) $user->balance);
        $this->assertGreaterThan($oldExpiredAt + 29 * 86400, (int) $user->expired_at, '叠加了一个月');
        $this->assertSame([$this->premium->id], $user->plan_options['addon_groups'], '增值线路跟着续上');
        Bus::assertDispatchedTimes(SendEmailJob::class, 1);
    }

    public function test_insufficient_balance_never_deducts_and_notifies_once_per_expiry(): void
    {
        $plan = $this->plan();
        $user = $this->user($plan, ['auto_renew' => 1, 'balance' => 300]);
        $service = new RenewService();

        $this->assertSame('insufficient', $service->attemptAutoRenew($user)['status']);
        $this->assertSame('insufficient', $service->attemptAutoRenew($user)['status']);
        $this->assertSame(300, (int) $user->fresh()->balance, '一分不扣');
        $this->assertSame(0, Order::count(), '不留待付单');
        Bus::assertDispatchedTimes(SendEmailJob::class, 1);

        // 续上之后到期日变了：下一个周期余额又不够，会再提醒一次
        $user->update(['expired_at' => time() + 40 * 86400]);
        $this->assertSame('insufficient', $service->attemptAutoRenew($user->fresh())['status']);
        Bus::assertDispatchedTimes(SendEmailJob::class, 2);
    }

    public function test_skips_when_disabled_pending_order_or_site_switch_off(): void
    {
        $plan = $this->plan();
        $service = new RenewService();

        $off = $this->user($plan, ['auto_renew' => 0, 'balance' => 5000]);
        $this->assertSame('skipped', $service->attemptAutoRenew($off)['status']);

        $pending = $this->user($plan, ['auto_renew' => 1, 'balance' => 5000]);
        Order::create(['user_id' => $pending->id, 'plan_id' => $plan->id, 'period' => 'monthly', 'type' => 2,
            'trade_no' => Helper::generateOrderNo(), 'total_amount' => 1000, 'status' => Order::STATUS_PENDING]);
        $this->assertSame('pending_order', $service->attemptAutoRenew($pending)['reason']);

        $unrenewable = $this->user($this->plan(['name' => '停售', 'renew' => false]), ['auto_renew' => 1, 'balance' => 5000]);
        $this->assertSame('unavailable', $service->attemptAutoRenew($unrenewable)['status']);
        Bus::assertDispatchedTimes(SendEmailJob::class, 1);
        $this->assertSame(1, Order::count(), '只有那笔手工造的待付单');
    }

    public function test_command_only_touches_the_expiry_window_and_dry_run_creates_nothing(): void
    {
        $plan = $this->plan();
        $soon = $this->user($plan, ['auto_renew' => 1, 'balance' => 5000]);                                  // 20h 后到期
        $justExpired = $this->user($plan, ['auto_renew' => 1, 'balance' => 5000, 'expired_at' => time() - 3600]);
        $far = $this->user($plan, ['auto_renew' => 1, 'balance' => 5000, 'expired_at' => time() + 10 * 86400]);
        $long = $this->user($plan, ['auto_renew' => 1, 'balance' => 5000, 'expired_at' => time() - 10 * 86400]);

        $this->artisan('renew:auto', ['--dry-run' => true])->expectsOutputToContain('would_renew 2')->assertSuccessful();
        $this->assertSame(0, Order::count());

        $this->artisan('renew:auto')->expectsOutputToContain('renewed 2')->assertSuccessful();
        $this->assertSame(2, Order::where('auto_renew', 1)->count());
        $this->assertSame(4000, (int) $soon->fresh()->balance);
        $this->assertSame(4000, (int) $justExpired->fresh()->balance);
        $this->assertSame(5000, (int) $far->fresh()->balance, '10 天后到期：不在窗口内');
        $this->assertSame(5000, (int) $long->fresh()->balance, '过期 10 天：超出宽限期');
        $this->assertGreaterThan(time() + 29 * 86400, (int) $justExpired->fresh()->expired_at, '过期后续费从现在起算');
    }
}
