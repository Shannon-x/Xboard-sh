<?php

namespace App\Services\Mail;

use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * 把邮件交给 Rust 投递服务（仓库 mail-relay/，容器 mail-relay）发：PHP 只负责渲染成完整的 MIME 原文，
 * 塞进 Redis Stream 就返回，SMTP 往返、连接池、并发、限速、重试都在投递服务里做。
 *
 * 默认关闭。开启要两个条件同时满足，缺一个就照旧由 PHP worker 自己连 SMTP 发（与上线前完全一样）：
 *   1. .env 的 MAIL_RELAY_LANES 列出了这条通道（instant / transactional / bulk，逗号分隔）
 *   2. 投递服务的心跳是新的（30 秒内）且它已经拿到 SMTP 配置
 * 投递服务停了、Redis 写失败，都会自动退回 PHP 直发，不丢信。
 *
 * Redis key（Laravel 的 key 前缀由 phpredis 自动加上，投递服务那边用 RELAY_KEY_PREFIX 配成一样）：
 *   mail:relay:stream:<lane>  待发的信
 *   mail:relay:results        投递结果，由 mail:relay-results 命令记 v2_mail_log、回写收据状态
 *   mail:relay:config         SMTP 账号（来自后台邮件设置，带版本号；改了设置下一封信时自动推过去）
 *   mail:relay:heartbeat      投递服务的心跳
 * 字段约定与 mail-relay/src/mail.rs 一致，改一边要改另一边。
 */
final class MailRelay
{
    public const HEARTBEAT_MAX_AGE = 30;

    /** MailService::deliver 返回的 category：已交给投递服务，结果稍后由 mail:relay-results 记 */
    public const QUEUED = 'queued';

    /** 正在交给投递服务的那封信的上下文（RelayTransport 读）：Mailer::send 中途没有别的办法把 lane / meta 传进 transport */
    public static ?array $context = null;

    /** 测试用：设了就不读写 Redis（心跳当作正常），入队时改为调用 fn (string $lane, array $fields) */
    public static $sink = null;

    private static ?array $alive = null;
    private static int $configSyncedAt = 0;
    private static ?Mailer $mailer = null;

    public static function key(string $name): string
    {
        return 'mail:relay:' . $name;
    }

    /** mail_bulk → bulk（投递服务里的通道名） */
    public static function laneName(string $lane): string
    {
        return match (MailLane::normalize($lane)) {
            MailLane::INSTANT => 'instant',
            MailLane::BULK => 'bulk',
            default => 'transactional',
        };
    }

    /** @return string[] 开了投递服务的通道名 */
    public static function lanes(): array
    {
        $raw = config('mail_lanes.relay.lanes', []);
        $list = is_array($raw) ? $raw : explode(',', (string) $raw);
        return array_values(array_filter(array_map(fn ($l) => strtolower(trim((string) $l)), $list), fn ($l) => in_array($l, ['instant', 'transactional', 'bulk'], true)));
    }

    public static function enabledFor(?string $lane): bool
    {
        if ($lane === null || $lane === '') {
            return false;
        }
        if (!in_array(self::laneName($lane), self::lanes(), true)) {
            return false;
        }
        return self::alive();
    }

    /** 投递服务在不在：每个进程 5 秒看一次心跳 */
    public static function alive(): bool
    {
        if (self::$sink !== null) {
            return true;
        }
        $now = time();
        if (self::$alive !== null && $now - self::$alive['checked'] < 5) {
            return self::$alive['ok'];
        }
        $ok = false;
        try {
            $hb = json_decode((string) Redis::get(self::key('heartbeat')), true);
            $ok = is_array($hb) && !empty($hb['smtp']) && $now - (int) ($hb['at'] ?? 0) <= self::HEARTBEAT_MAX_AGE;
        } catch (\Throwable $e) {
            Log::warning('[mail] 读投递服务心跳失败', ['error' => $e->getMessage()]);
        }
        self::$alive = ['ok' => $ok, 'checked' => $now];
        return $ok;
    }

