<?php

namespace App\Services\Mail;

/**
 * 邮件通道：按「收件人要等多久」分三条队列，各有自己的 worker，批量信再多也挤不到验证码。
 *
 * 2026-11-27 生产：11:30 的到期提醒一次塞进约 2,700 封账单 / 到期 / 挽回 / 流量信，和验证码、收据同在 send_email
 * 一条先进先出的队列里、共用 2 个 worker，11:30–11:49 一封即时邮件都没发出去，而注册验证码只有 300 秒有效。
 *
 *   instant        用户正在页面上等：注册验证码、魔法登录链接、邮箱验证 / 改邮箱确认（用户自己点的那封）
 *   transactional  交易与人工触发：付款收据、按量保底凭证、提现结果、工单回复、续费结果、后台告警与日报、后台重发
 *   bulk           系统定时批量：续费账单、到期暂停、挽回、流量提醒、邮箱验证提醒、群发通知、佣金结算通知、到货提醒
 *
 * Horizon 里 instant 有专属 worker；transactional 的 worker 每次先看 instant 再看自己；bulk 单独一组，
 * 永远不碰前两条（见 config/horizon.php 的 mail-* supervisor）。
 *
 * 旧队列名 send_email / send_email_mass 仍被 bulk 组监听：升级那一刻已经排在里面的信照样发完；
 * 新代码不再往里派。
 */
final class MailLane
{
    public const INSTANT = 'mail_instant';
    public const TRANSACTIONAL = 'mail_transactional';
    public const BULK = 'mail_bulk';

    /** 升级前的队列名：只为排空存量，新代码不要再用 */
    public const LEGACY_QUEUES = ['send_email', 'send_email_mass'];

    public const ALL = [self::INSTANT, self::TRANSACTIONAL, self::BULK];

    /** 旧调用方传进来的队列名映射到新通道（send_email_mass 是后台群发 = 批量），认不得的按交易类 */
    public static function normalize(?string $queue): string
    {
        return match ($queue) {
            self::INSTANT, self::TRANSACTIONAL, self::BULK => $queue,
            'send_email_mass' => self::BULK,
            default => self::TRANSACTIONAL,
        };
    }

    public static function isBulk(?string $queue): bool
    {
        return $queue === self::BULK || $queue === 'send_email_mass';
    }
}
