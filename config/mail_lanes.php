<?php

/*
 * 邮件三条通道（App\Services\Mail\MailLane）的批量限速与即时邮件过期策略。
 * 这份跟着镜像走，数字用 .env 覆盖；改了要重启 horizon 容器才生效。
 */
return [
    // 三组 worker 的进程数在 config/horizon.php 里直接读 .env（MAIL_INSTANT_WORKERS 默认 2、
    // MAIL_TRANSACTIONAL_WORKERS 默认 2、MAIL_BULK_WORKERS 默认 3）：horizon.php 先于本文件加载，读不到这里。
    // 批量一个 worker 约每分钟 80 封（PDF + SMTP 往返）；加进程会更快，也会更快撞上 SMTP 服务商的速率上限，
    // 先看 OCI 控制台里 Email Delivery 的限额再调。

    // 批量通道全局限速（封 / 分钟，所有 bulk worker 合计）。0 = 不限，只靠进程数控制速度。
    // 开了以后拿不到配额的 worker 最多等 20 秒，还拿不到就把任务放回队列 10 秒后再试（会占一次重试次数）
    'bulk_per_minute' => (int) env('MAIL_BULK_PER_MINUTE', 0),

    // 即时邮件过期不发：派发时带 not_after（验证码 / 登录链接的有效期截止），排到时已经过了就不发，
    // v2_mail_log 记一条 category = expired（不计入用户的退信 / 临时失败计数）。0 = 关掉，过期也照发
    'drop_expired_instant' => (bool) env('MAIL_DROP_EXPIRED_INSTANT', true),

    // Rust 邮件投递服务（仓库 mail-relay/，compose 里的 mail-relay 容器）。默认不开：这里为空时所有邮件照旧由 PHP worker 直发。
    // 列出的通道（instant / transactional / bulk，逗号分隔）在投递服务心跳正常时只组信入队，由它并发发出；
    // 投递服务停了或 Redis 写失败会自动退回直发。建议先只开 bulk，看几天 v2_mail_log 再加 transactional / instant
    'relay' => [
        'lanes' => env('MAIL_RELAY_LANES', ''),
        // 组好的信超过这么大（带 PDF 的收据 / 账单）就写到 spool 目录，通道里只放文件名。投递服务要挂载同一个目录
        'inline_max_bytes' => (int) env('MAIL_RELAY_INLINE_MAX_BYTES', 65536),
        'spool_dir' => env('MAIL_RELAY_SPOOL_DIR', storage_path('mail-spool')),
    ],
];
