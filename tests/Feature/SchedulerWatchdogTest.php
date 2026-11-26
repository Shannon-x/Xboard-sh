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
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 定时任务心跳与看门狗（2026-11-23 调度整块停摆近三天无人发现之后加的）。
 */
class SchedulerWatchdogTest extends TestCase
{
    use RefreshDatabase;

    private int $spawned = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Bus::fake([SendTelegramJob::class, SendEmailJob::class]);
        config(['scheduler.stale_after' => 150, 'scheduler.alert_after' => 300, 'scheduler.heartbeat_url' => null]);
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

    /** @return list<string> */
    private function alerts(): array
    {
        $texts = [];
        Bus::assertDispatched(SendTelegramJob::class, function (SendTelegramJob $job) use (&$texts) {
            $texts[] = (fn () => $this->text)->call($job);
            return true;
        });
        return $texts;
    }

    private function alertsContaining(string $needle): array
    {
        try {
            return array_values(array_filter($this->alerts(), fn ($t) => str_contains($t, $needle)));
        } catch (\Throwable) {
            return [];
        }
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
        Bus::assertNotDispatched(SendTelegramJob::class);
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
        Bus::assertNotDispatched(SendTelegramJob::class);
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
