<?php

namespace App\Services\Monitor;

use App\Services\Mail\MailRelay;
use App\Services\TelegramService;
use App\Support\AdminAlert;
use App\Support\SchedulerHeartbeat;
use App\Support\SchedulerWatchdog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 面板巡检：把队列、定时任务、调度进程、邮件投递服务、告警通道的现状汇成一份报告，
 * 列出问题，发告警（直发 Telegram，不经过队列），推外部心跳。
 *
 * 仪表盘「队列与定时任务」面板读 report()；monitor:check 每分钟调 check()。
 */
final class SystemMonitor
{
    public const LAST_CHECK = 'monitor:last_check_at';
    private const OPEN_ISSUES = 'monitor:open_issues';
    private const HEARTBEAT_PUSH = 'monitor:heartbeat_push';

    /** 每分钟一次：投金丝雀、出报告、发告警、推外部心跳 */
    public static function check(?int $now = null): array
    {
        $now ??= time();
        Cache::add(ScheduleTracker::SINCE, $now, 86400 * 365);
        Cache::forever(self::LAST_CHECK, $now);
        QueueHealth::dispatchCanaries($now);

        $report = self::report($now);
        self::notify($report['issues'], $now);
        self::pushHeartbeat($report['issues'], $now);
        return $report;
    }

    /** 仪表盘读的完整报告 */
    public static function report(?int $now = null): array
    {
        $now ??= time();
        $queues = QueueHealth::collect($now);
        foreach ($queues['queues'] as &$queue) {
            $queue['status'] = QueueHealth::queueStatus($queue, $queues['horizon']['status'], $now);
        }
        unset($queue);

        $report = [
            'generated_at' => $now,
            'horizon' => $queues['horizon'],
            'queues' => $queues['queues'],
            'failed' => $queues['failed'],
            'scheduler' => self::scheduler($now),
            'schedule' => self::schedule($now),
            'mail_relay' => self::mailRelay(),
            'alerting' => self::alerting(),
        ];
        $report['issues'] = self::withSince(self::issues($report, $now), $now);
        $report['overall'] = self::overall($report['issues']);
        return $report;
    }

