<?php

namespace Tests\Unit;

use App\Support\PageParams;
use PHPUnit\Framework\TestCase;

/**
 * 分页参数解析（纯函数）：只认十进制整数，其余回落默认值，再夹到上下限。
 */
class PageParamsTest extends TestCase
{
    public function test_plain_integers_pass_through(): void
    {
        $this->assertSame(1, PageParams::page(null));
        $this->assertSame(1, PageParams::page('1'));
        $this->assertSame(3, PageParams::page('3'));
        $this->assertSame(3, PageParams::page(3));
        $this->assertSame(3, PageParams::page(' 3 '));
        $this->assertSame(20, PageParams::size('20', 10, 10, 100));
    }

    public function test_probe_strings_and_arrays_fall_back_to_the_default(): void
    {
        foreach (['1 AND SLEEP(3)', "1' OR '1'='1", 'abc', '', '1.5', '1e3', '0x10', ['1'], ['a' => ['b' => '1']], 1.5] as $value) {
            $label = var_export($value, true);
            $this->assertSame(1, PageParams::page($value), "page({$label})");
            $this->assertSame(10, PageParams::size($value, 10, 10, 100), "size({$label})");
        }
    }

    public function test_out_of_range_values_are_clamped(): void
    {
        $this->assertSame(1, PageParams::page('0'));
        $this->assertSame(1, PageParams::page('-5'));
        $this->assertSame(PageParams::MAX_PAGE, PageParams::page((string) PHP_INT_MAX));
        $this->assertSame(PageParams::MAX_PAGE, PageParams::page(PHP_INT_MAX));
        // 超出 PHP_INT_MAX 的数字串不是合法整数：回第 1 页，不做溢出转换
        $this->assertSame(1, PageParams::page('99999999999999999999'));

        $this->assertSame(10, PageParams::size('3', 10, 10, 100));
        $this->assertSame(100, PageParams::size('1000', 10, 10, 100));
        $this->assertSame(5, PageParams::size(null, 5, 1, 50));
    }
}
