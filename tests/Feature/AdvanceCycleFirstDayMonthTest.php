<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\AdvanceCycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 提前重置流量（advance cycle）也支持「每月 1 号重置」的套餐。
 *
 * 语义：流量立即清零、到期日减 30 天，下一次重置仍是原定的下个 1 号（不重排周期）。
 * 整个订阅期内能拿到的整月配额次数不变：提前拿的这一次，换掉的是到期前最后一个 1 号。
 * 「按月重置」（按到期日锚定）的行为保持不变：下一次重置排在 30 天后。
 */
class AdvanceCycleFirstDayMonthTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1073741824;

    private function plan(?int $resetMethod): Plan
    {
        $group = new ServerGroup();
        $group->name = 'base';
        $group->save();

        return Plan::create([
            'name' => 'Plan ' . Str::random(6), 'capacity_limit' => null, 'group_id' => $group->id,
            'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 66, 'device_limit' => 2, 'speed_limit' => 100,
            'reset_traffic_method' => $resetMethod,
            'prices' => ['monthly' => 3, 'half_yearly' => 16.2],
        ]);
    }

    private function user(Plan $plan, array $attributes = []): User
    {
        $user = User::create([
            'email' => Str::random(12) . '@example.test', 'password' => 'test',
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'plan_id' => $plan->id, 'group_id' => $plan->group_id, 'balance' => 0,
            'transfer_enable' => 66 * self::GB, 'u' => 10 * self::GB, 'd' => 57 * self::GB,
            'device_limit' => 2, 'speed_limit' => 100,
            'expired_at' => time() + 360 * 86400,
        ]);
        // 显式写入排期，避开 created 观察者按当前时间重算的结果。
        $user->forceFill($attributes + ['next_reset_at' => $this->nextFirstDay()])->save();

        return $user->fresh()->load('plan');
    }

    private function nextFirstDay(): int
    {
        return now()->startOfMonth()->addMonthNoOverflow()->timestamp;
    }

    public function test_first_day_month_plan_can_advance_and_keeps_the_next_first_day(): void
    {
        $user = $this->user($this->plan(Plan::RESET_TRAFFIC_FIRST_DAY_MONTH));
        $oldExpiredAt = (int) $user->expired_at;
        $scheduled = (int) $user->next_reset_at;

        $preview = app(AdvanceCycleService::class)->preview($user);
        $this->assertTrue($preview['eligible'], json_encode($preview));
        $this->assertSame($scheduled, $preview['new_next_reset_at']);

        $result = app(AdvanceCycleService::class)->advance($user);
        $this->assertTrue($result['advanced'] ?? false, json_encode($result));

        $user->refresh();
        $this->assertSame(0, (int) $user->u + (int) $user->d);
        $this->assertSame($oldExpiredAt - 30 * 86400, (int) $user->expired_at);
        $this->assertSame($scheduled, (int) $user->next_reset_at, '下次重置必须仍是原定的下个 1 号');
    }

    public function test_first_day_month_plan_next_reset_is_capped_by_new_expiry(): void
    {
        $scheduled = $this->nextFirstDay();
        // 新到期日（旧到期日 - 30 天）早于下个 1 号：排期夹到新到期日。
        $expiredAt = time() + 31 * 86400;
        if ($expiredAt - 30 * 86400 >= $scheduled) {
            $this->markTestSkipped('当月剩余天数不足以构造该场景');
        }
        $user = $this->user($this->plan(Plan::RESET_TRAFFIC_FIRST_DAY_MONTH), ['expired_at' => $expiredAt]);

        $result = app(AdvanceCycleService::class)->advance($user);
        $this->assertTrue($result['advanced'] ?? false, json_encode($result));
        $this->assertSame($expiredAt - 30 * 86400, (int) $user->fresh()->next_reset_at);
    }

    public function test_first_day_month_plan_still_needs_more_than_30_days_left(): void
    {
        $user = $this->user($this->plan(Plan::RESET_TRAFFIC_FIRST_DAY_MONTH), ['expired_at' => time() + 20 * 86400]);

        $result = app(AdvanceCycleService::class)->advance($user);
        $this->assertFalse($result['advanced'] ?? false);
        $this->assertSame('insufficient_remaining_days', $result['reason']);
    }

    public function test_first_day_month_plan_still_needs_traffic_used_up(): void
    {
        $user = $this->user($this->plan(Plan::RESET_TRAFFIC_FIRST_DAY_MONTH), ['u' => 0, 'd' => 10 * self::GB]);

        $result = app(AdvanceCycleService::class)->advance($user);
        $this->assertFalse($result['advanced'] ?? false);
        $this->assertSame('traffic_not_exhausted', $result['reason']);
    }

    public function test_monthly_plan_still_schedules_next_reset_30_days_later(): void
    {
        $user = $this->user($this->plan(Plan::RESET_TRAFFIC_MONTHLY), ['next_reset_at' => time() + 10 * 86400]);
        $before = time();

        $result = app(AdvanceCycleService::class)->advance($user);
        $this->assertTrue($result['advanced'] ?? false, json_encode($result));

        $next = (int) $user->fresh()->next_reset_at;
        $this->assertGreaterThanOrEqual($before + 30 * 86400, $next);
        $this->assertLessThanOrEqual(time() + 30 * 86400, $next);
    }

    public function test_never_reset_plan_is_still_unsupported(): void
    {
        $user = $this->user($this->plan(Plan::RESET_TRAFFIC_NEVER), ['next_reset_at' => null]);

        $result = app(AdvanceCycleService::class)->advance($user);
        $this->assertFalse($result['advanced'] ?? false);
        $this->assertSame('unsupported_reset_method', $result['reason']);
    }
}
