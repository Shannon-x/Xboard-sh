<?php

/*
 * 定时任务（Laravel Schedule）的运行方式与健康检查。
 *
 * 推荐部署：单独一个 scheduler 容器 / 进程跑 `php artisan schedule:work`（每分钟起一个独立的
 * schedule:run 子进程，某次卡住不影响下一分钟）。网页进程（Octane）里的 tick 只当看门狗：
 * 心跳过期就临时代跑并告警。2026-11-23 定时任务整块停摆近三天、没人发现，就是因为调度原来
 * 只挂在 Octane tick 里跑、也没有任何心跳告警。
 */
return [
    /*
     * 网页进程里的调度 tick：
     *   auto —— 只在心跳过期时代跑 schedule:run（独立子进程），并告警（默认）；
     *   on   —— 每分钟都代跑（没有单独 scheduler 进程的旧部署）；
     *   off  —— 不代跑，只做心跳告警。
     */
    'octane_tick' => env('SCHEDULER_OCTANE_TICK', 'auto'),

    // 心跳超过这么多秒没更新就算停摆：auto 模式开始代跑，并提醒「专用调度进程没在跑」
    'stale_after' => (int) env('SCHEDULER_STALE_AFTER', 150),

    // 心跳超过这么多秒没更新（代跑也没跑起来）就发紧急告警
    'alert_after' => (int) env('SCHEDULER_ALERT_AFTER', 300),

    // 可选：每分钟心跳时 GET 这个地址（如 Uptime Kuma 的 Push 监控），面板整个挂了也能从外部发现
    'heartbeat_url' => env('SCHEDULER_HEARTBEAT_URL'),
];
