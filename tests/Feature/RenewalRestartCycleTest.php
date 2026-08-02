<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Services\OrderService;
use App\Support\Setting;
use Carbon\Carbon;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 续费「重开周期」判定规则。
 *
 * 规则：同套餐续费时，若 ① 流量已耗尽 且 ② 从今天重开不会缩短到期日，
 * 则到期日从付款时刻重算并立即重置流量；否则维持原有的「时间叠加、不重置流量」。
 *
 * 两个条件各自挡住一类事故：
 *   ① 挡「手滑提前续费」——还剩大半配额的用户被重开会白丢时间。
 *   ② 挡「长周期买短周期」——年付剩 11 个月的用户流量跑完时也满足①，
 *      若只看① 就会被一张月付单把 11 个月烧成 1 个月。
 *
 * 本测试不碰数据库：只驱动纯判定函数，Setting 用桩件顶掉以免 admin_setting() 查库。
 */
class RenewalRestartCycleTest extends TestCase
{
    private const GB = 1073741824;
    private const DAY = 86400;

    protected function setUp(): void
    {
        parent::setUp();

        // admin_setting('advance_cycle_used_ratio') 会走 Setting::get()，这里让它一律回落默认值 0.95
        $this->app->instance(Setting::class, new class extends Setting {
            public function __construct()
            {
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return null;
            }
        });
    }

    private function decide(
        int $type,
        string $period,
        ?int $oldExpiredAt,
        int $usedTraffic,
        int $transferEnable
    ): bool {
        // 不给构造函数传属性：Order 的 $guarded 会让 fill() 去查表结构，而这套测试不跑 migration。
        $order = new Order();
        $order->type = $type;
        $order->period = $period;

        $method = new ReflectionMethod(OrderService::class, 'shouldRestartRenewalCycle');
        $method->setAccessible(true);

        return $method->invoke(
            new OrderService($order),
            $order,
            $oldExpiredAt,
            $usedTraffic,
            $transferEnable
        );
    }

    /** 月付用户第 5 天跑完流量续月付 → 重开（今天+1月 = Day35 晚于原到期 Day31） */
    public function test_monthly_exhausted_restarts_cycle(): void
    {
        $this->assertTrue($this->decide(
            Order::TYPE_RENEWAL,
            Plan::PERIOD_MONTHLY,
            time() + (26 * self::DAY),   // 还剩 26 天
            100 * self::GB,              // 用满
            100 * self::GB
        ));
    }

    /** 月付用户第 2 天没跑完就续费 → 不重开，走叠加，保住剩余 28 天 */
    public function test_monthly_with_traffic_left_keeps_stacking(): void
    {
        $this->assertFalse($this->decide(
            Order::TYPE_RENEWAL,
            Plan::PERIOD_MONTHLY,
            time() + (28 * self::DAY),
            10 * self::GB,               // 只用了 10%
            100 * self::GB
        ));
    }

    /** 关键防线：年付剩 11 个月、流量跑完时买月付 → 条件②不成立 → 不重开 */
    public function test_yearly_remainder_is_never_burned_by_a_monthly_renewal(): void
    {
        $this->assertFalse($this->decide(
            Order::TYPE_RENEWAL,
            Plan::PERIOD_MONTHLY,
            time() + (330 * self::DAY),  // 还剩约 11 个月
            500 * self::GB,              // 流量确实跑完了（条件①满足）
            500 * self::GB
        ));
    }

    /** 季付剩 20 天、流量跑完买月付 → 今天+1月 晚于原到期日 → 重开，用户还多赚 10 天 */
    public function test_short_remainder_restarts_and_extends(): void
    {
        $this->assertTrue($this->decide(
            Order::TYPE_RENEWAL,
            Plan::PERIOD_MONTHLY,
            time() + (20 * self::DAY),
            300 * self::GB,
            300 * self::GB
        ));
    }

    /** 恰好卡在 95% 阈值上：达到即算耗尽 */
    public function test_threshold_is_inclusive_at_95_percent(): void
    {
        $enable = 100 * self::GB;

        $this->assertTrue($this->decide(
            Order::TYPE_RENEWAL,
            Plan::PERIOD_MONTHLY,
            time() + (26 * self::DAY),
            (int) ceil($enable * 0.95),
            $enable
        ));

        $this->assertFalse($this->decide(
            Order::TYPE_RENEWAL,
            Plan::PERIOD_MONTHLY,
            time() + (26 * self::DAY),
            (int) ceil($enable * 0.95) - 1,
            $enable
        ));
    }

    /** 新购 / 套餐变更 / 流量重置包都不走这条规则 */
    public function test_only_renewal_type_is_affected(): void
    {
        foreach ([Order::TYPE_NEW_PURCHASE, Order::TYPE_UPGRADE, Order::TYPE_RESET_TRAFFIC] as $type) {
            $this->assertFalse(
                $this->decide($type, Plan::PERIOD_MONTHLY, time() + (26 * self::DAY), 100 * self::GB, 100 * self::GB),
                "type={$type} 不应触发续费重开周期"
            );
        }
    }

    /** 永久订阅（expired_at = null）没有周期可重开 */
    public function test_lifetime_subscription_is_untouched(): void
    {
        $this->assertFalse($this->decide(
            Order::TYPE_RENEWAL,
            Plan::PERIOD_MONTHLY,
            null,
            500 * self::GB,
            500 * self::GB
        ));
    }

    /** 无流量配额时不做判定，避免除零式的误判 */
    public function test_zero_quota_does_not_restart(): void
    {
        $this->assertFalse($this->decide(
            Order::TYPE_RENEWAL,
            Plan::PERIOD_MONTHLY,
            time() + (26 * self::DAY),
            0,
            0
        ));
    }

    /** 重开后的到期日就是「今天 + 新周期」，不含旧周期残留 */
    public function test_restarted_expiration_is_exactly_one_period_from_now(): void
    {
        $order = new Order();
        $order->type = Order::TYPE_RENEWAL;
        $order->period = Plan::PERIOD_MONTHLY;

        $method = new ReflectionMethod(OrderService::class, 'getTime');
        $method->setAccessible(true);
        $restarted = $method->invoke(new OrderService($order), Plan::PERIOD_MONTHLY, time());

        $this->assertEqualsWithDelta(
            Carbon::now()->addMonth()->timestamp,
            $restarted,
            5
        );
    }
}
