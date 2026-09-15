<?php

namespace Tests\Feature;

use App\Jobs\StatServerJob;
use App\Jobs\StatUserJob;
use App\Services\Database\AutoIncrementMonitor;
use App\Services\Plugin\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 2026-09-15：v2_stat_user 的 int 主键被 upsert 烧到上限，新统计行被静默累加到同一行。
 * 默认跑 sqlite；设 DB_CONNECTION=mysql 跑一遍可以验证自增计数器真的不再空转。
 */
class StatAutoIncrementTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function repeated_user_reports_accumulate_into_one_row_per_rate(): void
    {
        $server = ['id' => 1, 'rate' => 3];
        for ($i = 0; $i < 5; $i++) {
            (new StatUserJob($server, [7 => [100, 1000], 8 => [1, 2]], 'vless'))->handle();
        }
        (new StatUserJob(['id' => 2, 'rate' => 1], [7 => [10, 20]], 'vless'))->handle();

        $rows = DB::table('v2_stat_user')->where('user_id', 7)->orderBy('server_rate')->get();
        $this->assertCount(2, $rows);
        $this->assertSame([10, 20], [(int) $rows[0]->u, (int) $rows[0]->d]);
        $this->assertSame([1500, 15000], [(int) $rows[1]->u, (int) $rows[1]->d]);
        $this->assertSame(1, DB::table('v2_stat_user')->where('user_id', 8)->count());
    }

    #[Test]
    public function repeated_server_reports_accumulate_into_one_row(): void
    {
        $server = ['id' => 5, 'rate' => 10];
        for ($i = 0; $i < 4; $i++) {
            (new StatServerJob($server, [1 => [5, 50], 2 => [1, 1]], 'vless'))->handle();
        }

        $row = DB::table('v2_stat_server')->where('server_id', 5)->sole();
        $this->assertSame([24, 204], [(int) $row->u, (int) $row->d]);
    }

    #[Test]
    public function updating_existing_stat_rows_does_not_consume_auto_increment_ids(): void
    {
        if (!in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Auto-increment burn only reproduces on MySQL/MariaDB.');
        }
        DB::statement('SET SESSION information_schema_stats_expiry = 0');
        $next = fn (string $table) => (int) DB::selectOne(
            'SELECT AUTO_INCREMENT AS a FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        )->a;

        $server = ['id' => 1, 'rate' => 1];
        (new StatUserJob($server, [1 => [1, 1], 2 => [1, 1]], 'vless'))->handle();
        (new StatServerJob($server, [1 => [1, 1]], 'vless'))->handle();
        $userBefore = $next('v2_stat_user');
        $serverBefore = $next('v2_stat_server');

        for ($i = 0; $i < 50; $i++) {
            (new StatUserJob($server, [1 => [1, 1], 2 => [1, 1]], 'vless'))->handle();
            (new StatServerJob($server, [1 => [1, 1]], 'vless'))->handle();
        }

        $this->assertSame($userBefore, $next('v2_stat_user'));
        $this->assertSame($serverBefore, $next('v2_stat_server'));
    }

    #[Test]
    public function stat_primary_keys_are_bigint_after_migration(): void
    {
        if (!in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('sqlite INTEGER PRIMARY KEY is already 64-bit.');
        }
        foreach (['v2_stat_user', 'v2_stat_server', 'v2_stat'] as $table) {
            $type = DB::selectOne(
                "SELECT DATA_TYPE AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'id'",
                [$table]
            )->t;
            $this->assertSame('bigint', strtolower($type), $table);
        }
    }

    #[Test]
    public function monitor_flags_an_int_counter_near_its_limit(): void
    {
        if (!in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Monitor only inspects MySQL/MariaDB and PostgreSQL.');
        }
        DB::statement('CREATE TABLE _autoinc_probe (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, v INT) AUTO_INCREMENT = 2000000000');
        try {
            $probe = collect(app(AutoIncrementMonitor::class)->scan())->firstWhere('table', '_autoinc_probe');
            $this->assertNotNull($probe);
            $this->assertGreaterThan(0.93, $probe['ratio']);

            $this->artisan('check:auto-increment', ['--threshold' => 0.9])->assertExitCode(1);
        } finally {
            DB::statement('DROP TABLE _autoinc_probe');
        }
    }

    #[Test]
    public function the_auto_increment_check_is_scheduled_hourly(): void
    {
        $pluginManager = Mockery::mock(PluginManager::class);
        $pluginManager->shouldReceive('registerPluginSchedules')->once();
        $this->app->instance(PluginManager::class, $pluginManager);

        $schedule = $this->app->make(\App\Console\Kernel::class)->resolveConsoleSchedule();
        $event = collect($schedule->events())->first(
            fn ($event) => str_contains($event->command ?? '', 'check:auto-increment')
        );

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
    }
}
