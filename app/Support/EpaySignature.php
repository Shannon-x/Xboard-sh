<?php

namespace App\Support;

/**
 * 易支付待签名串（V1 MD5 与 V2 RSA 共用同一套拼串规则）。
 *
 * 两版文档一致：取所有非空参数，不含数组 / 文件，剔除 sign、sign_type，按键名 ASCII 升序，
 * 以「参数=参数值」用 & 连接，值不做 URL 编码。
 *
 * 以前插件用 stripslashes(urldecode(http_build_query())) 拼串：http_build_query 会把空字符串
 * 也拼进去（网关每笔回调都带空的 param=），线上能验过只是因为 Kernel 的 ConvertEmptyStringsToNull
 * 先把空串变成了 null；stripslashes 还会吃掉值里的反斜杠。这里按文档显式实现，不再依赖中间件。
 */
final class EpaySignature
{
    public static function content(array $params): string
    {
        $pairs = [];
        foreach ($params as $key => $value) {
            $key = (string) $key;
            if ($key === 'sign' || $key === 'sign_type' || $value === null || is_array($value) || is_object($value)) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            $value = (string) $value;
            if ($value === '') {
                continue;
            }
            $pairs[$key] = $value;
        }
        ksort($pairs, SORT_STRING);

        $parts = [];
        foreach ($pairs as $key => $value) {
            $parts[] = $key . '=' . $value;
        }
        return implode('&', $parts);
    }

    /** V1：md5(待签名串 + 商户密钥)，小写十六进制 */
    public static function md5(array $params, string $key): string
    {
        return md5(self::content($params) . $key);
    }

    public static function verifyMd5(array $params, string $key): bool
    {
        $sign = $params['sign'] ?? null;
        if ($key === '' || !is_string($sign) || $sign === '') {
            return false;
        }
        return hash_equals(self::md5($params, $key), strtolower($sign));
    }
}