    /**
     * 后台邮件设置推给投递服务。内容没变就不写；每个进程最多 60 秒查一次版本号。
     * 投递服务每 10 秒看一次版本号，变了就换新连接池。密码只存在 Redis（和后台设置缓存同一个库）。
     */
    public static function syncConfig(bool $force = false): void
    {
        if (self::$sink !== null) {
            return;
        }
        if (!$force && time() - self::$configSyncedAt < 60) {
            return;
        }
        $fields = [
            'host' => (string) admin_setting('email_host', config('mail.host')),
            'port' => (string) admin_setting('email_port', config('mail.port')),
            'encryption' => (string) admin_setting('email_encryption', config('mail.encryption')),
            'username' => (string) admin_setting('email_username', config('mail.username')),
            'password' => (string) admin_setting('email_password', config('mail.password')),
        ];
        $fields['version'] = substr(hash('sha256', json_encode($fields)), 0, 16);
        if ((string) Redis::hget(self::key('config'), 'version') !== $fields['version']) {
            Redis::hmset(self::key('config'), $fields);
        }
        self::$configSyncedAt = time();
    }

    /**
     * 用和直发完全相同的方式（同一个视图、同样的附件和头）组好信，交给投递服务。
     *
     * @param callable $build function (\Illuminate\Mail\Message $message)：设收件人、主题、附件、头
     * @param array $meta 结果回来时要用的东西（收件人、主题、视图、用户、收据回调……），原样带回结果流
     * @return string 这封信的 id（结果流里按它对应）
     */
    public static function send(string $lane, string $view, array $data, callable $build, array $meta, int $notAfter = 0): string
    {
        $id = (string) Str::ulid();
        self::$context = ['lane' => self::laneName($lane), 'id' => $id, 'meta' => $meta, 'not_after' => max(0, $notAfter)];
        try {
            $mailer = self::mailer();
            $mailer->alwaysFrom((string) config('mail.from.address'), (string) config('mail.from.name'));
            $mailer->send($view, $data, $build);
        } finally {
            self::$context = null;
        }
        return $id;
    }

    private static function mailer(): Mailer
    {
        return self::$mailer ??= new Mailer('relay', app('view'), new RelayTransport(), app('events'));
    }

    /** 写进通道。大邮件（带 PDF 的收据 / 账单）写到 spool 目录，通道里只放文件名 */
    public static function enqueue(string $lane, string $id, string $from, string $to, string $raw, int $notAfter, array $meta): void
    {
        $fields = ['id' => $id, 'from' => $from, 'to' => $to, 'not_after' => (string) $notAfter, 'attempt' => '0',
            'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        $max = (int) config('mail_lanes.relay.inline_max_bytes', 65536);
        if (strlen($raw) > $max) {
            $dir = self::spoolDir();
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException("cannot create mail spool dir {$dir}");
            }
            $name = $id . '.eml';
            if (file_put_contents($dir . '/' . $name . '.tmp', $raw) === false || !rename($dir . '/' . $name . '.tmp', $dir . '/' . $name)) {
                throw new \RuntimeException("cannot write mail spool file {$name}");
            }
            $fields['eml_path'] = $name;
        } else {
            $fields['eml'] = $raw;
        }
        if (self::$sink !== null) {
            (self::$sink)($lane, $fields);
            return;
        }
        Redis::xadd(self::key('stream:' . $lane), '*', $fields);
    }

    public static function spoolDir(): string
    {
        return (string) config('mail_lanes.relay.spool_dir', storage_path('mail-spool'));
    }

    /** 测试 / 进程重置用 */
    public static function reset(): void
    {
        self::$alive = null;
        self::$configSyncedAt = 0;
        self::$context = null;
        self::$mailer = null;
    }
}
