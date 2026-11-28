<?php

namespace Tests\Feature;

use App\Jobs\SendEmailJob;
use App\Jobs\SendTelegramJob;
use App\Models\User;
use App\Support\SchedulerHeartbeat;
use App\Support\SchedulerWatchdog;
use App\Utils\CacheKey;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 定时任务心跳与看门狗（2026-11-23 调度整块停摆近三天无人发现之后加的）。
 */
class SchedulerWatchdogTest extends TestCase
{
    use RefreshDatabase;

    private int $spawned = 0;
    private bool $telegramUp = true;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Bus::fake([SendTelegramJob::class, SendEmailJob::class]);
        // 看门狗的告警直发 Telegram（不经过队列）：拦下 Bot API 请求来读告警正文
        Http::fake(fn () => $this->telegramUp
            ? Http::response(['ok' => true, 'result' => []])
            : Http::response(['ok' => false, 'description' => 'Bad Gateway'], 502));
        config(['scheduler.stale_after' => 150, 'scheduler.alert_after' => 300, 'scheduler.heartbeat_url' => null, 'v2board.telegram_bot_token' => '1:test']);
        SchedulerWatchdog::useSpawner(function () {
            $this->spawned++;
        });
        User::create([
            'email' => 'admin@example.test', 'password' => 'x', 'uuid' => (string) Str::uuid(),
            'token' => Str::random(32), 'is_admin' => 1, 'telegram_id' => 10001,
        ]);
    }

    protected function tearDown(): void
    {
        SchedulerWatchdog::useSpawner(null);
        parent::tearDown();
    }

    /** @return list<string> 直发的和（直发失败）退回队列的告警正文 */
    private function alerts(): array
    {
        $texts = [];
        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), '/sendMessage')) {
                $texts[] = (string) ($request->data()['text'] ?? '');
            }
        }
        try {
            Bus::assertDispatched(SendTelegramJob::class, function (SendTelegramJob $job) use (&$texts) {
                $texts[] = (fn () => $this->text)->call($job);
                return true;
            });
        } catch (\Throwable) {
        }
        return $texts;
    }

    private function alertsContaining(string $needle): array
    {
        return array_values(array_filter($this->alerts(), fn ($t) => str_contains($t, $needle)));
    }

    public function test_schedule_registers_a_minutely_heartbeat_that_updates_the_admin_status_key(): void
    {
        $events = collect(app(Schedule::class)->events());
        $heartbeat = $events->first(fn ($e) => $e->description === 'scheduler:heartbeat');
        $this->assertNotNull($heartbeat, '调度里要有心跳事件');
        $this->assertSame('* * * * *', $heartbeat->expression);

        $this->assertNull(Cache::get(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null)), '只启动 artisan、不跑调度时不该刷新「最后检查时间」');
        $heartbeat->run($this->app);
        $this->assertEqualsWithDelta(time(), (int) Cache::get(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null)), 2);
        $this->assertEqualsWithDelta(time(), SchedulerHeartbeat::lastAt(), 2);
    }

    public function test_every_command_runs_in_background_so_one_slow_task_cannot_block_the_rest(): void
    {
        $commands = collect(app(Schedule::class)->events())->filter(fn ($e) => $e->command !== null);
        $this->assertNotEmpty($commands);
        foreach ($commands as $event) {
            $this->assertTrue($event->runInBackground, $event->command . ' 应在后台运行');
        }
    }

    public function test_fresh_heartbeat_does_nothing(): void
    {
        SchedulerHeartbeat::beat(time() - 30);
        $this->assertSame('ok', SchedulerWatchdog::tick('auto'));
        $this->assertSame(0, $this->spawned);
        $this->assertSame([], $this->alerts());
    }

    public function test_alerts_go_straight_to_telegram_not_through_the_queue(): void
    {
        SchedulerHeartbeat::beat(time() - 600);
        SchedulerWatchdog::tick('auto');
        $this->assertNotEmpty($this->alertsContaining('定时任务已停摆'));
        // 队列停了的时候还往 send_telegram 里塞，告警就和故障一起卡住
        Bus::assertNotDispatched(SendTelegramJob::class);
    }

    public function test_direct_send_failure_falls_back_to_the_queue(): void
    {
        $this->telegramUp = false;
        SchedulerHeartbeat::beat(time() - 600);
        SchedulerWatchdog::tick('auto');
        Bus::assertDispatched(SendTelegramJob::class);
        $last = \App\Support\AdminAlert::lastUrgent();
        $this->assertSame(0, $last['direct']);
        $this->assertGreaterThan(0, $last['queued']);
        $this->assertNotNull($last['error']);
    }

    public function test_stalled_monitor_is_reported_while_the_scheduler_runs(): void
    {
        SchedulerHeartbeat::beat(time() - 20);
        Cache::forever(\App\Services\Monitor\SystemMonitor::LAST_CHECK, time() - 600);
        SchedulerWatchdog::tick('auto');
        SchedulerWatchdog::tick('auto');
        $this->assertCount(1, $this->alertsContaining('面板巡检已 10 分钟没跑'));
    }

    public function test_stale_heartbeat_spawns_one_fallback_run_per_minute_and_warns_once(): void
    {
        SchedulerHeartbeat::beat(time() - 200);
        $this->assertSame('fallback', SchedulerWatchdog::tick('auto'));
        $this->assertSame('fallback', SchedulerWatchdog::tick('auto'));
        $this->assertSame(1, $this->spawned, '同一分钟内只派一个子进程');
        $this->assertCount(1, $this->alertsContaining('专用调度进程'));
        $this->assertCount(0, $this->alertsContaining('已停摆'));
    }

    public function test_long_stall_sends_urgent_alert_and_recovery_notice(): void
    {
        SchedulerHeartbeat::beat(time() - 1800);
        SchedulerWatchdog::tick('auto');
        SchedulerWatchdog::tick('auto');
        $this->assertCount(1, $this->alertsContaining('定时任务已停摆 30 分钟'), '30 分钟内只提醒一次');

        SchedulerHeartbeat::beat();
        $this->assertSame('ok', SchedulerWatchdog::tick('auto'));
        SchedulerWatchdog::tick('auto');
        $this->assertCount(1, $this->alertsContaining('定时任务已恢复'));
    }

    public function test_off_mode_never_spawns_but_still_alerts(): void
    {
        SchedulerHeartbeat::beat(time() - 600);
        $this->assertSame('skipped', SchedulerWatchdog::tick('off'));
        $this->assertSame(0, $this->spawned);
        $this->assertCount(1, $this->alertsContaining('定时任务已停摆'));
    }

    public function test_never_beaten_falls_back_without_alerting(): void
    {
        // 首次部署、scheduler 容器还没起来：代跑，但不报「停摆」
        $this->assertSame('fallback', SchedulerWatchdog::tick('auto'));
        $this->assertSame(1, $this->spawned);
        $this->assertSame([], $this->alerts());
        $this->assertNotNull(Cache::get(SchedulerWatchdog::FALLBACK_AT), '仪表盘据此显示「网页进程在代跑」');
    }

    public function test_health_command_reflects_heartbeat_age(): void
    {
        $this->artisan('scheduler:health')->assertExitCode(1);
        SchedulerHeartbeat::beat(time() - 400);
        $this->artisan('scheduler:health', ['--max-age' => 180])->assertExitCode(1);
        SchedulerHeartbeat::beat(time() - 20);
        $this->artisan('scheduler:health', ['--max-age' => 180])->assertExitCode(0);
    }

    public function test_payment_digest_sends_once_per_day_and_catches_up_after_a_gap(): void
    {
        $this->artisan('payment:alert-digest', ['--once-per-day' => true])->assertExitCode(0);
        $first = (int) Cache::get('payment_alert_digest:last_sent_at');
        $this->assertGreaterThan(0, $first);

        $this->artisan('payment:alert-digest', ['--once-per-day' => true])->expectsOutput('今天已经发过，跳过')->assertExitCode(0);
    }
}
