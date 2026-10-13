<?php

namespace App\Support;

/**
 * RFC 6238 TOTP（SHA-1 / 6 位 / 30 秒）—— Google Authenticator、Authy、1Password、
 * Microsoft Authenticator 等所有主流验证器的默认参数，不依赖第三方包。
 *
 * 只做纯算法：密钥生成、Base32 编解码、按时间步算码、带窗口比对、otpauth:// 地址。
 * 「同一个码不能用两次」「错满几次作废」之类的策略在 AdminTwoFactorService 里。
 */
final class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;
    /** 比对时允许前后各偏一个时间步（±30 秒），吸收手机与服务器的时钟误差 */
    public const WINDOW = 1;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** 160 位随机密钥，Base32 后 32 个字符（RFC 4226 推荐长度） */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $out;
    }

    public static function base32Decode(string $encoded): string
    {
        $clean = strtoupper((string) preg_replace('/[^A-Za-z2-7]/', '', $encoded));
        $bits = '';
        foreach (str_split($clean) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index === false) {
                continue;
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    public static function currentStep(?int $timestamp = null): int
    {
        return intdiv($timestamp ?? time(), self::PERIOD);
    }

    /** 某个时间步对应的 6 位码 */
    public static function codeAt(string $secret, int $step): string
    {
        $key = self::base32Decode($secret);
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);
        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * 在 ±WINDOW 个时间步内比对验证码，命中返回那个时间步（调用方用它防重放），否则 null。
     * 输入里的空格 / 连字符会被忽略，方便用户照着验证器里的「123 456」输入。
     */
    public static function matchStep(string $secret, string $code, ?int $timestamp = null, int $window = self::WINDOW): ?int
    {
        $digits = (string) preg_replace('/\D+/', '', $code);
        if (strlen($digits) !== self::DIGITS) {
            return null;
        }
        $now = self::currentStep($timestamp);
        for ($i = -$window; $i <= $window; $i++) {
            $step = $now + $i;
            if (hash_equals(self::codeAt($secret, $step), $digits)) {
                return $step;
            }
        }
        return null;
    }

    /** 验证器扫码用的 otpauth:// 地址（issuer 同时写进 label 与参数，兼容所有 App） */
    public static function otpauthUrl(string $issuer, string $account, string $secret): string
    {
        $issuer = trim($issuer) !== '' ? trim($issuer) : 'XBoard';
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
        return 'otpauth://totp/' . $label . '?' . $query;
    }
}
