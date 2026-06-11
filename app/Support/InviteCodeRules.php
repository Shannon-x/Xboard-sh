<?php

namespace App\Support;

/**
 * 自定义邀请码的格式规则（纯函数，不触 DB / settings，可单测）。
 *
 * 唯一性由 v2_invite_code.code 唯一索引保证（2026_06_10_000010 迁移），
 * 这里只负责格式与保留字。
 */
class InviteCodeRules
{
    public const MIN_LEN = 4;
    public const MAX_LEN = 20;

    /**
     * 保留字黑名单（小写比较）：防止抢注带官方语义的码误导其他用户
     * （"官方邀请码 admin" 之类的社工话术），以及与系统路由/常见标识混淆。
     */
    public const RESERVED = [
        'admin',
        'administrator',
        'root',
        'system',
        'official',
        'support',
        'help',
        'service',
        'staff',
        'team',
        'moderator',
        'mod',
        'register',
        'login',
        'signup',
        'invite',
        'code',
        'aff',
        'api',
        'www',
        'mail',
        'noreply',
        'no-reply',
        'test',
        'null',
        'undefined',
        'xboard',
        'v2board',
        // 易被用于钓鱼/社工话术的官方语义词
        'refund',
        'billing',
        'pay',
        'payment',
        'verify',
        'verification',
        'security',
        'reset',
        'password',
        'coupon',
        'gift',
        'reward',
        'bonus',
        'vip',
        'premium',
    ];

    /**
     * 校验自定义码格式。
     *
     * @return string|null 不合法时返回英文错误文案（经 __() 翻译后展示）；合法返回 null
     */
    public static function validateFormat(string $code): ?string
    {
        $len = strlen($code);
        if ($len < self::MIN_LEN || $len > self::MAX_LEN) {
            return 'Invite code must be 4-20 characters';
        }

        // 仅允许字母/数字/中划线/下划线，且必须以字母或数字开头：
        // 保证 ${origin}/register?code=xxx 链接全程 URL 安全、复制粘贴无歧义
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $code)) {
            return 'Invite code may only contain letters, numbers, hyphens and underscores, and must start with a letter or number';
        }

        if (in_array(strtolower($code), self::RESERVED, true)) {
            return 'This invite code is reserved';
        }

        return null;
    }
}