    /**
     * 从报告里挑出问题。level：critical（业务在停）、warning（变慢 / 部分失败）、info（建议）。
     * notify=false 的只在仪表盘显示：调度停摆由 SchedulerWatchdog 告警，这里不重复。
     * notify_after：问题持续这么多秒才告警，避免一次抖动半夜叫人。
     *
     * @return list<array{key: string, level: string, title: string, detail: string, notify: bool, notify_after: int}>
     */
    public static function issues(array $report, int $now): array
    {
        $issues = [];
        $add = function (string $key, string $level, string $title, string $detail = '', bool $notify = true, int $after = 0) use (&$issues) {
            $issues[] = ['key' => $key, 'level' => $level, 'title' => $title, 'detail' => $detail, 'notify' => $notify, 'notify_after' => $after];
        };

        $horizon = $report['horizon']['status'];
        if ($horizon === 'stopped') {
            $add('horizon:stopped', 'critical', '队列进程 Horizon 没在运行',
                '所有队列任务都不会被处理：流量入账、订单取消 / 补开通、节点同步、Telegram 和邮件。请检查 horizon 容器（docker compose ps / logs horizon）。');
        } elseif ($horizon === 'paused') {
            $add('horizon:paused', 'critical', '队列进程 Horizon 被暂停了', '有人执行了 horizon:pause，恢复用 php artisan horizon:continue。');
        } elseif ($horizon === 'unknown') {
            $add('horizon:unknown', 'critical', '读不到 Horizon 状态', '多半是 Redis 连不上。');
        }

        foreach ($report['queues'] as $q) {
            $label = "「{$q['label']}」队列（{$q['name']}）";
            $pendingSince = $q['canary']['pending_since'] ?? null;
            $stuckFor = $pendingSince !== null ? $now - $pendingSince : 0;
            if ($horizon === 'running' && $pendingSince !== null && $stuckFor > $q['stall_after']) {
                $add("queue:stalled:{$q['name']}", 'critical', "{$label}" . self::duration($stuckFor) . '没有处理任务',
                    '探测任务已排队 ' . self::duration($stuckFor) . "；等待中 " . self::count($q['length']) . " 个，进程 {$q['processes']} 个。worker 可能卡在某个任务上，或者被积压堵住。");
            } elseif ($horizon === 'running' && (int) $q['processes'] === 0) {
                $add("queue:no_workers:{$q['name']}", 'critical', "{$label}没有工作进程",
                    'Horizon 在跑，但没有进程守这条队列：多半是主机上挂载的 config/horizon.php 和代码不一致。');
            } elseif ($q['wait'] !== null && $q['wait'] > $q['wait_threshold']) {
                $add("queue:slow:{$q['name']}", 'warning', "{$label}积压：预计等待 " . self::duration($q['wait']),
                    "等待中 " . self::count($q['length']) . " 个，阈值 " . self::duration($q['wait_threshold']) . "，进程 {$q['processes']} 个。", true, 300);
            }
        }

        $spike = (int) config('monitor.failed_spike', 20);
        if ($spike > 0 && $report['failed']['last_10m'] >= $spike) {
            $add('failed:spike', 'warning', "10 分钟内 {$report['failed']['last_10m']} 个任务失败",
                '后台「系统日志 → 失败任务」看是哪类任务、什么报错。');
        }

        $scheduler = $report['scheduler'];
        if ($scheduler['status'] === 'stopped') {
            $add('scheduler:stopped', 'critical', '定时任务停了',
                '心跳 ' . ($scheduler['heartbeat_age'] === null ? '从未上报' : self::duration($scheduler['heartbeat_age']) . '前') . '。退款回查封号、付款核验、流量重置、佣金结算、每日邮件都不会跑。', false);
        } elseif ($scheduler['status'] === 'fallback') {
            $add('scheduler:fallback', 'warning', '专用调度进程没在跑，网页进程正在代跑定时任务',
                '业务暂时不受影响，但网页进程重启或卡住时定时任务就会停。请检查 scheduler 容器（docker compose ps scheduler）。', false);
        }

        $failuresToAlert = max(1, (int) config('monitor.schedule_failures_to_alert', 3));
        foreach ($report['schedule'] as $task) {
            $label = "定时任务「{$task['label']}」（{$task['command']}）";
            if ($task['status'] === 'overdue' && $scheduler['status'] !== 'stopped') {
                $detail = $task['running']
                    ? '上一次 ' . self::duration($now - (int) $task['started_at']) . '前开始、到现在还没结束，可能卡住了，占着锁让后面的都跑不了。'
                    : '到点后没有启动' . ($task['started_at'] ? '，上一次开始于 ' . self::duration($now - (int) $task['started_at']) . '前' : '') . '。';
                $add("schedule:overdue:{$task['command']}", 'critical', "{$label}没按时跑", $detail);
            } elseif ($task['status'] === 'failed' && $task['failures'] >= $failuresToAlert) {
                $add("schedule:failed:{$task['command']}", 'warning', "{$label}连续 {$task['failures']} 次失败",
                    "最近一次退出码 {$task['exit_code']}，详情看 storage/logs。");
            }
        }

        if ($report['mail_relay']['enabled'] !== [] && !$report['mail_relay']['online']) {
            $add('mail_relay:offline', 'warning', 'Rust 邮件投递服务离线，邮件已自动改由面板直发',
                '开了投递服务的通道：' . implode('、', $report['mail_relay']['enabled']) . '。邮件照常发，但速度和服务商限额保护都没了。请检查 mail-relay 容器。', true, 180);
        }

        $alerting = $report['alerting'];
        if (!$alerting['telegram']['bot_configured'] || $alerting['telegram']['recipients'] === 0) {
            $add('alerting:telegram', 'warning', '告警发不出去：' . (!$alerting['telegram']['bot_configured'] ? '没有配置 Telegram Bot' : '没有管理员绑定 Telegram'),
                '队列、定时任务出问题时没有任何通知。', false);
        }
        if (!$alerting['heartbeat_configured']) {
            $add('alerting:heartbeat', 'info', '建议配置外部心跳（MONITOR_HEARTBEAT_URL）',
                '整台机器、Redis 或面板全挂时巡检本身跑不起来，只有外部监控（如 Uptime Kuma Push）能发现。', false);
        }

        return $issues;
    }

    /** @param list<array> $issues */
    public static function overall(array $issues): string
    {
        $levels = array_column($issues, 'level');
        return in_array('critical', $levels, true) ? 'critical' : (in_array('warning', $levels, true) ? 'warning' : 'ok');
    }

