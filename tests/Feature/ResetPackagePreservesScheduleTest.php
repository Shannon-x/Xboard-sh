<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TrafficResetService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 回归测试：流量重置包只清零流量，不得把已排定的下次重置日往后推。
 *
 * 背景（真实事故 —— user 10100 / 工单 #2444）：
 *   8-21 入口 IP 被墙的故障补偿用裸 SQL 给受影响用户 expired_at +7 天
 *   （08-27 22:11 → 09-03 22:11），刻意没动 next_reset_at，用户面板上仍显示
 *   「3 天后重置」。用户随后花 36.12 元买了流量重置包，performReset 顺手按**新的**
 *   expired_at 重新锚定 next_reset_at，把 08-27 那次重置推到了 09-03。
 *   结果：付费产品把用户本就该拿到的一轮 550GB 吃掉了，面板上「3 天后重置」
 *   在付款后当场变成「10 天后」。
 *
 * 修复：performReset($user, $source, preserveSchedule: true) 时，若原定重置时间
 *   仍在未来且早于重算结果，保留原值。只有 expired_at 真的变了（续费重开周期 /
 *   套餐变更 / 新购）才重新锚定，那些路径不传 preserveSchedule。
 *
 * 本测试不碰数据库：只驱动纯计算的私有方法，因此不依赖 migration 在 SQLite 上的可用性。
 */
class ResetPackagePreservesScheduleTest extends TestCase
{
    private function keepScheduled(?int $scheduled, ?int $recalculated): ?int
    {
        $user = new User();
        $user->next_reset_at = $scheduled;

        $method = new ReflectionMethod(TrafficResetService::class, 'keepScheduledResetIfEarlier');
        $method->setAccessible(true);

        return $method->invoke(new TrafficResetService(), $user, $recalculated);
    }

    /**
     * 事故场景本身：原定 2 天后重置，重算结果被补偿后的 expired_at 推到 9 天后。
     * 必须保留原定时间，否则用户花钱反而少拿一轮流量。
     */
    public function test_reset_package_keeps_the_earlier_scheduled_reset(): void
    {
        $scheduled = time() + 2 * 86400;
        $recalculated = time() + 9 * 86400;

        $this->assertSame(
            $scheduled,
            $this->keepScheduled($scheduled, $recalculated),
            '重置包不得把已排定且仍在未来的重置日往后推'
        );
    }

    /**
     * 已过期的排期必须重算 —— 否则每分钟跑的 reset:traffic 会反复命中同一个
     * 时间点，把用户的流量无限重置。
     */
    public function test_expired_schedule_is_always_recalculated(): void
    {
        $recalculated = time() + 9 * 86400;

        $this->assertSame(
            $recalculated,
            $this->keepScheduled(time() - 60, $recalculated),
            '原定时间已过期时必须重算，否则 cron 会无限重置'
        );
    }

    /**
     * 本方法只会保留更早的排期，永远不会把重置日往后拖。
     */
    public function test_never_pushes_the_reset_later(): void
    {
        $recalculated = time() + 3 * 86400;

        $this->assertSame(
            $recalculated,
            $this->keepScheduled(time() + 20 * 86400, $recalculated),
            '重算结果更早时应按重算走'
        );
    }

    public function test_null_schedule_falls_back_to_recalculated(): void
    {
        $recalculated = time() + 3 * 86400;

        $this->assertSame($recalculated, $this->keepScheduled(null, $recalculated));
    }

    /**
     * 套餐配置为「不重置」时重算结果是 null，应如实落 null，不能被旧排期救回来。
     */
    public function test_null_recalculated_wins(): void
    {
        $this->assertNull($this->keepScheduled(time() + 3 * 86400, null));
    }

    /**
     * 守卫住调用点本身：重置包订单分支若哪天被改回不传 preserveSchedule，此测试会失败。
     */
    public function test_reset_traffic_order_passes_preserve_schedule(): void
    {
        $source = file_get_contents(app_path('Services/OrderService.php'));

        $this->assertMatchesRegularExpression(
            '/private function resetTrafficForOrder\(\).*?performReset\([^)]*preserveSchedule:\s*true/s',
            $source,
            'resetTrafficForOrder() 必须传 preserveSchedule: true，否则重置包会吞掉用户已排定的免费重置'
        );
    }
}
