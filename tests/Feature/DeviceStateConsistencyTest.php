<?php

namespace Tests\Feature;

use App\Http\Controllers\V1\Server\UniProxyController;
use App\Http\Controllers\V2\Server\ServerController;
use App\Services\DeviceStateService;
use App\Services\Plugin\PluginManager;
use App\Support\Setting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DeviceStateConsistencyTest extends TestCase
{
    #[Test]
    public function the_database_reconciliation_is_scheduled_every_minute(): void
    {
        $pluginManager = Mockery::mock(PluginManager::class);
        $pluginManager->shouldReceive('registerPluginSchedules')->once();
        $this->app->instance(PluginManager::class, $pluginManager);

        $schedule = $this->app->make(\App\Console\Kernel::class)->resolveConsoleSchedule();
        $event = collect($schedule->events())->first(
            fn ($event) => str_contains($event->command ?? '', 'device:reconcile-online-counts')
        );

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
    }

    #[Test]
    public function the_reconciliation_command_passes_the_validated_chunk_size(): void
    {
        $service = $this->getMockBuilder(DeviceStateService::class)
            ->onlyMethods(['reconcileOnlineCounts'])
            ->getMock();
        $service->expects($this->once())
            ->method('reconcileOnlineCounts')
            ->with(123)
            ->willReturn(2);
        $this->app->instance(DeviceStateService::class, $service);

        $this->artisan('device:reconcile-online-counts', ['--chunk' => 123])
            ->expectsOutput('Reconciled 2 user online count(s).')
            ->assertSuccessful();
    }

    #[Test]
    public function it_applies_full_node_snapshots_and_returns_all_affected_users(): void
    {
        $service = $this->getMockBuilder(DeviceStateService::class)
            ->onlyMethods(['getNodeDevices', 'removeNodeDevices', 'notifyUpdate', 'setDevices'])
            ->getMock();

        $service->expects($this->once())
            ->method('getNodeDevices')
            ->with(7)
            ->willReturn([
                10 => ['1.1.1.1'],
                20 => ['2.2.2.2'],
            ]);
        $service->expects($this->once())->method('removeNodeDevices')->with(7, 20);
        $service->expects($this->once())->method('notifyUpdate')->with(20);

        $setCalls = [];
        $service->expects($this->exactly(2))
            ->method('setDevices')
            ->willReturnCallback(function (int $userId, int $nodeId, array $ips) use (&$setCalls): void {
                $setCalls[] = [$userId, $nodeId, $ips];
            });

        $affected = $service->syncNodeDevices(7, [
            10 => ['1.1.1.1'],
            30 => ['3.3.3.3'],
            'invalid' => ['4.4.4.4'],
        ]);

        $this->assertSame([10, 20, 30], $affected);
        $this->assertSame([
            [10, 7, ['1.1.1.1']],
            [30, 7, ['3.3.3.3']],
        ], $setCalls);
    }

    #[Test]
    public function v1_alive_treats_an_empty_object_as_a_full_empty_snapshot(): void
    {
        $service = Mockery::mock(DeviceStateService::class);
        $service->shouldReceive('syncNodeDevices')->once()->with(7, [])->andReturn([20]);
        $service->shouldReceive('getDeviceCounts')->once()->with([20])->andReturn([20 => 0]);

        $request = Request::create('/api/v1/server/UniProxy/alive', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{}');
        $request->attributes->set('node_info', (object) ['id' => 7]);
        $this->app->instance('request', $request);

        $response = (new UniProxyController($service))->alive($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['data' => true, 'alive' => ['20' => 0]], $response->getData(true));
    }

    #[Test]
    public function v2_report_distinguishes_a_missing_alive_field_from_an_empty_snapshot(): void
    {
        $service = Mockery::mock(DeviceStateService::class);
        $service->shouldReceive('syncNodeDevices')->once()->with(7, [])->andReturn([20]);
        $service->shouldReceive('getDeviceCounts')->once()->with([20])->andReturn([20 => 0]);
        $this->app->instance(DeviceStateService::class, $service);

        $missingRequest = Request::create('/api/v2/server/node/report', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{}');
        $missingRequest->attributes->set('node_info', (object) ['id' => 7, 'type' => 'v2ray']);
        $missingResponse = (new ServerController())->report($missingRequest);

        $request = Request::create('/api/v2/server/node/report', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{"alive":[]}');
        $request->attributes->set('node_info', (object) ['id' => 7, 'type' => 'v2ray']);

        $response = (new ServerController())->report($request);

        $this->assertSame(200, $missingResponse->getStatusCode());
        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayNotHasKey('alive', $missingResponse->getData(true));
        $this->assertSame(['20' => 0], $response->getData(true)['alive']);
    }

    #[Test]
    public function it_batches_redis_reads_and_ignores_expired_device_fields(): void
    {
        $setting = Mockery::mock(Setting::class);
        $setting->shouldReceive('get')->once()->with('device_limit_mode')->andReturn(null);
        $this->app->instance(Setting::class, $setting);

        $now = time();
        Redis::shouldReceive('pipeline')
            ->once()
            ->with(Mockery::type(\Closure::class))
            ->andReturn([
                [
                    '1:1.1.1.1' => $now,
                    '2:2.2.2.2' => $now - 30,
                    '3:3.3.3.3' => $now - 301,
                ],
                [],
            ]);

        $counts = (new DeviceStateService())->getDeviceCounts([10, 20]);

        $this->assertSame([10 => 2, 20 => 0], $counts);
    }

    #[Test]
    public function it_reconciles_stale_and_recent_database_snapshots_with_redis(): void
    {
        config(['database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');

        Schema::create('v2_user', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('online_count')->nullable();
            $table->timestamp('last_online_at')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });

        try {
            DB::table('v2_user')->insert([
                ['id' => 1, 'online_count' => 18, 'last_online_at' => now()->subDay(), 'created_at' => 0, 'updated_at' => 0],
                ['id' => 2, 'online_count' => 0, 'last_online_at' => now(), 'created_at' => 0, 'updated_at' => 0],
                ['id' => 3, 'online_count' => 0, 'last_online_at' => now()->subDay(), 'created_at' => 0, 'updated_at' => 0],
                ['id' => 4, 'online_count' => 3, 'last_online_at' => now(), 'created_at' => 0, 'updated_at' => 0],
            ]);

            $service = $this->getMockBuilder(DeviceStateService::class)
                ->onlyMethods(['getDeviceCounts'])
                ->getMock();
            $service->expects($this->once())
                ->method('getDeviceCounts')
                ->with([1, 2, 4])
                ->willReturn([1 => 0, 2 => 2, 4 => 3]);

            $updated = $service->reconcileOnlineCounts();

            $this->assertSame(2, $updated);
            $this->assertSame([1 => 0, 2 => 2, 3 => 0, 4 => 3], DB::table('v2_user')
                ->orderBy('id')
                ->pluck('online_count', 'id')
                ->mapWithKeys(fn ($count, $id) => [(int) $id => (int) $count])
                ->all());
        } finally {
            Schema::dropIfExists('v2_user');
        }
    }
}