    /**
     * 新出现的问题发一条汇总告警；持续的按 repeat_minutes 重复提醒；告警过的问题消失后发恢复通知。
     *
     * @param list<array> $issues
     */
    public static function notify(array $issues, int $now): void
    {
        try {
            $open = Cache::get(self::OPEN_ISSUES, []);
            $open = is_array($open) ? $open : [];
            $next = [];
            $send = [];
            foreach ($issues as $issue) {
                $prev = $open[$issue['key']] ?? null;
                $entry = [
                    'level' => $issue['level'],
                    'title' => $issue['title'],
                    'since' => (int) ($prev['since'] ?? $now),
                    'alerted_at' => $prev['alerted_at'] ?? null,
                ];
                if ($issue['notify'] && $now - $entry['since'] >= $issue['notify_after']) {
                    $repeat = 60 * max(1, (int) config("monitor.repeat_minutes.{$issue['level']}", 60));
                    if ($entry['alerted_at'] === null || $now - (int) $entry['alerted_at'] >= $repeat) {
                        $send[] = $issue;
                        $entry['alerted_at'] = $now;
                    }
                }
                $next[$issue['key']] = $entry;
            }
            $recovered = [];
            foreach ($open as $key => $entry) {
                if (!isset($next[$key]) && ($entry['alerted_at'] ?? null) !== null) {
                    $recovered[] = $entry['title'] . '（持续 ' . self::duration($now - (int) $entry['since']) . '）';
                }
            }
            Cache::forever(self::OPEN_ISSUES, $next);

            if ($send !== []) {
                AdminAlert::urgent(self::alertText($send), '', true);
            }
            if ($recovered !== []) {
                AdminAlert::urgent("✅ 面板巡检：已恢复\n" . implode("\n", array_map(fn ($t) => '· ' . $t, $recovered)), '');
            }
        } catch (\Throwable $e) {
            Log::warning('[monitor] 发告警失败', ['error' => $e->getMessage()]);
        }
    }

    /** @param list<array> $issues */
    public static function alertText(array $issues): string
    {
        $critical = count(array_filter($issues, fn ($i) => $i['level'] === 'critical'));
        $other = count($issues) - $critical;
        $head = $critical > 0 ? '🚨 面板巡检：' : '⚠️ 面板巡检：';
        $parts = array_filter([$critical ? "{$critical} 个严重问题" : '', $other ? "{$other} 个提醒" : '']);
        $lines = [$head . implode('、', $parts), ''];
        foreach ($issues as $issue) {
            $lines[] = ($issue['level'] === 'critical' ? '🔴 ' : '🟡 ') . $issue['title'];
            if ($issue['detail'] !== '') {
                $lines[] = '   ' . $issue['detail'];
            }
        }
        $lines[] = '';
        $lines[] = '详情见后台仪表盘「队列与定时任务」。同一问题严重的 30 分钟、提醒 2 小时重复一次，恢复后会通知。';
        return implode("\n", $lines);
    }

    /** @param list<array> $issues */
    private static function withSince(array $issues, int $now): array
    {
        try {
            $open = Cache::get(self::OPEN_ISSUES, []);
        } catch (\Throwable) {
            $open = [];
        }
        foreach ($issues as &$issue) {
            $issue['since'] = (int) ($open[$issue['key']]['since'] ?? $now);
            unset($issue['notify_after']);
        }
        unset($issue);
        return $issues;
    }

    private static function scheduler(int $now): array
    {
        $age = SchedulerHeartbeat::age($now);
        $fallbackAt = Cache::get(SchedulerWatchdog::FALLBACK_AT);
        $fallbackAt = $fallbackAt !== null ? (int) $fallbackAt : null;
        $staleAfter = max(60, (int) config('scheduler.stale_after', 150));
        $alertAfter = max($staleAfter, (int) config('scheduler.alert_after', 300));
        $lastCheck = Cache::get(self::LAST_CHECK);

        $status = match (true) {
            $age === null || $age > $alertAfter => 'stopped',
            // 网页进程 3 分钟内代跑过：专用调度进程没在跑（或刚恢复）
            $fallbackAt !== null && $now - $fallbackAt < 180 => 'fallback',
            $age > $staleAfter => 'stale',
            default => 'ok',
        };

        return [
            'status' => $status,
            'heartbeat_age' => $age,
            'fallback_at' => $fallbackAt,
            'tick_mode' => (string) config('scheduler.octane_tick', 'auto'),
            'monitor_age' => $lastCheck !== null ? max(0, $now - (int) $lastCheck) : null,
        ];
    }

