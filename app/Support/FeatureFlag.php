<?php

namespace App\Support;

use Illuminate\Support\Facades\Config;

/**
 * 读取 config/feature_flags.php 的统一入口。
 *
 * 用法：
 *   FeatureFlag::enabled('payment_secret_hide')           // bool
 *   FeatureFlag::mode('payment_amount_check')             // 'off' | 'warn' | 'enforce'
 *   FeatureFlag::is('payment_amount_check', 'enforce')    // bool 比较模式
 */
class FeatureFlag
{
    /** 布尔型 flag。 */
    public static function enabled(string $key): bool
    {
        $value = Config::get("feature_flags.{$key}");

        if (is_bool($value)) {
            return $value;
        }

        // 字符串型 flag 视 'enforce'/'true'/'on' 为开
        if (is_string($value)) {
            return in_array(strtolower($value), ['enforce', 'true', 'on', '1'], true);
        }

        return (bool) $value;
    }

    /** 三档字符串 mode（off / warn / enforce）。 */
    public static function mode(string $key): string
    {
        $value = Config::get("feature_flags.{$key}");

        if (is_bool($value)) {
            return $value ? 'enforce' : 'off';
        }

        $normalized = is_string($value) ? strtolower($value) : 'off';

        return in_array($normalized, ['off', 'warn', 'enforce'], true) ? $normalized : 'off';
    }

    /** 比较 mode 是否等于给定值。 */
    public static function is(string $key, string $expected): bool
    {
        return self::mode($key) === strtolower($expected);
    }
}
