<?php

namespace App\Services\Telegram;

/**
 * 群管理机器人签发的「一键绑定」票据。
 *
 * 用户在机器人的入群验证页点「用面板账号一键绑定」，机器人签一张票据，带着它打开面板的 /tg-bind 页；
 * 面板上已登录的用户点「确认绑定」，前端把票据交给 /api/v1/user/telegram/gateBind，这里验签后
 * 把票据里的 Telegram ID 写进当前登录的账号。
 *
 * 格式：base64url(JSON 载荷) . base64url(HMAC-SHA256(secret, "xbgate-bind:" . 载荷段))
 * 载荷：{ v: 1, tg: Telegram 用户 ID, n: 显示名, u: 用户名|null, b: 机器人用户名, exp: 过期时间戳 }
 * 密钥与入群门槛接口共用 TELEGRAM_GROUP_GATE_SECRET（机器人侧 XBOARD_GATE_SECRET）。
 *
 * 票据本身只证明「这个 Telegram 账号想绑到某个面板账号上」，绑到哪个账号由面板登录态决定，
 * 所以载荷里不需要也没有面板账号信息。显示名、用户名只用于确认页展示。
 */
final class GateBindTicket
{
    public const MAX_LENGTH = 1024;
    /** 机器人签发时给 15 分钟；这里再兜一道上限，防止签发方配错把有效期拉得很长 */
    public const MAX_LIFETIME = 3600;

    private function __construct(
        public readonly int $telegramId,
        public readonly string $displayName,
        public readonly ?string $username,
        public readonly ?string $botUsername,
        public readonly int $expiresAt,
        public readonly string $raw,
    ) {
    }

    public static function parse(string $raw, string $secret, ?int $now = null): ?self
    {
        $now ??= time();
        if ($secret === '' || $raw === '' || strlen($raw) > self::MAX_LENGTH) {
            return null;
        }
        $parts = explode('.', $raw);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }
        [$payloadB64, $sigB64] = $parts;

        $expected = self::b64url(hash_hmac('sha256', 'xbgate-bind:' . $payloadB64, $secret, true));
        if (!hash_equals($expected, $sigB64)) {
            return null;
        }

        $json = base64_decode(strtr($payloadB64, '-_', '+/'), true);
        $payload = $json === false ? null : json_decode($json, true);
        if (!is_array($payload) || ($payload['v'] ?? null) !== 1) {
            return null;
        }
        $tg = $payload['tg'] ?? null;
        $exp = $payload['exp'] ?? null;
        if (!is_int($tg) || $tg <= 0 || !is_int($exp) || $exp < $now || $exp > $now + self::MAX_LIFETIME) {
            return null;
        }

        $name = is_string($payload['n'] ?? null) ? mb_substr($payload['n'], 0, 64) : '';
        $username = is_string($payload['u'] ?? null) && preg_match('/^[A-Za-z0-9_]{1,32}$/', $payload['u']) ? $payload['u'] : null;
        $bot = is_string($payload['b'] ?? null) && preg_match('/^[A-Za-z0-9_]{1,32}$/', $payload['b']) ? $payload['b'] : null;

        return new self($tg, $name, $username, $bot, $exp, $raw);
    }

    /** 仅供测试与工具使用：按机器人的规则签一张票据 */
    public static function sign(array $payload, string $secret): string
    {
        $payloadB64 = self::b64url(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $payloadB64 . '.' . self::b64url(hash_hmac('sha256', 'xbgate-bind:' . $payloadB64, $secret, true));
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
