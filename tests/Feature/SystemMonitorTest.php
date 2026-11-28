<?php

namespace Tests\Feature;

use App\Jobs\QueueCanaryJob;
use App\Jobs\SendTelegramJob;
use App\Models\User;
use App\Services\Monitor\QueueHealth;
use App\Services\Monitor\ScheduleTracker;
use App\Services\Monitor\SystemMonitor;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 面板巡检（monitor:check）与仪表盘「队列与定时任务」面板。CI 没有 Redis：Horizon 的读数用构造好的报告测，
 * 金丝雀、定时任务记录、告警节流都在 array 缓存上跑。
 */
class SystemMonitorTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = 1_800_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Bus::fake([QueueCanaryJob::class, SendTelegramJob::class]);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => []])]);
        config(['v2board.telegram_bot_token' => '1:test', 'monitor.heartbeat_url' => null]);
        User::create([
            'email' => 'admin@example.test', 'password' => 'x', 'uuid' => (string) Str::uuid(),
            'token' => Str::random(32), 'is_admin' => 1, 'telegram_id' => 10001,
        ]);
    }

    /** @return list<string> */
    private function telegramTexts(): array
    {
        $texts = [];
        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), '/sendMessage')) {
                $texts[] = (string) ($request->data()['text'] ?? '');
            }
        }
        return $texts;
    }

    private function queue(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'traffic_fetch', 'label' => '节点流量入账', 'length' => 0, 'wait' => 0, 'wait_threshold' => 60,
            'processes' => 8, 'canary' => ['ran_at' => self::NOW - 30, 'latency_ms' => 12, 'pending_since' => null],
            'stall_after' => 120, 'throughput' => ['per_minute' => 400.0, 'runtime_ms' => 35.0], 'failed_1h' => 0,
        ];
    }

    private function report(array $overrides = []): array
    {
        return array_replace_recursive([
            'horizon' => ['status' => 'running', 'masters' => 1, 'supervisors' => [], 'processes' => 37, 'jobs_per_minute' => 900],
            'queues' => [$this->queue()],
            'failed' => ['last_10m' => 0, 'last_1h' => 0, 'truncated' => false],
            'scheduler' => ['status' => 'ok', 'heartbeat_age' => 20, 'fallback_at' => null, 'tick_mode' => 'auto', 'monitor_age' => 30],
            'schedule' => [],
            'mail_relay' => ['enabled' => [], 'online' => false],
            'alerting' => [
                'telegram' => ['bot_configured' => true, 'recipients' => 1, 'last' => null],
                'heartbeat_configured' => true, 'heartbeat_last' => null, 'scheduler_heartbeat_configured' => true,
            ],
        ], $overrides);
    }

    private function keys(array $issues): array
    {
        return array_column($issues, 'key');
    }

    // ---- 金丝雀 ----

    public function test_canaries_go_to_every_horizon_queue_except_the_legacy_ones(): void
    {
        QueueHealth::dispatchCanaries(self::NOW);

        $queues = [];
        Bus::assertDispatched(QueueCanaryJob::class, function (QueueCanaryJob $job) use (&$queues) {
            $queues[] = $job->queue;
            return true;
        });
        sort($queues);
        $expected = array_values(array_diff(QueueHealth::queues(), ['send_email', 'send_email_mass']));
        sort($expected);
        $this->assertSame($expected, $queues);
        $this->assertContains('traffic_fetch', $queues);
        $this->assertContains('mail_instant', $queues);
        $this->assertNotContains('send_email', $queues);
    }

    public function test_a_queue_gets_no_new_canary_while_the_last_one_is_still_waiting(): void
    {
        QueueHealth::dispatchCanaries(self::NOW);
        QueueHealth::dispatchCanaries(self::NOW + 60);
        QueueHealth::dispatchCanaries(self::NOW + 120);
        $perQueue = count(QueueHealth::canaryQueues());
        Bus::assertDispatchedTimes(QueueCanaryJob::class, $perQueue);

        // 「排了多久」从第一个没被执行的算起
        $this->assertSame(self::NOW, QueueHealth::canary('traffic_fetch')['pending_since']);

        // 10 分钟都没执行，可能是 Redis 重启弄丢了：补投一个，起点不变
        QueueHealth::dispatchCanaries(self::NOW + 700);
        Bus::assertDispatchedTimes(QueueCanaryJob::class, $perQueue * 2);
        $this->assertSame(self::NOW, QueueHealth::canary('traffic_fetch')['pending_since']);
    }

    public function test_a_canary_that_runs_records_its_latency_and_clears_the_pending_mark(): void
    {
        QueueHealth::dispatchCanaries(self::NOW);
        QueueHealth::canaryRan('stat', self::NOW + 0.25, self::NOW + 1.5);

        $canary = QueueHealth::canary('stat');
        $this->assertNull($canary['pending_since']);
        $this->assertSame(self::NOW + 1, $canary['ran_at']);
        $this->assertSame(1250, $canary['latency_ms']);

        // 执行完下一分钟会再投
        Bus::fake([QueueCanaryJob::class]);
        QueueHealth::dispatchCanaries(self::NOW + 60);
        Bus::assertDispatched(QueueCanaryJob::class, fn (QueueCanaryJob $job) => $job->queue === 'stat');
    }

    public function test_canary_job_lands_on_its_own_queue(): void
    {
        $job = new QueueCanaryJob('order_handle', microtime(true));
        $this->assertSame('order_handle', $job->queue);
        $job->handle();
        $this->assertNotNull(QueueHealth::canary('order_handle')['ran_at']);
    }

    public function test_stall_threshold_follows_the_queue_wait_threshold(): void
    {
        $this->assertSame(120, QueueHealth::stallAfter('order_handle'), 'order_handle 等待阈值 30 秒：取下限 120');
        $this->assertSame(960, QueueHealth::stallAfter('mail_bulk'), '批量邮件本来就要发十几分钟：900 + 60');
    }

    // ---- 单条队列状态 ----

    public function test_queue_status(): void
    {
        $now = self::NOW;
        $this->assertSame('ok', QueueHealth::queueStatus($this->queue(), 'running', $now));
        $this->assertSame('critical', QueueHealth::queueStatus($this->queue(['canary' => ['ran_at' => null, 'latency_ms' => null, 'pending_since' => $now - 300]]), 'running', $now));
        $this->assertSame('critical', QueueHealth::queueStatus($this->queue(['processes' => 0]), 'running', $now));
        $this->assertSame('warning', QueueHealth::queueStatus($this->queue(['wait' => 90]), 'running', $now));
        $this->assertSame('warning', QueueHealth::queueStatus($this->queue(['failed_1h' => 12]), 'running', $now));
        $this->assertSame('ok', QueueHealth::queueStatus($this->queue(['canary' => ['ran_at' => null, 'latency_ms' => null, 'pending_since' => $now - 60]]), 'running', $now), '排了 60 秒还在阈值内');
    }

    // ---- 问题判定 ----

    public function test_healthy_report_has_no_issues(): void
    {
        $issues = SystemMonitor::issues($this->report(), self::NOW);
        $this->assertSame([], $issues);
        $this->assertSame('ok', SystemMonitor::overall($issues));
    }

    public function test_stopped_horizon_is_critical_and_does_not_also_blame_every_queue(): void
    {
        $report = $this->report(['horizon' => ['status' => 'stopped', 'masters' => 0, 'processes' => 0]]);
        $report['queues'] = [$this->queue(['processes' => 0, 'canary' => ['ran_at' => null, 'latency_ms' => null, 'pending_since' => self::NOW - 900]])];
        $issues = SystemMonitor::issues($report, self::NOW);
        $this->assertSame(['horizon:stopped'], $this->keys($issues));
        $this->assertSame('critical', SystemMonitor::overall($issues));
    }

    public function test_stalled_queue_and_queue_without_workers(): void
    {
        $report = $this->report();
        $report['queues'] = [
            $this->queue(['canary' => ['ran_at' => self::NOW - 400, 'latency_ms' => 10, 'pending_since' => self::NOW - 300], 'length' => 5400]),
            $this->queue(['name' => 'node_sync', 'label' => '节点用户同步', 'processes' => 0]),
            $this->queue(['name' => 'mail_bulk', 'label' => '批量提醒邮件', 'wait' => 1200, 'wait_threshold' => 900]),
        ];
        $issues = SystemMonitor::issues($report, self::NOW);
        $this->assertSame(['queue:stalled:traffic_fetch', 'queue:no_workers:node_sync', 'queue:slow:mail_bulk'], $this->keys($issues));
        $this->assertStringContainsString('5 分钟没有处理任务', $issues[0]['title']);
        $this->assertStringContainsString('5,400', $issues[0]['detail']);
        $this->assertSame('warning', $issues[2]['level']);
        $this->assertSame(300, $issues[2]['notify_after'], '积压持续 5 分钟才告警');
    }

    public function test_failed_spike(): void
    {
        $issues = SystemMonitor::issues($this->report(['failed' => ['last_10m' => 25, 'last_1h' => 40]]), self::NOW);
        $this->assertSame(['failed:spike'], $this->keys($issues));
        config(['monitor.failed_spike' => 0]);
        $this->assertSame([], SystemMonitor::issues($this->report(['failed' => ['last_10m' => 25]]), self::NOW));
    }

    public function test_scheduler_issues_are_shown_but_left_to_the_watchdog_to_alert(): void
    {
        $issues = SystemMonitor::issues($this->report(['scheduler' => ['status' => 'fallback']]), self::NOW);
        $this->assertSame(['scheduler:fallback'], $this->keys($issues));
        $this->assertFalse($issues[0]['notify'], '看门狗已经发过，不重复');

        $issues = SystemMonitor::issues($this->report(['scheduler' => ['status' => 'stopped', 'heartbeat_age' => 900]]), self::NOW);
        $this->assertSame(['scheduler:stopped'], $this->keys($issues));
        $this->assertFalse($issues[0]['notify']);
    }

    public function test_overdue_and_repeatedly_failing_scheduled_commands(): void
    {
        $task = ['command' => 'payment:verify', 'label' => '付款事后核验', 'running' => false, 'started_at' => self::NOW - 1800, 'failures' => 0, 'exit_code' => 0, 'status' => 'ok'];
        $report = $this->report();
        $report['schedule'] = [
            ['status' => 'overdue', 'running' => true] + $task,
            ['command' => 'commission:refresh-usdt-rate', 'label' => 'USDT 汇率刷新', 'status' => 'failed', 'failures' => 3, 'exit_code' => 1] + $task,
            ['command' => 'reset:log', 'label' => '日志清理', 'status' => 'failed', 'failures' => 1, 'exit_code' => 1] + $task,
        ];
        $issues = SystemMonitor::issues($report, self::NOW);
        $this->assertSame(['schedule:overdue:payment:verify', 'schedule:failed:commission:refresh-usdt-rate'], $this->keys($issues), '只失败一次的不报');
        $this->assertStringContainsString('还没结束，可能卡住了', $issues[0]['detail']);
        $this->assertSame('critical', $issues[0]['level']);
    }

    public function test_mail_relay_offline_and_alerting_gaps(): void
    {
        $issues = SystemMonitor::issues($this->report([
            'mail_relay' => ['enabled' => ['bulk'], 'online' => false],
            'alerting' => ['telegram' => ['recipients' => 0], 'heartbeat_configured' => false],
        ]), self::NOW);
        $this->assertSame(['mail_relay:offline', 'alerting:telegram', 'alerting:heartbeat'], $this->keys($issues));
        $this->assertSame(180, $issues[0]['notify_after']);
        $this->assertSame('info', $issues[2]['level']);
        $this->assertSame('warning', SystemMonitor::overall($issues));
    }

    // ---- 告警 ----

    public function test_alerts_are_sent_directly_once_repeated_later_and_followed_by_a_recovery_notice(): void
    {
        // 用「被暂停」：它没有 notify_after，第一次巡检就报（「没在运行」要持续 2 分钟才报，见下一个测试）
        $issues = SystemMonitor::issues($this->report(['horizon' => ['status' => 'paused']]), self::NOW);

        SystemMonitor::notify($issues, self::NOW);
        SystemMonitor::notify($issues, self::NOW + 60);
        $this->assertCount(1, $this->telegramTexts(), '持续的问题不每分钟都发');
        $this->assertStringContainsString('队列进程 Horizon 被暂停了', $this->telegramTexts()[0]);
        Bus::assertNotDispatched(SendTelegramJob::class);

        SystemMonitor::notify($issues, self::NOW + 1800);
        $this->assertCount(2, $this->telegramTexts(), '严重问题 30 分钟重复一次');

        SystemMonitor::notify([], self::NOW + 1900);
        $texts = $this->telegramTexts();
        $this->assertCount(3, $texts);
        $this->assertStringContainsString('已恢复', $texts[2]);
        $this->assertStringContainsString('持续 31 分钟', $texts[2]);
    }

    public function test_notify_after_waits_for_the_problem_to_persist(): void
    {
        $issues = SystemMonitor::issues($this->report(['mail_relay' => ['enabled' => ['bulk'], 'online' => false]]), self::NOW);
        SystemMonitor::notify($issues, self::NOW);
        SystemMonitor::notify($issues, self::NOW + 120);
        $this->assertSame([], $this->telegramTexts(), '离线不到 3 分钟不报');
        SystemMonitor::notify($issues, self::NOW + 200);
        $this->assertCount(1, $this->telegramTexts());

        // 没报过的问题消失不发恢复
        $slow = SystemMonitor::issues($this->report(['queues' => [$this->queue(['wait' => 90])]]), self::NOW);
        SystemMonitor::notify(array_merge($issues, $slow), self::NOW + 260);
        SystemMonitor::notify($issues, self::NOW + 320);
        $this->assertCount(1, $this->telegramTexts());
    }

    public function test_check_itself_sends_alerts(): void
    {
        // check() 曾把去掉 notify_after 的仪表盘版 issues 交给 notify()，读字段抛错被吞，巡检一条告警都发不出。
        // CI 没有 Redis，Horizon 读数是 unknown（critical，持续 2 分钟才报）
        SystemMonitor::check(self::NOW);
        $this->assertArrayHasKey('horizon:unknown', (array) Cache::get('monitor:open_issues', []), '巡检要把当前问题记进 open_issues');
        SystemMonitor::check(self::NOW + 130);
        $texts = $this->telegramTexts();
        $this->assertNotEmpty($texts, '持续超过 notify_after 的严重问题要发出去');
        $this->assertStringContainsString('Horizon', implode("\n", $texts));
    }

    public function test_horizon_down_waits_two_minutes_so_a_deploy_restart_does_not_alert(): void
    {
        $issues = SystemMonitor::issues($this->report(['horizon' => ['status' => 'stopped']]), self::NOW);
        SystemMonitor::notify($issues, self::NOW);
        SystemMonitor::notify($issues, self::NOW + 60);
        $this->assertSame([], $this->telegramTexts());
        SystemMonitor::notify([], self::NOW + 90);
        SystemMonitor::notify($issues, self::NOW + 100);
        SystemMonitor::notify($issues, self::NOW + 200);
        $this->assertSame([], $this->telegramTexts(), '中间恢复过，重新计时');
        SystemMonitor::notify($issues, self::NOW + 230);
        $this->assertCount(1, $this->telegramTexts());
    }

    public function test_error_messages_lose_their_urls_before_they_are_stored(): void
    {
        $msg = 'cURL error 28: Operation timed out (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://api.telegram.org/bot1:SECRET/sendMessage';
        $clean = \App\Support\AdminAlert::redactUrls($msg);
        $this->assertStringNotContainsString('SECRET', $clean);
        $this->assertStringContainsString('cURL error 28', $clean);

        config(['monitor.heartbeat_url' => 'https://kuma.example.test/api/push/PushTok3n']);
        // 换一个干净的 Http 工厂，免得 setUp 里的 '*' 假响应先匹配上
        Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
        Http::fake(fn () => throw new \RuntimeException('cURL error 7: Failed to connect for https://kuma.example.test/api/push/PushTok3n?status=up'));
        SystemMonitor::pushHeartbeat([], self::NOW);
        $last = SystemMonitor::report(self::NOW)['alerting']['heartbeat_last'];
        $this->assertFalse($last['ok']);
        $this->assertStringNotContainsString('PushTok3n', (string) $last['error']);
    }

    public function test_dashboard_only_issues_never_alert(): void
    {
        $issues = SystemMonitor::issues($this->report(['scheduler' => ['status' => 'fallback'], 'alerting' => ['heartbeat_configured' => false]]), self::NOW);
        SystemMonitor::notify($issues, self::NOW);
        SystemMonitor::notify($issues, self::NOW + 86400);
        $this->assertSame([], $this->telegramTexts());
    }

    // ---- 外部心跳 ----

    public function test_heartbeat_target_for_uptime_kuma_and_healthchecks(): void
    {
        $kuma = 'https://kuma.example.test/api/push/AbC123?status=up&msg=OK&ping=';
        $this->assertSame('https://kuma.example.test/api/push/AbC123?status=up&msg=OK&ping=', SystemMonitor::heartbeatTarget($kuma, []));
        $down = SystemMonitor::heartbeatTarget($kuma, [['title' => 'Horizon 没在运行']]);
        $this->assertStringStartsWith('https://kuma.example.test/api/push/AbC123?status=down&msg=', $down);
        $this->assertStringContainsString(rawurlencode('Horizon'), $down);

        $hc = 'https://hc-ping.com/1111-2222';
        $this->assertSame($hc, SystemMonitor::heartbeatTarget($hc, []));
        $this->assertSame($hc . '/fail', SystemMonitor::heartbeatTarget($hc, [['title' => 'x']]));
    }

    public function test_heartbeat_is_pushed_and_its_result_recorded(): void
    {
        config(['monitor.heartbeat_url' => 'https://kuma.example.test/api/push/AbC123']);
        SystemMonitor::pushHeartbeat([], self::NOW);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://kuma.example.test/api/push/AbC123?status=up'));
        $this->assertTrue(SystemMonitor::report(self::NOW)['alerting']['heartbeat_last']['ok']);
    }

    // ---- 定时任务记录 ----

    private function event(string $command): \Illuminate\Console\Scheduling\Event
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => ScheduleTracker::commandName($e) === $command);
        $this->assertNotNull($event, "调度里应该有 {$command}");
        return $event;
    }

    public function test_every_scheduled_command_records_start_finish_and_exit_code(): void
    {
        $event = $this->event('payment:verify');
        $event->callBeforeCallbacks($this->app);
        $record = ScheduleTracker::record('payment:verify');
        $this->assertNotNull($record['started_at']);
        $this->assertArrayNotHasKey('finished_at', $record);

        // runInBackground 的命令结束后由 schedule:finish 调 finish(退出码)
        $event->finish($this->app, 1);
        $event->finish($this->app, 1);
        $record = ScheduleTracker::record('payment:verify');
        $this->assertSame(1, $record['exit_code']);
        $this->assertSame(2, $record['failures']);

        $event->finish($this->app, 0);
        $record = ScheduleTracker::record('payment:verify');
        $this->assertSame(0, $record['failures']);
        $this->assertNotNull($record['last_success_at']);
    }

    public function test_command_name_and_args_are_parsed_from_the_artisan_line(): void
    {
        $rows = collect(ScheduleTracker::statuses(app(Schedule::class), time()))->keyBy('command');
        $this->assertSame('--limit=300 --max-seconds=240', $rows['payment:verify']['args']);
        $this->assertSame('付款事后核验', $rows['payment:verify']['label']);
        $this->assertTrue($rows->has('monitor:check'), '巡检自己也在表里');
        $this->assertFalse($rows->has(''), '心跳闭包不算命令');
    }

    public function test_overdue_when_a_due_command_did_not_start(): void
    {
        $event = $this->event('check:order'); // 每分钟
        $now = mktime(12, 10, 30, 6, 1, 2026);
        $grace = 150;

        $this->assertSame('never', ScheduleTracker::status($event, 'check:order', '', null, $now - 10, $now, $grace)['status'], '刚开始记录，还不算');
        $this->assertSame('ok', ScheduleTracker::status($event, 'check:order', '', ['started_at' => $now - 30, 'finished_at' => $now - 29, 'exit_code' => 0], $now - 3600, $now, $grace)['status']);

        // 上一次 10 分钟前开始、一直没结束：每分钟都该跑，被锁挡住了
        $stuck = ScheduleTracker::status($event, 'check:order', '', ['started_at' => $now - 600, 'finished_at' => $now - 660], $now - 3600, $now, $grace);
        $this->assertSame('overdue', $stuck['status']);
        $this->assertTrue($stuck['running']);
        $this->assertSame(600, $stuck['duration']);

        $failed = ScheduleTracker::status($event, 'check:order', '', ['started_at' => $now - 30, 'finished_at' => $now - 25, 'exit_code' => 1, 'failures' => 2], $now - 3600, $now, $grace);
        $this->assertSame('failed', $failed['status']);
    }

    public function test_daily_command_is_judged_against_its_own_time(): void
    {
        $event = $this->event('payment:purge-banned'); // 每天 4:30
        $tz = new \DateTimeZone(config('app.timezone'));
        $at = fn (string $time) => (new \DateTimeImmutable("2026-06-01 {$time}", $tz))->getTimestamp();
        $yesterday = (new \DateTimeImmutable('2026-05-31 04:30:05', $tz))->getTimestamp();
        $since = $at('00:00:00') - 86400 * 3;

        $this->assertSame('ok', ScheduleTracker::status($event, 'payment:purge-banned', '', ['started_at' => $yesterday, 'finished_at' => $yesterday + 5, 'exit_code' => 0], $since, $at('04:31:00'), 150)['status'], '刚到点，还在宽限内');
        $this->assertSame('overdue', ScheduleTracker::status($event, 'payment:purge-banned', '', ['started_at' => $yesterday, 'finished_at' => $yesterday + 5, 'exit_code' => 0], $since, $at('05:00:00'), 150)['status']);
        $this->assertSame(86400, ScheduleTracker::status($event, 'payment:purge-banned', '', null, $since, $at('05:00:00'), 150)['interval']);
    }

    public function test_time_window_commands_are_not_judged_for_overdue(): void
    {
        // between() 在定义调度时冻结了「现在」，常驻进程里判断不准：带时间窗的只看成功 / 失败
        $event = $this->event('payment:link-health'); // 每 15 分钟，9:00–23:59
        $now = mktime(3, 10, 0, 6, 1, 2026);
        $status = ScheduleTracker::status($event, 'payment:link-health', '', ['started_at' => $now - 3 * 3600, 'finished_at' => $now - 3 * 3600 + 5, 'exit_code' => 0], $now - 86400, $now, 150);
        $this->assertSame('ok', $status['status']);
        $failed = ScheduleTracker::status($event, 'payment:link-health', '', ['started_at' => $now - 60, 'finished_at' => $now - 55, 'exit_code' => 1, 'failures' => 1], $now - 86400, $now, 150);
        $this->assertSame('failed', $failed['status']);
    }

    // ---- 接口 ----

    public function test_admin_endpoint_returns_the_report_without_redis(): void
    {
        $path = '/api/v2/' . admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))) . '/system/getMonitorReport';
        $this->getJson($path)->assertStatus(403);

        Sanctum::actingAs(User::create(['email' => Str::random(8) . '@example.test', 'password' => 'x', 'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'is_admin' => 1]), ['admin']);
        $response = $this->getJson($path)->assertOk();
        $data = $response->json('data');
        foreach (['generated_at', 'overall', 'issues', 'horizon', 'queues', 'failed', 'scheduler', 'schedule', 'mail_relay', 'alerting'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }
        $this->assertSame(QueueHealth::queues(), array_column($data['queues'], 'name'));
        $this->assertNotEmpty($data['schedule']);
        $this->assertTrue($data['alerting']['telegram']['bot_configured']);
        $this->assertArrayNotHasKey('notify_after', $data['issues'][0] ?? []);
    }

    public function test_monitor_check_command_runs_and_marks_itself(): void
    {
        $this->artisan('monitor:check')->assertExitCode(0);
        $this->assertEqualsWithDelta(time(), (int) Cache::get(SystemMonitor::LAST_CHECK), 2);
        Bus::assertDispatched(QueueCanaryJob::class);
    }
}
