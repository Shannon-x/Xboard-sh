<?php

namespace Tests\Unit;

use App\Console\Commands\XboardUpdate;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

class UpgradeCommandFailureTest extends TestCase
{
    public function test_failed_migration_stops_before_plugin_schedule_or_queue_mutation(): void
    {
        Artisan::shouldReceive('call')->once()->with('migrate', ['--force' => true])->andReturn(1);
        Artisan::shouldReceive('output')->once()->andReturn('migration failed');
        $command = new XboardUpdate();
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $this->assertSame(1, $tester->execute([]));
        $this->assertStringContainsString('停止更新', $tester->getDisplay());
    }
}
