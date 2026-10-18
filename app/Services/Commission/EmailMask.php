<?php

namespace App\Services\Commission;

/**
 * 邀请相关展示用的邮箱脱敏：ab****@e****.com。
 * 把被邀请人 / 买家邮箱展示给邀请人（aff 明细、入账通知、榜单）时，保留极弱辨识度但尽量降低撞库 / 社工价值
 * （local 最多留 1-2 位、域名只露主域首字母 + 后缀）。
 *
 * 口径原本内联在 InviteController::maskEmail（刻意不走 Helper，避免被 bind-mount 的旧 Helper 覆盖）；
 * 现在抽到这里让通知与榜单复用，InviteController 的那份改为委托到此，输出完全一致。
 */
final class EmailMask
{
    public static function mask(?string $email): string
    {
        if ($email === null || $email === '' || !str_contains($email, '@')) {
            return '****';
        }
        [$local, $domain] = explode('@', $email, 2);

        $localKeep = mb_strlen($local) <= 2 ? 1 : 2;
        $maskedLocal = mb_substr($local, 0, $localKeep) . '****';

        $parts = explode('.', $domain);
        if (count($parts) >= 2) {
            $host = $parts[0];
            $suffix = implode('.', array_slice($parts, 1));
            $maskedDomain = mb_substr($host, 0, 1) . '****.' . $suffix;
        } else {
            $maskedDomain = mb_substr($domain, 0, 1) . '****';
        }

        return $maskedLocal . '@' . $maskedDomain;
    }
}
