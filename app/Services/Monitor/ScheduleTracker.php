<?php

namespace App\Services\Monitor;

use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 每条定时任务的「上次开始 / 结束 / 退出码」，以及它现在算不算正常。
 *
 * 2026-11-23 的停摆就是某条命令卡住、后面全停，却没有任何地方看得到「哪条命令最后一次是什么时候跑的」。
 * Kernel::schedule() 末尾调用 attach()，给每条 artisan 命令挂 before / after 回调：
 * before 在 schedule:run 里命令真正启动时执行（被 withoutOverlapping 的锁挡住、没启动就不会执行），
 * after 在 runInBackground 命令结束后的 schedule:finish 里执行，那时退出码已经写进 $event->exitCode。
 *
 * 判定（status()）：
 *   overdue —— 到点 grace 秒后还没开始（上一次还卡着占着锁、或调度根本没派它）；带时间窗的任务不判断
 *   failed  —— 最近一次退出码非 0
 *   running —— 已开始、还没结束
 *   ok / never（从开始记录以来还没到过点）
 */
final class ScheduleTracker
{
    private const PREFIX = 'monitor:schedule:';
    /** 第一次开始记录的时间：之前没有记录的命令从这一刻起算，刚上线不会全标成「没按时跑」 */
    public const SINCE = 'monitor:schedule:since';

    /** 仪表盘上显示的中文名；没列的显示命令名 */
    public const LABELS = [
        'xboard:statistics' => '每日统计',
        'check:order' => '订单超时取消 / 补开通',
        'check:commission' => '佣金确认与结算',
        'check:ticket' => '工单超时自动关闭',
        'check:auto-increment' => '自增主键余量检查',
        'check:traffic-exceeded' => '超流量用户踢下线',
        'renew:auto' => '余额自动续费',
        'payg:auto-topup' => '按量自动补充',
        'payg:settle-floor' => '按量月度保底结算',
        'plan:restock-notify' => '到货提醒',
        'reset:traffic' => '流量重置',
        'reset:log' => '日志清理',
        'payment:verify' => '付款事后核验',
        'payment:verify-report' => '付款核验日报',
        'payment:link-health' => '支付通道巡检',
        'payment:epay-complaints' => '易支付投诉巡检',
        'payment:refund-watch' => '退款回查封号',
        'payment:purge-banned' => '封号满 180 天删号',
        'payment:alert-digest' => '支付异常日报',
        'commission:refresh-usdt-rate' => 'USDT 汇率刷新',
        'ticket:clean-attachments' => '工单附件清理',
        'billing:prune-documents' => '收据 / 账单归档清理',
        'sanctum:prune-expired' => '过期令牌清理',
        'mcp:prune' => 'AI 助手记录清理',
        'commission:period-sweep' => '周期返佣资格巡检',
        'device:reconcile-online-counts' => '在线设备数回写',
        'send:remindMail' => '到期 / 流量提醒邮件',
        'email-verify:remind' => '邮箱验证提醒',
        'commission:notify-digest' => '佣金入账汇总',
        'mail:relay-results' => '邮件投递结果回写',
        'mail:delivery-digest' => '邮件投递日报',
        'horizon:snapshot' => '队列指标快照',
        'monitor:check' => '面板巡检',
    ];

    public static function attach(Schedule $schedule): void
    {
        foreach ($schedule->events() as $event) {
            $name = self::commandName($event);
            if ($name === null) {
                continue;
            }
            $event->before(static function () use ($name) {
                self::started($name);
            });
            $event->after(static function () use ($event, $name) {
                self::finished($name, (int) ($event->exitCode ?? 0));
            });
        }
    }

    /** `'/usr/bin/php' 'artisan' payment:verify --limit=300` → payment:verify；闭包事件返回 null */
    public static function commandName(Event $event): ?string
    {
        $parsed = self::parseCommand($event);
        return $parsed[0] ?? null;
    }

    /** @return array{0: string, 1: string}|null [命令名, 参数] */
    private static function parseCommand(Event $event): ?array
    {
        $command = (string) ($event->command ?? '');
        if ($command === '' || !preg_match("/artisan['\"]?\\s+(\\S+)\\s*(.*)$/", $command, $m)) {
            return null;
        }
        return [trim($m[1], "'\""), trim($m[2])];
    }

    public static function started(string $name, ?int $now = null): void
    {
        $now ??= time();
        self::update($name, function (array $r) use ($now) {
            $r['started_at'] = $now;
            return $r;
        });
    }

    public static function finished(string $name, int $exitCode, ?int $now = null): void
    {
        $now ??= time();
        self::update($name, function (array $r) use ($now, $exitCode) {
            $r['finished_at'] = $now;
            $r['exit_code'] = $exitCode;
            $r['duration'] = isset($r['started_at']) ? max(0, $now - (int) $r['started_at']) : null;
            if ($exitCode === 0) {
                $r['last_success_at'] = $now;
                $r['failures'] = 0;
            } else {
                $r['failures'] = (int) ($r['failures'] ?? 0) + 1;
            }
            return $r;
        });
    }

