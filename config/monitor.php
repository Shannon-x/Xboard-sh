<?php

/*
 * 面板巡检（monitor:check，每分钟一次）与仪表盘「队列与定时任务」面板。
 *
 * 巡检看四样东西：Horizon 在不在、每条队列真的有没有在处理任务（金丝雀）、失败任务有没有激增、
 * 每条定时任务有没有按时开始 / 有没有连续失败。发现问题当场直发 Telegram（AdminAlert::urgent），
 * 不经过 send_telegram 队列：队列本身坏了时，经过队列的告警会和故障一起卡住。
 */
return [
    /*
     * 外部心跳（强烈建议配置）：每分钟巡检完 GET 这个地址。Uptime Kuma 的 Push 监控地址直接贴进来，
     * 一切正常推 status=up，有严重问题推 status=down 带原因；healthchecks.io 的 ping 地址也可以
     * （有严重问题时请求 <地址>/fail）。整台机器、Redis 或面板全挂时巡检本身跑不起来，
     * 只有外部监控能发现「心跳停了」。
     */
    'heartbeat_url' => env('MONITOR_HEARTBEAT_URL'),

    // 金丝雀任务排队超过这么多秒还没被执行，就算这条队列卡住（实际阈值取它和 horizon.waits + 60 秒里较大的）
    'stall_min_seconds' => (int) env('MONITOR_STALL_SECONDS', 120),

    // 这些队列不放金丝雀：升级前的旧邮件队列，只为排空存量，清零后会删掉
    'canary_skip' => ['send_email', 'send_email_mass'],

    // 10 分钟内失败任务达到这个数就告警（0 = 不告警）
    'failed_spike' => (int) env('MONITOR_FAILED_SPIKE', 20),

    // 单条队列近 1 小时失败达到这个数，面板上标黄
    'queue_failed_warn' => (int) env('MONITOR_QUEUE_FAILED_WARN', 10),

    // 定时任务到点后多少秒还没开始，算「没按时跑」（调度每分钟一轮，后台命令启动也要几秒）
    'schedule_grace_seconds' => (int) env('MONITOR_SCHEDULE_GRACE', 150),

    // 定时任务连续失败（退出码非 0）几次才告警：汇率接口这类偶发失败不值得半夜叫人
    'schedule_failures_to_alert' => (int) env('MONITOR_SCHEDULE_FAILURES', 3),

    // 同一个问题多久重复提醒一次（分钟）
    'repeat_minutes' => [
        'critical' => 30,
        'warning' => 120,
    ],
];
