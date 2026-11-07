<?php

namespace Tests\Unit;

use App\Models\Payment;
use App\Support\ChannelFee;
use PHPUnit\Framework\TestCase;

/** 通道手续费只展示、不进订单金额：费率解析要宽容，算出来要和收银台一样四舍五入到分 */
class ChannelFeeTest extends TestCase
{
    private function payment(array $config): Payment
    {
        $p = new Payment();
        $p->setRawAttributes(['config' => json_encode($config)]);
        return $p;
    }

    public function test_percent_reads_config(): void
    {
        $this->assertSame(3.5, ChannelFee::percent($this->payment(['channel_fee_percent' => '3.5'])));
        $this->assertSame(3.0, ChannelFee::percent($this->payment(['channel_fee_percent' => 3])));
        $this->assertSame(3.0, ChannelFee::percent($this->payment(['channel_fee_percent' => ' 3% '])));
    }

    public function test_missing_or_bad_percent_is_zero(): void
    {
        $this->assertSame(0.0, ChannelFee::percent(null));
        $this->assertSame(0.0, ChannelFee::percent($this->payment([])));
        $this->assertSame(0.0, ChannelFee::percent($this->payment(['channel_fee_percent' => ''])));
        $this->assertSame(0.0, ChannelFee::percent($this->payment(['channel_fee_percent' => 'abc'])));
        $this->assertSame(0.0, ChannelFee::percent($this->payment(['channel_fee_percent' => '-1'])));
        $this->assertSame(0.0, ChannelFee::percent($this->payment(['channel_fee_percent' => '150'])));
    }

    public function test_amount_matches_cashier_rounding(): void
    {
        // 工单实例：¥26.40 → 收银台 ¥27.19；¥95.04 → ¥97.89（均为 3%）
        $this->assertSame(79, ChannelFee::amount(2640, 3.0));
        $this->assertSame(285, ChannelFee::amount(9504, 3.0));
        $this->assertSame(92, ChannelFee::amount(2640, 3.5));
        $this->assertSame(0, ChannelFee::amount(0, 3.0));
        $this->assertSame(0, ChannelFee::amount(2640, 0.0));
    }
}
