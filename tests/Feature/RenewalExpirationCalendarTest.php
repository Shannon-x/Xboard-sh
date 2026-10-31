<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Services\OrderService;
use Carbon\Carbon;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 开通 / 续费的到期日 = 起算时刻 + N 个日历月，日历按站点时区（app.timezone）算。
 *
 * 曾经的 bug：getTime() 用 Carbon::createFromTimestamp($ts)，Carbon 3 下落在 UTC。北京时间 0–8 点
 * 下单时 UTC 还是前一天，月份加在前一天上：11-01 01:00 买月付到 12-02（多 1 天），03-01 03:00
 * 买月付到 03-29（少 3 天）。这组用例只在月末这类日子才暴露，所以用固定日期而不是 time()。
 *
 * 起算时刻早于 now 时 getTime() 会改从 now 起算，所以固定日期都取在远未来。
 */
class RenewalExpirationCalendarTest extends TestCase
{
    private function expiry(string $period, string $localStart): string
    {
        $order = new Order();
        $order->type = Order::TYPE_RENEWAL;
        $order->period = $period;

        $method = new ReflectionMethod(OrderService::class, 'getTime');
        $method->setAccessible(true);
        $start = Carbon::parse($localStart, config('app.timezone'))->timestamp;
        $end = $method->invoke(new OrderService($order), $period, $start);

        return Carbon::createFromTimestamp($end, config('app.timezone'))->format('Y-m-d H:i');
    }

    public function test_site_timezone_is_what_the_cases_assume(): void
    {
        $this->assertSame('Asia/Shanghai', config('app.timezone'));
    }

    /** 北京时间凌晨（UTC 还在前一天）下单，到期日按北京日历走，不多不少。 */
    public function test_early_morning_orders_follow_the_local_calendar(): void
    {
        // 旧实现：2041-12-02 01:00（多 1 天）
        $this->assertSame('2041-12-01 01:00', $this->expiry(Plan::PERIOD_MONTHLY, '2041-11-01 01:00'));
        // 旧实现：2041-03-29 03:00（少 3 天）
        $this->assertSame('2041-04-01 03:00', $this->expiry(Plan::PERIOD_MONTHLY, '2041-03-01 03:00'));
        // 旧实现：2041-05-31 07:59（少 1 天）
        $this->assertSame('2041-06-01 07:59', $this->expiry(Plan::PERIOD_MONTHLY, '2041-05-01 07:59'));
        $this->assertSame('2041-09-15 00:00', $this->expiry(Plan::PERIOD_QUARTERLY, '2041-06-15 00:00'));
        $this->assertSame('2042-01-01 06:30', $this->expiry(Plan::PERIOD_YEARLY, '2041-01-01 06:30'));
    }

    /**
     * 目标月没有这一天时顺延到下月初（与 PHP strtotime('+1 month') 及原版 Xboard 一致），
     * 用户拿到的天数不少于一个整月。
     */
    public function test_month_end_overflow_rolls_into_the_next_month(): void
    {
        $this->assertSame('2041-03-03 10:00', $this->expiry(Plan::PERIOD_MONTHLY, '2041-01-31 10:00'));
        $this->assertSame('2041-12-01 01:00', $this->expiry(Plan::PERIOD_MONTHLY, '2041-10-31 01:00'));
        $this->assertSame('2044-03-02 03:00', $this->expiry(Plan::PERIOD_MONTHLY, '2044-01-31 03:00'));   // 闰年
        $this->assertSame('2044-03-29 12:00', $this->expiry(Plan::PERIOD_MONTHLY, '2044-02-29 12:00'));
        $this->assertSame('2045-03-01 12:00', $this->expiry(Plan::PERIOD_YEARLY, '2044-02-29 12:00'));
        $this->assertSame('2042-01-31 23:59', $this->expiry(Plan::PERIOD_MONTHLY, '2041-12-31 23:59'));
    }

    /** 一整年每一天、跨 UTC 日界线的几个钟点：只要起算日 ≤ 28 号，到期日就是下月同一天同一时刻。 */
    public function test_day_of_month_and_clock_time_are_kept_all_year(): void
    {
        $tz = config('app.timezone');
        $day = Carbon::parse('2041-01-01', $tz);
        $end = Carbon::parse('2042-01-01', $tz);
        for (; $day->lt($end); $day->addDay()) {
            if ($day->day > 28) {
                continue;
            }
            foreach (['00:00', '03:30', '07:59', '08:00', '23:59'] as $clock) {
                $start = $day->format('Y-m-d') . ' ' . $clock;
                $next = $day->copy()->addMonthNoOverflow()->format('Y-m-d') . ' ' . $clock;
                $this->assertSame($next, $this->expiry(Plan::PERIOD_MONTHLY, $start), "起算 {$start}");
            }
        }
    }
}
