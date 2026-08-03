<?php

namespace Tests\Unit;

use App\Utils\Helper;
use PHPUnit\Framework\TestCase;

/**
 * 回归测试：订单号的时间段必须是 YmdHis。
 *
 * 曾误写成 date('YmdHms')——'m' 是月份不是分钟，于是订单号里月份出现两次、根本没有
 * 分钟：2026-08-03 09:11:21 和 09:08:21 都编码成「20260803090821」。排查支付问题时
 * 拿订单号反推时间会得到凭空多出几分钟的假时间差。
 */
class GenerateOrderNoTest extends TestCase
{
    public function test_order_no_is_25_digits(): void
    {
        // 14 位时间 + 6 位微秒 + 5 位 CSPRNG 随机数；各 trade_no 列最窄 char(36)，需留有余量
        $this->assertMatchesRegularExpression('/^\d{25}$/', Helper::generateOrderNo());
    }

    public function test_order_no_encodes_minutes_not_month_twice(): void
    {
        // 跨分钟边界时取样可能落在前后两分钟中的任意一个，两者都算通过
        $before = date('YmdHi');
        $orderNo = Helper::generateOrderNo();
        $after = date('YmdHi');

        $this->assertContains(
            substr($orderNo, 0, 12),
            [$before, $after],
            '订单号前 12 位必须是 YmdHi；旧实现用 YmdHms 把月份写了两次、丢掉了分钟'
        );
    }

    public function test_order_no_time_prefix_parses_back_to_now(): void
    {
        $orderNo = Helper::generateOrderNo();
        $parsed = \DateTime::createFromFormat('YmdHis', substr($orderNo, 0, 14));

        $this->assertNotFalse($parsed, '订单号前 14 位不是合法的 YmdHis');
        $this->assertLessThanOrEqual(
            5,
            abs($parsed->getTimestamp() - time()),
            '订单号解析出的时间应等于当前时间'
        );
    }

    public function test_order_no_is_unique_across_rapid_calls(): void
    {
        // 同一秒内连续下单必须仍然唯一（靠微秒 + 随机数），修改时间格式不得削弱这一点
        $generated = [];
        for ($i = 0; $i < 200; $i++) {
            $generated[] = Helper::generateOrderNo();
        }

        $this->assertCount(200, array_unique($generated));
    }
}