    private static function schedule(int $now): array
    {
        try {
            $rows = ScheduleTracker::statuses(app(Schedule::class), $now);
        } catch (\Throwable $e) {
            Log::notice('[monitor] 读定时任务状态失败', ['error' => $e->getMessage()]);
            return [];
        }
        $order = ['overdue' => 0, 'failed' => 1, 'running' => 2, 'never' => 3, 'ok' => 4];
        usort($rows, fn ($a, $b) => [$order[$a['status']], (int) $a['interval'], $a['command']] <=> [$order[$b['status']], (int) $b['interval'], $b['command']]);
        return $rows;
    }

    private static function mailRelay(): array
    {
        $enabled = MailRelay::lanes();
        return ['enabled' => $enabled, 'online' => $enabled === [] ? false : MailRelay::alive()];
    }

    private static function alerting(): array
    {
        $botConfigured = false;
        $recipients = 0;
        try {
            $telegram = new TelegramService();
            $botConfigured = $telegram->hasBotToken();
            $recipients = count($telegram->getAdminChatIds());
        } catch (\Throwable) {
        }
        $push = null;
        try {
            $push = Cache::get(self::HEARTBEAT_PUSH);
        } catch (\Throwable) {
        }
        return [
            'telegram' => [
                'bot_configured' => $botConfigured,
                'recipients' => $recipients,
                'last' => AdminAlert::lastUrgent(),
            ],
            'heartbeat_configured' => (string) config('monitor.heartbeat_url', '') !== '',
            'heartbeat_last' => is_array($push) ? $push : null,
            'scheduler_heartbeat_configured' => (string) config('scheduler.heartbeat_url', '') !== '',
        ];
    }

    /**
     * 外部心跳：Uptime Kuma Push（status=up / down + msg）或 healthchecks.io（有问题时请求 /fail）。
     *
     * @param list<array> $issues
     */
    public static function pushHeartbeat(array $issues, int $now): void
    {
        $url = trim((string) config('monitor.heartbeat_url', ''));
        if ($url === '') {
            return;
        }
        $critical = array_values(array_filter($issues, fn ($i) => $i['level'] === 'critical'));
        $target = self::heartbeatTarget($url, $critical);
        $result = ['at' => $now, 'status' => $critical === [] ? 'up' : 'down', 'ok' => false, 'error' => null];
        try {
            $response = Http::timeout(5)->connectTimeout(3)->get($target);
            $result['ok'] = $response->successful();
            if (!$result['ok']) {
                $result['error'] = 'HTTP ' . $response->status();
            }
        } catch (\Throwable $e) {
            $result['error'] = mb_substr($e->getMessage(), 0, 200);
        }
        if (!$result['ok']) {
            Log::notice('[monitor] 外部心跳上报失败', ['error' => $result['error']]);
        }
        try {
            Cache::forever(self::HEARTBEAT_PUSH, $result);
        } catch (\Throwable) {
        }
    }

    /** @param list<array> $critical */
    public static function heartbeatTarget(string $url, array $critical): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (str_contains($host, 'hc-ping')) {
            return $critical === [] ? $url : rtrim($url, '/') . '/fail';
        }
        $parts = parse_url($url);
        parse_str((string) ($parts['query'] ?? ''), $query);
        $query['status'] = $critical === [] ? 'up' : 'down';
        $query['msg'] = $critical === [] ? 'OK' : mb_substr(implode('; ', array_column($critical, 'title')), 0, 200);
        $base = strtok($url, '?');
        return $base . '?' . http_build_query($query);
    }

    private static function duration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 120) {
            return "{$seconds} 秒";
        }
        if ($seconds < 7200) {
            return intdiv($seconds, 60) . ' 分钟';
        }
        if ($seconds < 172800) {
            return round($seconds / 3600, 1) . ' 小时';
        }
        return round($seconds / 86400, 1) . ' 天';
    }

    private static function count(?int $n): string
    {
        return $n === null ? '未知' : number_format($n);
    }
}
