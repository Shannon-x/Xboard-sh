<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Services\AdvanceCycleService;
use App\Services\TrafficResetService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 「按月重置」（按到期日锚定）套餐的提前重置流量，与 wyx2685/v2board 的 newPeriod 语义一致：
 *
 *   结束当前周期、从点击时刻起开一个完整的新周期；
 *   到期日只扣「本周期还没用完的时间」，不是固定 30 天；
 *   扣完后至少还要剩一个完整周期，否则不给（新周期会被到期日截短）。
 *
 * 用例日期都放在将来：preview/advance 会先跑一次自然重置检查，那里用的是真实时钟。
 *
 * 回归：#5431（11-19 点击，到期 12-26、下次重置 11-26，旧逻辑扣 30 天把到期扣成 11-26，
 * 新周期只有 7 天），#5218（本周期只剩 3 天被扣了 30 天）。
 */
class AdvanceCycleMonthlyTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1073741824;
    private const TZ = 'Asia/Shanghai';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $time): int
    {
        return Carbon::parse($time, self::TZ)->timestamp;
    }

    private function freezeAt(string $time): void
    {
        Carbon::setTestNow(Carbon::parse($time, self::TZ));
    }

    private function user(string $expiredAt, string $nextResetAt, ?int $resetMethod = Plan::RESET_TRAFFIC_MONTHLY): User
    {
        $group = new ServerGroup();
        $group->name = 'base';
        $group->save();
        $plan = Plan::create([
            'name' => 'Plan ' . Str::random(6), 'capacity_limit' => null, 'group_id' => $group->id,
            'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 420, 'device_limit' => 2, 'speed_limit' => 100,
            'reset_traffic_method' => $resetMethod,
            'prices' => ['monthly' => 24],
        ]);
        $user = User::create([
            'email' => Str::random(12) . '@example.test', 'password' => 'test',
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'plan_id' => $plan->id, 'group_id' => $plan->group_id, 'balance' => 0,
            'transfer_enable' => 420 * self::GB, 'u' => 20 * self::GB, 'd' => 400 * self::GB,
            'device_limit' => 2, 'speed_limit' => 100,
            'expired_at' => $this->at($expiredAt),
        ]);
        // 显式写入排期，避开观察者按当前时间重算的结果。
        $user->forceFill(['next_reset_at' => $this->at($nextResetAt)])->save();

        return $user->fresh()->load('plan');
    }

    public function test_case_5431_only_consumes_the_rest_of_the_current_cycle(): void
    {
        $this->freezeAt('2036-11-19 14:09:35');
        $user = $this->user('2036-12-26 22:48:27', '2036-11-26 22:48:27');

        $preview = app(AdvanceCycleService::class)->preview($user);
        $this->assertTrue($preview['eligible'], json_encode($preview));
        $this->assertSame($this->at('2036-12-19 14:09:35'), $preview['new_expired_at']);
        $this->assertSame($this->at('2036-12-19 14:09:35'), $preview['new_next_reset_at']);
        $this->assertEqualsWithDelta(7.4, $preview['consume_days'], 0.05);

        $result = app(AdvanceCycleService::class)->advance($user);
        $this->assertTrue($result['advanced'] ?? false, json_encode($result));

        $user->refresh();
        $this->assertSame(0, (int) $user->u + (int) $user->d);
        $this->assertSame($this->at('2036-12-19 14:09:35'), (int) $user->expired_at, '只扣本周期剩下的 7 天多');
        $this->assertSame($this->at('2036-12-19 14:09:35'), (int) $user->next_reset_at, '新周期满一个月');

        $log = TrafficResetLog::where('user_id', $user->id)->where('reset_type', TrafficResetLog::TYPE_ADVANCE_CYCLE)->firstOrFail();
        $this->assertEqualsWithDelta(7.4, $log->metadata['consume_days'], 0.05);
    }

    public function test_case_5218_short_remainder_costs_only_those_days(): void
    {
        // 同 #5218 的形状（本周期只剩约 3 天），日期挪到将来
        $this->freezeAt('2037-01-14 18:28:36');
        $user = $this->user('2037-02-17 19:38:17', '2037-01-17 19:38:17');

        $result = app(AdvanceCycleService::class)->advance($user);
        $this->assertTrue($result['advanced'] ?? false, json_encode($result));

        $user->refresh();
        $this->assertSame($this->at('2037-02-14 18:28:36'), (int) $user->expired_at);
        $this->assertSame($this->at('2037-02-14 18:28:36'), (int) $user->next_reset_at);
    }

    public function test_longer_subscription_keeps_the_remaining_full_cycles_and_reanchors(): void
    {
        $this->freezeAt('2036-11-19 12:16:05');
        $user = $this->user('2037-01-26 22:29:00', '2036-11-26 22:29:00');

        $result = app(AdvanceCycleService::class)->advance($user);
        $this->assertTrue($result['advanced'] ?? false, json_encode($result));

        $user->refresh();
        // 原来 11-26 之后还有 2 个完整周期 -> 从现在起保留 2 个完整周期
        $this->assertSame($this->at('2037-01-19 12:16:05'), (int) $user->expired_at);
        $this->assertSame($this->at('2036-12-19 12:16:05'), (int) $user->next_reset_at);

        // 12-19 自然重置之后，下一次按新到期日的锚点排在 1-19，不会出现几天的短周期
        $this->freezeAt('2036-12-19 12:16:06');
        $next = app(TrafficResetService::class)->calculateNextResetTime($user->fresh()->load('plan'));
        $this->assertSame($this->at('2037-01-19 12:16:05'), $next->timestamp);
    }

    public function test_month_end_anchor_does_not_create_a_short_extra_cycle(): void
    {
        // 31 号锚点遇到 2 月：原下次重置被夹到 2-28
        $this->freezeAt('2037-02-20 10:00:00');
        $user = $this->user('2037-05-31 10:00:00', '2037-02-28 10:00:00');

        $result = app(AdvanceCycleService::class)->advance($user);
        $this->assertTrue($result['advanced'] ?? false, json_encode($result));

        $user->refresh();
        $this->assertSame($this->at('2037-05-20 10:00:00'), (int) $user->expired_at);
        $this->assertSame($this->at('2037-03-20 10:00:00'), (int) $user->next_reset_at);

        $this->freezeAt('2037-03-20 10:00:01');
        $next = app(TrafficResetService::class)->calculateNextResetTime($user->fresh()->load('plan'));
        $this->assertSame($this->at('2037-04-20 10:00:00'), $next->timestamp, '下一次重置仍隔一个整月');
    }

    public function test_last_cycle_is_not_eligible(): void
    {
        // 下次重置就是到期日：扣完后不足一个完整周期，不给
        $this->freezeAt('2036-11-19 14:09:35');
        $user = $this->user('2036-11-26 22:48:27', '2036-11-26 22:48:27');

        $result = app(AdvanceCycleService::class)->advance($user);
        $this->assertFalse($result['advanced'] ?? false);
        $this->assertSame('insufficient_remaining_days', $result['reason']);
        $this->assertSame($this->at('2036-11-26 22:48:27'), (int) $user->fresh()->expired_at);
    }

    public function test_partial_month_after_next_reset_is_not_eligible(): void
    {
        // 到期还有 40 天，但下次重置在 15 天后，之后只剩 25 天：扣完不满一个完整周期
        $this->freezeAt('2036-11-19 10:00:00');
        $user = $this->user('2036-12-29 10:00:00', '2036-12-04 10:00:00');

        $result = app(AdvanceCycleService::class)->advance($user);
        $this->assertFalse($result['advanced'] ?? false);
        $this->assertSame('insufficient_remaining_days', $result['reason']);
    }

    public function test_follow_system_plan_uses_monthly_semantics(): void
    {
        $this->freezeAt('2036-11-19 14:09:35');
        $user = $this->user('2036-12-26 22:48:27', '2036-11-26 22:48:27', Plan::RESET_TRAFFIC_FOLLOW_SYSTEM);

        $result = app(AdvanceCycleService::class)->advance($user);
        $this->assertTrue($result['advanced'] ?? false, json_encode($result));
        $this->assertSame($this->at('2036-12-19 14:09:35'), (int) $user->fresh()->expired_at);
    }

    public function test_expiry_is_never_extended(): void
    {
        // 到期时刻比锚点早几小时：仍算一个完整周期（容差 1 天），但新到期日不能晚于原到期日
        $this->freezeAt('2036-11-19 23:00:00');
        $user = $this->user('2036-12-26 20:00:00', '2036-11-26 22:00:00');

        $result = app(AdvanceCycleService::class)->advance($user);
        $this->assertTrue($result['advanced'] ?? false, json_encode($result));
        $this->assertLessThanOrEqual($this->at('2036-12-26 20:00:00'), (int) $user->fresh()->expired_at);
    }
}
