<?php

namespace Tests\Feature;

use App\Services\DeviceStateService;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;
use Throwable;

/**
 * alivelist 只对「此刻在任一节点上有设备记录」的用户做 HGETALL（生产 Redis 命中率 13.7% 的来源）。
 * 前三个用例在 CI 里跑（Redis 被替换），最后一个需要本机 Redis：用真实的写路径造数据，
 * 证明过滤后的结果与逐个查询完全相同。
 */
class AliveListOnlineFilterTest extends TestCase
{
    use RefreshDatabase;

    private function serviceWith(?array $online, ?array $expectedLookups, array $counts): DeviceStateService
    {
        $service = $this->getMockBuilder(DeviceStateService::class)
            ->onlyMethods(['onlineUserIds', 'getDeviceCounts'])
            ->getMock();
        $service->method('onlineUserIds')->willReturn($online);
        if ($expectedLookups === null) {
            $service->expects($this->never())->method('getDeviceCounts');
        } else {
            $service->expects($this->once())->method('getDeviceCounts')
                ->with($expectedLookups)
                ->willReturn($counts);
        }
        return $service;
    }

    private function users(array $ids)
    {
        return collect(array_map(fn ($id) => (object) ['id' => $id, 'device_limit' => 3], $ids));
    }

    public function test_only_users_present_in_a_node_index_are_looked_up(): void
    {
        $service = $this->serviceWith([2, '5', 99], [2, 5], [2 => 1, 5 => 0]);
        $this->assertSame([2 => 1], $service->getAliveList($this->users([1, 2, 3, 5])));
    }

    public function test_no_lookup_at_all_when_nobody_on_the_list_is_online(): void
    {
        $service = $this->serviceWith([7, 8], null, []);
        $this->assertSame([], $service->getAliveList($this->users([1, 2, 3])));
    }

    public function test_falls_back_to_the_full_list_when_the_index_cannot_be_read(): void
    {
        $service = $this->serviceWith(null, [1, 2, 3], [1 => 2, 2 => 0, 3 => 1]);
        $this->assertSame([1 => 2, 3 => 1], $service->getAliveList($this->users([1, 2, 3])));
    }

    public function test_matches_a_full_scan_on_real_redis(): void
    {
        config([
            'database.redis.default' => ['host' => '127.0.0.1', 'port' => (int) (getenv('XB_TEST_REDIS_PORT') ?: 6379), 'database' => 15, 'persistent' => false],
            'database.redis.options.prefix' => 'xb_alivelist_test:',
            'cache.stores.redis' => ['driver' => 'array'],
        ]);
        // RedisManager 在创建时就拿走了配置：丢掉旧实例，让它按上面的配置重建
        $this->app->forgetInstance('redis');
        $this->app->forgetInstance('redis.connection');
        Redis::clearResolvedInstances();
        Cache::forgetDriver('redis');
        try {
            Redis::connection()->ping();
        } catch (Throwable $e) {
            $this->markTestSkipped('本机没有可用的 Redis：' . $e->getMessage());
        }
        Redis::flushdb();

        // admin_setting('device_limit_mode') 走内存替身：0 严格、1 按 IP 去重
        $mode = 0;
        $this->app->instance(Setting::class, new class ($mode) extends Setting {
            public function __construct(public int &$mode) {}
            public function get(string $key, mixed $default = null): mixed { return $key === 'device_limit_mode' ? $this->mode : $default; }
        });

        $nodes = [11, 12, 13, 14];
        $service = new class ($nodes) extends DeviceStateService {
            public function __construct(private array $nodes) {}
            protected function nodeIds(): array { return $this->nodes; }
            public function notifyUpdate(int $userId): void {}
        };

        mt_srand(20261021);
        $allUsers = range(1, 400);
        foreach ($nodes as $node) {
            $snapshot = [];
            foreach (array_rand(array_flip($allUsers), 60) as $uid) {
                $snapshot[$uid] = array_map(fn () => '10.0.' . mt_rand(0, 3) . '.' . mt_rand(1, 3), range(1, mt_rand(1, 3)));
            }
            $service->syncNodeDevices($node, $snapshot);
        }
        // 第二轮：节点 12 有一半人下线、节点 13 整个断开，验证删除路径也不会让过滤漏人
        $half = array_slice(array_keys($service->getNodeDevices(12)), 0, 30);
        $service->syncNodeDevices(12, array_fill_keys($half, ['10.9.9.9']));
        $service->clearAllNodeDevices(13);

        $online = $service->onlineUserIds();
        $this->assertGreaterThan(0, count($online));
        $this->assertLessThan(count($allUsers), count(array_unique($online)), '样本里应有不在线的用户，过滤才有意义');

        $listUsers = collect(array_map(fn ($id) => (object) ['id' => $id], $allUsers));
        foreach ([0, 1] as $m) {
            $mode = $m;
            $full = array_filter($service->getDeviceCounts($allUsers), fn ($c) => $c > 0);
            ksort($full);
            $filtered = $service->getAliveList($listUsers);
            ksort($filtered);
            $this->assertNotEmpty($full);
            $this->assertSame($full, $filtered, "device_limit_mode={$m} 时过滤结果与逐个查询不一致");
        }

        Redis::flushdb();
    }
}