    public static function record(string $name): ?array
    {
        $r = Cache::get(self::PREFIX . $name);
        return is_array($r) ? $r : null;
    }

    /**
     * 调度里每条命令的现状（仪表盘和巡检共用）。
     *
     * @return list<array<string, mixed>>
     */
    public static function statuses(Schedule $schedule, int $now): array
    {
        $grace = max(60, (int) config('monitor.schedule_grace_seconds', 150));
        $since = (int) (Cache::get(self::SINCE) ?: $now);
        $rows = [];
        foreach ($schedule->events() as $event) {
            $parsed = self::parseCommand($event);
            if ($parsed === null) {
                continue;
            }
            [$name, $args] = $parsed;
            $rows[] = self::status($event, $name, $args, self::record($name), $since, $now, $grace);
        }
        return $rows;
    }

    /** @param array<string, mixed>|null $record */
    public static function status(Event $event, string $name, string $args, ?array $record, int $since, int $now, int $grace): array
    {
        [$previousDue, $interval] = self::previousDue($event, $now);
        // 已经过了宽限期的那次「该跑」：每分钟的任务看 grace 秒之前那一分钟，每天的任务看今天（或昨天）那个点
        [$judgedDue] = self::previousDue($event, $now - $grace);
        $startedAt = (int) ($record['started_at'] ?? 0);
        $finishedAt = (int) ($record['finished_at'] ?? 0);
        $running = $startedAt > 0 && $startedAt > $finishedAt;
        $exitCode = isset($record['exit_code']) ? (int) $record['exit_code'] : null;

        // 该跑的时间过了 grace 秒还没开始：上一次开始（没有记录就从开始记录那一刻算）早于那次该跑的时间
        $overdue = $judgedDue !== null
            && max($startedAt, $since) < $judgedDue - 30
            && !self::hasTimeWindow($event);

        $status = match (true) {
            $overdue => 'overdue',
            !$running && $exitCode !== null && $exitCode !== 0 => 'failed',
            $running => 'running',
            $record === null => 'never',
            default => 'ok',
        };

        return [
            'command' => $name,
            'args' => $args,
            'label' => self::LABELS[$name] ?? $name,
            'expression' => $event->expression,
            'interval' => $interval,
            'previous_due_at' => $previousDue,
            'started_at' => $startedAt ?: null,
            'finished_at' => $finishedAt ?: null,
            'duration' => $running ? $now - $startedAt : ($record['duration'] ?? null),
            'running' => $running,
            'exit_code' => $exitCode,
            'failures' => (int) ($record['failures'] ?? 0),
            'last_success_at' => isset($record['last_success_at']) ? (int) $record['last_success_at'] : null,
            'status' => $status,
        ];
    }

    /**
     * 按 cron 表达式算「最近一次该跑的时间」和间隔（秒）。
     *
     * @return array{0: ?int, 1: ?int}
     */
    private static function previousDue(Event $event, int $now): array
    {
        try {
            $cron = new CronExpression($event->expression);
            $tz = $event->timezone ?: config('app.timezone', 'UTC');
            $at = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone(is_string($tz) ? $tz : $tz->getName()));
            $previous = $cron->getPreviousRunDate($at, 0, true)->getTimestamp();
            $before = $cron->getPreviousRunDate($at, 1, true)->getTimestamp();
            return [$previous, max(60, $previous - $before)];
        } catch (\Throwable $e) {
            Log::notice('[monitor] 解析定时任务表达式失败', ['expression' => $event->expression, 'error' => $e->getMessage()]);
            return [null, null];
        }
    }

    /**
     * 带 between() / when() 时间窗的任务不判断「没按时跑」：Laravel 的 between() 在定义调度时就把「现在」
     * 冻结进了闭包（ManagesFrequencies::inTimeInterval），常驻的网页进程里拿它判断窗口会一直用旧时间。
     * 现在带时间窗的只有支付通道巡检和两份日报，日报自己会补发。
     * 只看 filters：withoutOverlapping 的「锁还在就跳过」挂在 rejects 上，那正是要抓的卡死情形。
     */
    private static function hasTimeWindow(Event $event): bool
    {
        try {
            return (fn () => $this->filters)->call($event) !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    private static function update(string $name, callable $change): void
    {
        try {
            $record = self::record($name) ?? [];
            Cache::forever(self::PREFIX . $name, $change($record));
        } catch (\Throwable $e) {
            // 记录失败不能影响命令本身
            try {
                Log::notice('[monitor] 记录定时任务状态失败', ['command' => $name, 'error' => $e->getMessage()]);
            } catch (\Throwable) {
            }
        }
    }
}
