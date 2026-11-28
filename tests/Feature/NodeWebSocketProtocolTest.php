<?php

namespace Tests\Feature;

use App\Console\Commands\NodeWebSocketServer;
use App\Console\Commands\PushExpiredUsers;
use App\Jobs\TrafficReportJob;
use App\Models\Server;
use App\Models\User;
use App\Services\DeviceStateService;
use App\Services\NodePayload;
use App\Services\NodeWsProtocol;
use App\Support\TrafficBatchKey;
use App\Utils\CacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * Rust 节点的 WS 扩展协议（sufe.1）和 HTTP 上报的批次去重。
 *
 * 核心不变量：
 *   · 同一个账本号 + 批次号，不管走 WS 还是 HTTP、发几次，只入账一次；
 *   · WS 下发的配置 / 名单和 HTTP 拉到的逐字节相同，ETag 也相同；
 *   · 不带新头、不协商扩展协议的请求（V2bX、Xboard-Node）行为不变。
 */
class NodeWebSocketProtocolTest extends TestCase
{
    use RefreshDatabase;

    private const LEDGER = '0123456789abcdef0123456789abcdef';

    /** @var array<int, array> Redis node:push 上发布的消息 */
    private array $published = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        app()->forgetScopedInstances();
        Redis::shouldReceive('sadd')->andReturn(1)->byDefault();
        Redis::shouldReceive('publish')->andReturnUsing(function ($channel, $message) {
            $this->published[] = ['channel' => $channel] + json_decode($message, true);
            return 1;
        })->byDefault();
        admin_setting(['server_token' => 'node-token']);
    }

    private function node(array $attributes = []): Server
    {
        return Server::create($attributes + [
            'type' => 'vless', 'name' => 'n', 'rate' => 2, 'group_ids' => ['1'], 'show' => true,
            'host' => 'n.example.test', 'port' => '443', 'server_port' => 443,
            'protocol_settings' => ['network' => 'tcp', 'tls' => 1, 'tls_settings' => ['server_name' => 'n.example.test']],
        ]);
    }

    private function user(int $uid, array $attributes = []): void
    {
        DB::table('v2_user')->insert($attributes + [
            'id' => $uid, 'email' => "u{$uid}@example.test", 'password' => 'x', 'uuid' => "uuid-{$uid}",
            'token' => "token-{$uid}", 'u' => 0, 'd' => 0, 't' => 0, 'transfer_enable' => 1 << 40,
            'group_id' => 1, 'plan_id' => 1, 'banned' => 0, 'expired_at' => null,
            'created_at' => time(), 'updated_at' => time(),
        ]);
    }

    private function usage(): array
    {
        return DB::table('v2_user')->orderBy('id')->get(['u', 'd'])
            ->map(fn ($row) => [(int) $row->u, (int) $row->d])->all();
    }

    private function serverStat(Server $node): array
    {
        $stat = DB::table('v2_stat_server')->where('server_id', $node->id)->first();
        return $stat ? [(int) $stat->u, (int) $stat->d] : [0, 0];
    }

    private function query(Server $node): string
    {
        return '?token=node-token&node_id=' . $node->id . '&node_type=vless';
    }

    private function push(Server $node, string $body, array $headers = [])
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        return $this->call('POST', '/api/v1/server/UniProxy/push' . $this->query($node), [], [], [], $server, $body);
    }

    private function batchHeaders(int $batch, string $ledger = self::LEDGER): array
    {
        return ['X-Sufe-Traffic-Ledger' => $ledger, 'X-Sufe-Traffic-Batch' => (string) $batch];
    }

    private function ws(Server $node, string $event, array $data): array
    {
        return NodeWsProtocol::handle($node->id, $event, $data);
    }

    // ---- 流量：批次去重 ----

    #[Test]
    public function ws_finds_the_same_node_as_the_http_api_when_a_numeric_code_collides_with_another_id(): void
    {
        $a = $this->node(['name' => 'a']);
        $b = $this->node(['name' => 'b']);
        $a->update(['code' => (string) $b->id]);   // 现网 id19 的 code 是 20 这种情况
        $trojan = $this->node(['name' => 't', 'type' => 'trojan']);

        $http = \App\Services\ServerService::getServer((string) $b->id, 'vless');
        $this->assertSame($a->id, $http->id, 'HTTP 先按 code 认');
        $this->assertSame($a->id, NodeWsProtocol::resolveNode(['node_id' => (string) $b->id, 'node_type' => 'vless'])?->id);

        $this->assertSame($trojan->id, NodeWsProtocol::resolveNode(['node_id' => (string) $trojan->id, 'node_type' => 'trojan'])?->id);
        $this->assertNull(NodeWsProtocol::resolveNode(['node_id' => (string) $trojan->id, 'node_type' => 'vless']), '类型不符不认');
        $this->assertNull(NodeWsProtocol::resolveNode(['node_id' => (string) $trojan->id, 'node_type' => 'nope']));
        $this->assertNull(NodeWsProtocol::resolveNode(['node_id' => '']));
        $this->assertNull(NodeWsProtocol::resolveNode(['node_id' => ['x']]));
        $this->assertSame($trojan->id, NodeWsProtocol::resolveNode(['node_id' => (string) $trojan->id, 'node_type' => 'v2node'])?->id, 'v2node 不按类型过滤');
    }

    #[Test]
    public function auth_success_echoes_the_node_id_the_node_connected_with(): void
    {
        // 节点核对 auth.success 的 node_id 和自己配置的是否一致；按 code 认到别的 id 时也要回它自己的号
        $a = $this->node(['name' => 'a']);
        $b = $this->node(['name' => 'b']);
        $a->update(['code' => (string) $b->id]);
        $params = ['node_id' => (string) $b->id, 'node_type' => 'vless'];

        $node = NodeWsProtocol::resolveNode($params);
        $this->assertSame($a->id, $node->id, '面板内部按 HTTP 的规则登记');
        $this->assertSame($b->id, NodeWsProtocol::ackNodeId($params, $node));
        $this->assertSame($b->id, NodeWsProtocol::authSuccess(NodeWsProtocol::ackNodeId($params, $node), true)['data']['node_id']);

        // 号不是规范的整数（字符串 code）时回内部 id
        $a->update(['code' => 'jp-a']);
        $byCode = ['node_id' => 'jp-a', 'node_type' => 'vless'];
        $this->assertSame($a->id, NodeWsProtocol::ackNodeId($byCode, NodeWsProtocol::resolveNode($byCode)));
        $this->assertSame($a->id, NodeWsProtocol::ackNodeId(['node_id' => '007'], $a));
    }

    #[Test]
    public function the_same_batch_over_http_is_billed_once_and_says_so(): void
    {
        $node = $this->node();
        $this->user(1);
        $this->user(2);

        foreach ([1, 2, 3] as $attempt) {
            $response = $this->push($node, '{"1":[100,200],"2":[300,400]}', $this->batchHeaders(7));
            $response->assertOk();
            $this->assertSame('1', $response->headers->get(TrafficBatchKey::IDEMPOTENT_HEADER));
            $this->assertSame(['status' => 'success', 'message' => '操作成功', 'data' => true, 'error' => null], $response->json());
        }

        // 倍率 2，三次发送只记一次
        $this->assertSame([[200, 400], [600, 800]], $this->usage());
        $this->assertSame([400, 600], $this->serverStat($node));

        // 下一批照常入账
        $this->push($node, '{"1":[1,1]}', $this->batchHeaders(8))->assertOk();
        $this->assertSame([[202, 402], [600, 800]], $this->usage());
    }

    #[Test]
    public function pushes_without_a_ledger_keep_the_old_behaviour(): void
    {
        $node = $this->node();
        $this->user(1);

        // V2bX：不带头；旧版 Rust 节点：只带批次号。两者都不去重、不回新响应头
        foreach ([[], ['X-Sufe-Traffic-Batch' => '7'], ['X-Sufe-Traffic-Ledger' => 'not-hex', 'X-Sufe-Traffic-Batch' => '7']] as $headers) {
            $response = $this->push($node, '{"1":[10,20]}', $headers);
            $response->assertOk();
            $this->assertNull($response->headers->get(TrafficBatchKey::IDEMPOTENT_HEADER));
        }
        $this->assertSame([[60, 120]], $this->usage());
    }

    #[Test]
    public function an_empty_batch_is_still_acknowledged_as_idempotent(): void
    {
        $node = $this->node();
        $response = $this->push($node, '{}', $this->batchHeaders(3));
        $response->assertOk();
        $this->assertSame('1', $response->headers->get(TrafficBatchKey::IDEMPOTENT_HEADER));
        $this->assertNull($this->push($node, '{}')->headers->get(TrafficBatchKey::IDEMPOTENT_HEADER));
    }

    #[Test]
    public function a_batch_sent_over_ws_and_resent_over_http_is_billed_once(): void
    {
        $node = $this->node();
        $this->user(1);

        $frames = $this->ws($node, 'report.traffic', ['seq' => 41, 'ledger' => self::LEDGER, 'batch' => 9, 'traffic' => ['1' => [100, 200]]]);
        $this->assertSame([NodeWsProtocol::ack(41, NodeWsProtocol::OK)], $frames);
        // WS 断开、节点没收到确认 → 同一批改走 HTTP 重发
        $this->push($node, '{"1":[100,200]}', $this->batchHeaders(9))->assertOk();
        // 再从 WS 发一次
        $this->ws($node, 'report.traffic', ['seq' => 42, 'ledger' => self::LEDGER, 'batch' => 9, 'traffic' => ['1' => [100, 200]]]);

        $this->assertSame([[200, 400]], $this->usage());
        $this->assertSame([100, 200], $this->serverStat($node));
        $this->assertSame(1, (int) Cache::get(CacheKey::get('SERVER_VLESS_ONLINE_USER', $node->id)));
        $this->assertNotNull(Cache::get(CacheKey::get('SERVER_VLESS_LAST_PUSH_AT', $node->id)));
    }

    #[Test]
    public function batches_are_scoped_to_the_ledger_and_the_node(): void
    {
        $a = $this->node();
        $b = $this->node(['name' => 'b']);
        $this->user(1);
        $traffic = ['1' => [10, 10]];

        $this->ws($a, 'report.traffic', ['seq' => 1, 'ledger' => self::LEDGER, 'batch' => 1, 'traffic' => $traffic]);
        // 换了账本（节点重建账本后批次号从 1 开始）
        $this->ws($a, 'report.traffic', ['seq' => 2, 'ledger' => str_repeat('f', 32), 'batch' => 1, 'traffic' => $traffic]);
        // 另一个节点碰巧用了同样的账本号和批次号
        $this->ws($b, 'report.traffic', ['seq' => 1, 'ledger' => self::LEDGER, 'batch' => 1, 'traffic' => $traffic]);

        $this->assertSame([[60, 60]], $this->usage());
    }

    #[Test]
    public function malformed_ws_traffic_is_rejected_without_billing(): void
    {
        $node = $this->node();
        $this->user(1);
        foreach ([
            ['seq' => 1, 'ledger' => 'short', 'batch' => 1, 'traffic' => ['1' => [1, 1]]],
            ['seq' => 2, 'ledger' => self::LEDGER, 'batch' => 0, 'traffic' => ['1' => [1, 1]]],
            ['seq' => 3, 'ledger' => strtoupper(self::LEDGER), 'batch' => 1, 'traffic' => ['1' => [1, 1]]],
            ['seq' => 5, 'ledger' => self::LEDGER, 'batch' => 1.5, 'traffic' => ['1' => [1, 1]]],
            ['seq' => 4, 'ledger' => self::LEDGER, 'batch' => 1, 'traffic' => 'x'],
        ] as $data) {
            $frames = $this->ws($node, 'report.traffic', $data);
            $this->assertSame(NodeWsProtocol::REJECTED, $frames[0]['data']['status']);
            $this->assertSame($data['seq'], $frames[0]['data']['seq']);
        }
        $this->assertSame([[0, 0]], $this->usage());

        // 没有序号就没法确认
        $this->assertSame('error', $this->ws($node, 'report.traffic', ['ledger' => self::LEDGER])[0]['event']);
        $this->assertNull(NodeWsProtocol::handle($node->id, 'pong', []));
    }

    #[Test]
    public function a_failure_while_queueing_asks_the_node_to_retry(): void
    {
        $node = $this->node();
        $this->user(1);
        \Illuminate\Support\Facades\Queue::swap(new class(app()) extends \Illuminate\Support\Testing\Fakes\QueueFake {
            public function push($job, $data = '', $queue = null)
            {
                throw new \RuntimeException('redis went away');
            }
        });
        $frames = $this->ws($node, 'report.traffic', ['seq' => 5, 'ledger' => self::LEDGER, 'batch' => 1, 'traffic' => ['1' => [1, 1]]]);
        $this->assertSame([NodeWsProtocol::ack(5, NodeWsProtocol::RETRY)], $frames);
    }

    #[Test]
    public function traffic_report_jobs_queued_before_the_upgrade_still_run(): void
    {
        $job = new TrafficReportJob(['id' => 1, 'rate' => 1], [1 => [1, 1]], 'vless', strtotime('today'));
        $data = $job->__serialize();
        unset($data["\0*\0parentKey"]);
        $legacy = (new ReflectionClass(TrafficReportJob::class))->newInstanceWithoutConstructor();
        $legacy->__unserialize($data);
        $restored = unserialize(serialize($legacy));
        $this->assertNull((fn () => $this->parentKey)->call($restored));
        \Illuminate\Support\Facades\Queue::fake();
        $restored->handle();
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\TrafficFetchJob::class, 1);
    }

    // ---- 下发：WS 与 HTTP 内容和 ETag 一致 ----

    #[Test]
    public function ws_payloads_match_the_http_responses_and_etags(): void
    {
        $node = $this->node();
        $this->user(1);
        $this->user(2, ['speed_limit' => 100, 'device_limit' => 3]);

        $config = $this->getJson('/api/v1/server/UniProxy/config' . $this->query($node));
        $config->assertOk();
        $users = $this->getJson('/api/v1/server/UniProxy/user' . $this->query($node));
        $users->assertOk();

        [$syncConfig, $syncUsers] = NodeWsProtocol::fullSync($node);
        $this->assertSame('sync.config', $syncConfig['event']);
        $this->assertSame('sync.users', $syncUsers['event']);
        $this->assertSame($config->getContent(), json_encode($syncConfig['data']['config']));
        $this->assertSame($users->getContent(), json_encode(['users' => $syncUsers['data']['users']]));
        $this->assertSame($config->headers->get('ETag'), '"' . $syncConfig['data']['etag'] . '"');
        $this->assertSame($users->headers->get('ETag'), '"' . $syncUsers['data']['etag'] . '"');
        $this->assertArrayHasKey('base_config', $syncConfig['data']['config']);

        // HTTP 回落时带 WS 上拿到的 ETag 去拉，面板回 304
        $this->getJson('/api/v1/server/UniProxy/user' . $this->query($node), ['If-None-Match' => '"' . $syncUsers['data']['etag'] . '"'])
            ->assertStatus(304);
    }

    #[Test]
    public function sync_check_only_sends_what_changed(): void
    {
        $node = $this->node();
        $this->user(1);
        [$config, $users] = NodeWsProtocol::fullSync($node);
        $configEtag = '"' . $config['data']['etag'] . '"';
        $usersEtag = $users['data']['etag'];

        $frames = $this->ws($node, 'sync.check', ['seq' => 1, 'config_etag' => $configEtag, 'users_etag' => $usersEtag]);
        $this->assertSame([['event' => 'sync.ok', 'data' => ['seq' => 1, 'config' => 'same', 'users' => 'same']]], $frames);
        $this->assertNotNull(Cache::get(CacheKey::get('SERVER_VLESS_LAST_CHECK_AT', $node->id)));

        // 推送丢了：名单变了
        $this->user(2);
        $frames = $this->ws($node, 'sync.check', ['seq' => 2, 'config_etag' => $configEtag, 'users_etag' => $usersEtag]);
        $this->assertSame(['sync.users', 'sync.ok'], array_column($frames, 'event'));
        $this->assertSame([1, 2], array_map(fn ($u) => (int) $u->id, $frames[0]['data']['users']));
        $this->assertSame(['seq' => 2, 'config' => 'same', 'users' => 'sent'], $frames[1]['data']);

        // 节点手里什么都没有
        $frames = $this->ws($node, 'sync.check', ['seq' => 3]);
        $this->assertSame(['sync.config', 'sync.users', 'sync.ok'], array_column($frames, 'event'));
    }

    #[Test]
    public function auth_success_only_advertises_features_when_negotiated(): void
    {
        $this->assertSame(['event' => 'auth.success', 'data' => ['node_id' => 3]], NodeWsProtocol::authSuccess(3, false));
        $extended = NodeWsProtocol::authSuccess(3, true);
        $this->assertSame('sufe.1', $extended['data']['protocol']);
        $this->assertContains('report.traffic', $extended['data']['features']);
        $this->assertTrue(NodeWsProtocol::negotiated(['proto' => 'sufe.1']));
        $this->assertFalse(NodeWsProtocol::negotiated([]));
        $this->assertFalse(NodeWsProtocol::negotiated(['proto' => ['sufe.1']]));
    }

    // ---- 在线设备和负载 ----

    #[Test]
    public function ws_activity_answers_with_the_same_device_counts_as_http_alive(): void
    {
        $node = $this->node();
        $snapshots = [];
        $devices = $this->getMockBuilder(DeviceStateService::class)
            ->onlyMethods(['syncNodeDevices', 'getDeviceCounts'])
            ->getMock();
        $devices->method('syncNodeDevices')->willReturnCallback(function (int $nodeId, array $alive) use (&$snapshots) {
            $snapshots[] = [$nodeId, $alive];
            return array_map('intval', array_keys($alive));
        });
        $devices->method('getDeviceCounts')->willReturnCallback(fn (array $ids) => array_fill_keys($ids, 2));
        $this->app->instance(DeviceStateService::class, $devices);

        $alive = ['5' => ['1.1.1.1', '2.2.2.2'], '6' => ['3.3.3.3']];
        $http = $this->call('POST', '/api/v1/server/UniProxy/alive' . $this->query($node), [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($alive));
        $http->assertOk();
        $frames = $this->ws($node, 'report.activity', ['seq' => 9, 'alive' => $alive, 'online' => ['5' => 4]]);

        $this->assertSame('report.ack', $frames[0]['event']);
        $this->assertSame(9, $frames[0]['data']['seq']);
        $this->assertSame(NodeWsProtocol::OK, $frames[0]['data']['status']);
        $this->assertSame($http->json('alive'), json_decode(json_encode($frames[0]['data']['alive']), true));
        $this->assertSame($snapshots[0], $snapshots[1]);
        $this->assertSame(4, Cache::get(CacheKey::get("USER_ONLINE_CONN_vless_{$node->id}", 5)));

        // 空快照 = 这个节点上没人在线，也要同步
        $frames = $this->ws($node, 'report.activity', ['seq' => 10, 'alive' => []]);
        $this->assertSame([$node->id, []], $snapshots[2]);
        $this->assertEquals((object) [], $frames[0]['data']['alive']);
    }

    #[Test]
    public function ws_alive_counts_match_http_alivelist(): void
    {
        $node = $this->node();
        $this->user(1, ['device_limit' => 2]);
        $this->user(2);
        $seen = [];
        $devices = $this->getMockBuilder(DeviceStateService::class)
            ->onlyMethods(['getAliveList'])
            ->getMock();
        $devices->method('getAliveList')->willReturnCallback(function ($users) use (&$seen) {
            $seen[] = $users->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
            return [1 => 3];
        });
        $this->app->instance(DeviceStateService::class, $devices);

        $http = $this->getJson('/api/v1/server/UniProxy/alivelist' . $this->query($node));
        $http->assertOk();
        $frames = $this->ws($node, 'request.alive', ['seq' => 4]);

        $this->assertSame(NodeWsProtocol::OK, $frames[0]['data']['status']);
        $this->assertSame($http->json('alive'), json_decode(json_encode($frames[0]['data']['alive']), true));
        $this->assertSame([[1], [1]], $seen);
    }

    #[Test]
    public function ws_status_is_validated_and_cached_like_http_status(): void
    {
        $node = $this->node();
        $status = ['cpu' => 12.5, 'mem' => ['total' => 100, 'used' => 50], 'swap' => ['total' => 0, 'used' => 0], 'disk' => ['total' => 10, 'used' => 1]];

        $this->postJson('/api/v1/server/UniProxy/status' . $this->query($node), $status)->assertOk();
        $httpCached = Cache::get(CacheKey::get('SERVER_VLESS_LOAD_STATUS', $node->id));
        Cache::forget(CacheKey::get('SERVER_VLESS_LOAD_STATUS', $node->id));

        $frames = $this->ws($node, 'report.status', ['seq' => 1, 'status' => $status, 'metrics' => ['active_connections' => 7]]);
        $this->assertSame([NodeWsProtocol::ack(1, NodeWsProtocol::OK)], $frames);
        $wsCached = Cache::get(CacheKey::get('SERVER_VLESS_LOAD_STATUS', $node->id));
        unset($httpCached['updated_at'], $wsCached['updated_at']);
        $this->assertSame($httpCached, $wsCached);
        $this->assertSame(7, Cache::get(CacheKey::get('SERVER_VLESS_METRICS', $node->id))['active_connections']);
        $this->assertNotNull(Cache::get(CacheKey::get('SERVER_VLESS_LAST_CHECK_AT', $node->id)));

        $frames = $this->ws($node, 'report.status', ['seq' => 2, 'status' => ['cpu' => 300] + $status]);
        $this->assertSame(NodeWsProtocol::REJECTED, $frames[0]['data']['status']);
    }

    // ---- 握手 ----

    #[Test]
    public function handshake_offers_ws_only_while_the_ws_server_is_alive(): void
    {
        $node = $this->node();
        $url = '/api/v2/server/handshake' . $this->query($node);

        $this->postJson($url)->assertOk()->assertExactJson(['websocket' => ['enabled' => false]]);

        Cache::put(NodeWebSocketServer::HEARTBEAT_CACHE_KEY, time(), 30);
        $this->postJson($url)->assertOk()->assertExactJson(['websocket' => ['enabled' => true, 'ws_url' => 'ws://localhost/ws']]);

        admin_setting(['server_ws_url' => 'wss://panel.example.test/ws/']);
        $this->postJson($url)->assertOk()->assertExactJson(['websocket' => ['enabled' => true, 'ws_url' => 'wss://panel.example.test/ws']]);

        admin_setting(['server_ws_enable' => 0]);
        $this->postJson($url)->assertOk()->assertExactJson(['websocket' => ['enabled' => false]]);
    }

    // ---- 推送修复 ----

    #[Test]
    public function changing_a_nodes_groups_pushes_a_full_sync_to_that_node(): void
    {
        $node = $this->node();
        $other = $this->node(['name' => 'other', 'group_ids' => [(string) 999]]);
        $this->user(1, ['group_id' => 2]);
        Cache::put("node_ws_alive:{$node->id}", true, 60);
        Cache::put("node_ws_alive:{$other->id}", true, 60);
        $this->published = [];

        $node->update(['group_ids' => ['1', '2']]);

        $this->assertSame([[$node->id, 'sync.config'], [$node->id, 'sync.users']],
            array_map(fn ($m) => [$m['node_id'], $m['event']], $this->published));
        $this->assertSame([1], array_column($this->published[1]['data']['users'], 'id'));
        $this->assertArrayHasKey('etag', $this->published[1]['data']);
    }

    #[Test]
    public function a_renewal_or_plan_change_is_pushed_to_ws_nodes(): void
    {
        // 改 expired_at / plan_id 会先重算 next_reset_at（refresh + save），之前这一步把 dirty
        // 状态清掉了，续费和换套餐都不下发。队列在测试里是同步的，这里直接看推送。
        $node = $this->node();
        Cache::put("node_ws_alive:{$node->id}", true, 60);
        DB::table('v2_plan')->insert(['id' => 1, 'group_id' => 1, 'name' => 'p', 'transfer_enable' => 1024,
            'show' => 1, 'renew' => 1, 'sell' => 1, 'created_at' => time(), 'updated_at' => time()]);
        $this->user(1, ['expired_at' => time() - 60]);
        $this->published = [];

        $user = User::findOrFail(1);
        $user->expired_at = time() + 30 * 86400;
        $user->save();

        $this->assertSame(['sync.user.delta'], array_column($this->published, 'event'));
        $this->assertSame('add', $this->published[0]['data']['action']);
        $this->assertSame([1], array_column($this->published[0]['data']['users'], 'id'));
        $this->assertNotNull(User::findOrFail(1)->next_reset_at, '续费时仍要重算 next_reset_at');
    }

    #[Test]
    public function exceeded_users_are_removed_from_their_addon_group_nodes_too(): void
    {
        $base = $this->node(['name' => 'base', 'group_ids' => ['1']]);
        $addon = $this->node(['name' => 'addon', 'group_ids' => ['7']]);
        $this->user(1, ['u' => 10, 'd' => 0, 'transfer_enable' => 10, 'admin_group_ids' => json_encode([7])]);
        foreach ([$base, $addon] as $node) {
            Cache::put("node_ws_alive:{$node->id}", true, 60);
        }
        Redis::shouldReceive('scard')->with('traffic:pending_check')->andReturn(1);
        Redis::shouldReceive('spop')->with('traffic:pending_check', 1)->andReturn(['1']);

        $this->artisan('check:traffic-exceeded')->assertSuccessful();

        $pushes = collect($this->published)->keyBy('node_id');
        $this->assertSame([$base->id, $addon->id], $pushes->keys()->sort()->values()->all());
        foreach ($pushes as $push) {
            $this->assertSame(['action' => 'remove', 'users' => [['id' => 1]]], $push['data']);
        }
    }

    #[Test]
    public function users_that_just_expired_are_removed_from_ws_nodes(): void
    {
        $node = $this->node();
        Cache::put("node_ws_alive:{$node->id}", true, 60);
        Cache::put(NodeWebSocketServer::HEARTBEAT_CACHE_KEY, time(), 30);
        $now = time();
        $this->user(1, ['expired_at' => $now - 30]);          // 刚到期
        $this->user(2, ['expired_at' => $now - 3600]);        // 早就到期（不在窗口里）
        $this->user(3, ['expired_at' => $now + 3600]);        // 没到期
        $this->user(4, ['expired_at' => $now - 20, 'plan_id' => null]);
        Cache::put(PushExpiredUsers::CURSOR_KEY, $now - 60, 600);

        $this->artisan('node:push-expired')->assertSuccessful();

        $this->assertCount(1, $this->published);
        $this->assertSame(['action' => 'remove', 'users' => [['id' => 1], ['id' => 4]]], $this->published[0]['data']);
        $this->assertGreaterThanOrEqual($now, Cache::get(PushExpiredUsers::CURSOR_KEY));

        // 同一窗口不再重推
        $this->published = [];
        $this->artisan('node:push-expired')->assertSuccessful();
        $this->assertSame([], $this->published);
    }

    #[Test]
    public function expiry_pushes_are_skipped_while_the_ws_server_is_down(): void
    {
        $node = $this->node();
        Cache::put("node_ws_alive:{$node->id}", true, 60);
        $this->user(1, ['expired_at' => time() - 30]);
        Cache::put(PushExpiredUsers::CURSOR_KEY, time() - 60, 600);

        $this->artisan('node:push-expired')->assertSuccessful();
        $this->assertSame([], $this->published);
    }

    #[Test]
    public function the_expiry_push_is_scheduled_every_minute(): void
    {
        $pluginManager = \Mockery::mock(\App\Services\Plugin\PluginManager::class);
        $pluginManager->shouldReceive('registerPluginSchedules')->once();
        $this->app->instance(\App\Services\Plugin\PluginManager::class, $pluginManager);

        $schedule = $this->app->make(\App\Console\Kernel::class)->resolveConsoleSchedule();
        $event = collect($schedule->events())->first(fn ($event) => str_contains($event->command ?? '', 'node:push-expired'));
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
    }

    #[Test]
    public function an_old_connection_closing_late_does_not_unregister_the_new_one(): void
    {
        $old = \Mockery::mock(\Workerman\Connection\TcpConnection::class);
        $old->shouldReceive('close')->once();
        $new = \Mockery::mock(\Workerman\Connection\TcpConnection::class);

        \App\Services\NodeRegistry::add(77, $old);
        \App\Services\NodeRegistry::add(77, $new);
        $this->assertFalse(\App\Services\NodeRegistry::removeIfCurrent(77, $old));
        $this->assertSame($new, \App\Services\NodeRegistry::get(77));
        $this->assertTrue(\App\Services\NodeRegistry::removeIfCurrent(77, $new));
        $this->assertNull(\App\Services\NodeRegistry::get(77));
    }

    #[Test]
    public function etags_from_http_headers_are_normalized(): void
    {
        $etag = sha1('x');
        $this->assertSame($etag, NodePayload::normalizeEtag("\"{$etag}\""));
        $this->assertSame($etag, NodePayload::normalizeEtag("W/\"{$etag}\""));
        $this->assertSame($etag, NodePayload::normalizeEtag($etag));
        $this->assertNull(NodePayload::normalizeEtag('nope'));
        $this->assertNull(NodePayload::normalizeEtag(null));
    }
}
