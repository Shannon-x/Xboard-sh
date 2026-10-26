<?php

namespace App\Support;

/**
 * 用户接口的分页参数（current / page_size）解析：纯函数，可单测。
 *
 * 2026-10-26 生产上有人往 current 里塞「1 AND SLEEP(3)」，原样传给 forPage() 后
 * 在 `($page - 1) * $perPage` 处报「A non-numeric value encountered」（纯字母则是
 * TypeError），回 500（forPage 只做算术、不拼 SQL，没有注入）。
 * 这里只认十进制整数，其余（字符串、数组、小数、溢出）一律回落默认值，
 * 再夹到 [min, max]，保证交给 forPage() 的永远是小整数。
 */
class PageParams
{
    /** 页码上限：每页最多 100 条时 offset 不超过 1e7，避免乘法溢出成 float */
    public const MAX_PAGE = 100000;

    public static function page(mixed $value): int
    {
        return self::int($value, 1, 1, self::MAX_PAGE);
    }

    /**
     * 每页条数：非整数取 $default；小于 $min 的回到 $min，大于 $max 的封顶。
     */
    public static function size(mixed $value, int $default, int $min, int $max): int
    {
        return self::int($value, $default, $min, $max);
    }

    private static function int(mixed $value, int $default, int $min, int $max): int
    {
        // FILTER_VALIDATE_INT：拒绝「1 AND SLEEP(3)」「1.5」「1e3」、超出 PHP_INT_MAX 的数字串和数组，
        // 不像 (int) 强转那样截前缀或在 PHP 8.5 上对溢出值告警
        $int = is_int($value) ? $value : filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
        if ($int === null) {
            return $default;
        }
        return max($min, min($max, $int));
    }
}
