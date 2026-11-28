<?php

namespace Tests\Feature;

use App\Models\AccessLogFile;
use App\Models\AccessLogQuery;
use App\Models\Server;
use App\Models\User;
use App\Services\AccessLog\AccessLogBatch;
use App\Services\AccessLog\AccessLogConfig;
use App\Services\AccessLog\AccessLogStorage;
use App\Services\AccessLog\ArraySubtotalBuffer;
use App\Services\AccessLog\RedisSubtotalBuffer;
use App\Services\AccessLog\SubtotalBuffer;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;
use Throwable;

/**
 * 用户访问记录：Rust 节点上传批次 → S3 原样存放 + 目录 + 日汇总；后台按用户查日汇总、
 * 只按范围读该用户那一段做明细和导出；过期清理。S3 用进程内的替身（支持 Range 和批量删除）。
 */
class AccessLogTest extends TestCase
{
    use RefreshDatabase;

    private const LEDGER = '0123456789abcdef0123456789abcdef';

    private Server $node;
    private ArraySubtotalBuffer $buffer;
    /** @var array<string,string> */
    private array $objects = [];
    /** @var array<int,array{0:string,1:string,2:string}> */
    private array $requests = [];
    private bool $failWrites = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        app()->forgetScopedInstances();
        Redis::shouldReceive('sadd')->andReturn(1)->byDefault();
        $this->travelTo(Carbon::parse('2026-11-28 14:30:00', 'Asia/Shanghai'));

        admin_setting(['server_token' => 'node-token']);
        $this->node = Server::create([
            'type' => 'vless', 'name' => '东京 1', 'rate' => 1, 'group_ids' => ['1'], 'show' => true,
            'host' => 'n.example.test', 'port' => '443', 'server_port' => 443,
        ]);
        foreach ([7, 8, 39] as $uid) {
            DB::table('v2_user')->insert([
                'id' => $uid, 'email' => "u{$uid}@example.test", 'password' => 'x', 'uuid' => "uuid-{$uid}",
                'token' => "token-{$uid}", 'u' => 0, 'd' => 0, 't' => 0, 'created_at' => time(), 'updated_at' => time(),
            ]);
        }
        $this->configure(['access_log_enable' => 1]);
        $this->buffer = new ArraySubtotalBuffer();
        $this->app->instance(SubtotalBuffer::class, $this->buffer);
    }

    private function configure(array $settings): void
    {
        admin_setting($settings + [
            'access_log_s3_endpoint' => 'https://s3.example.test',
            'access_log_s3_bucket' => 'logs',
            'access_log_s3_access_key' => 'AKIDEXAMPLE',
            'access_log_s3_secret_key' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
        ]);
        $config = AccessLogConfig::fromSettings();
        if ($config->storageConfigured()) {
            $this->app->instance(AccessLogStorage::class, new AccessLogStorage($config->s3, $this->s3()));
        }
    }

    /** 一个只在内存里的桶：PUT / GET（含 Range）/ DELETE / DeleteObjects */
    private function s3(): Client
    {
        $handler = function (RequestInterface $request) {
            $path = rawurldecode($request->getUri()->getPath());
            $key = substr($path, strlen('/logs/'));
            $method = $request->getMethod();
            $range = $request->getHeaderLine('range');
            $this->requests[] = [$method, $key, $range];
            $this->assertStringStartsWith('AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/', $request->getHeaderLine('authorization'));
            if ($method === 'PUT') {
                if ($this->failWrites) {
                    return Create::promiseFor(new Response(500, [], '<Error><Code>InternalError</Code></Error>'));
                }
                $this->objects[$key] = (string) $request->getBody();
                return Create::promiseFor(new Response(200));
            }
            if ($method === 'POST' && $request->getUri()->getQuery() === 'delete') {
                preg_match_all('#<Key>([^<]*)</Key>#', (string) $request->getBody(), $m);
                foreach ($m[1] as $deleted) {
                    unset($this->objects[htmlspecialchars_decode($deleted)]);
                }
                return Create::promiseFor(new Response(200, [], '<DeleteResult/>'));
            }
            if ($method === 'DELETE') {
                unset($this->objects[$key]);
                return Create::promiseFor(new Response(204));
            }
            if (!isset($this->objects[$key])) {
                return Create::promiseFor(new Response(404, [], '<Error><Code>NoSuchKey</Code></Error>'));
            }
            $body = $this->objects[$key];
            if (preg_match('/^bytes=(\d+)-(\d+)$/', $range, $m)) {
                return Create::promiseFor(new Response(206, [], substr($body, (int) $m[1], (int) $m[2] - (int) $m[1] + 1)));
            }
            return Create::promiseFor(new Response(200, [], $body));
        };
        return new Client(['handler' => HandlerStack::create($handler), 'http_errors' => false]);
    }

    /**
     * 按节点的格式造一个批次。
     *
     * @param array<int,array{0:int,1:string,2:int,3:int}> $rows [user, host, up, down]
     */
    private function batch(array $rows, int $hourAt, int $seq = 0): string
    {
        $groups = array_fill(0, 32, '');
        $users = [];
        foreach ($rows as [$uid, $host, $up, $down]) {
            $groups[$uid % 32] .= implode("\t", [$uid, $hourAt + 60, '1.5', 'tcp', $host, 443, 'direct', '198.51.100.7', $up, $down, 1]) . "\n";
            $users[$uid] ??= [$uid, 0, 0, 0];
            $users[$uid][1]++;
            $users[$uid][2] += $up;
            $users[$uid][3] += $down;
        }
        ksort($users);
        $data = '';
        $ranges = [];
        foreach ($groups as $text) {
            $offset = strlen($data);
            if ($text !== '') {
                $data .= gzencode($text);
            }
            $ranges[] = [$offset, strlen($data) - $offset];
        }
        $header = [
            'v' => 1, 'ledger' => self::LEDGER, 'seq' => $seq, 'hour' => $hourAt, 'from' => $hourAt + 60, 'to' => $hourAt + 62,
            'records' => count($rows), 'dropped' => 2, 'groups' => $ranges, 'users' => array_values($users),
        ];
        return json_encode($header) . "\n" . $data;
    }

    private function hourAt(string $local): int
    {
        return Carbon::parse($local, 'Asia/Shanghai')->getTimestamp();
    }

    private function upload(string $body)
    {
        $url = "/api/v2/server/access-log?token=node-token&node_id={$this->node->id}&node_type=vless";
        return $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], $body);
    }

    private function adminPath(string $path): string
    {
        return '/api/v2/' . admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))) . $path;
    }

    private function admin(): User
    {
        $admin = User::create([
            'email' => Str::random(10) . '@example.test', 'password' => 'x', 'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'is_admin' => 1, 'balance' => 0, 'transfer_enable' => 0, 'u' => 0, 'd' => 0,
        ]);
        Sanctum::actingAs($admin, ['admin']);
        return $admin;
    }

    public function test_the_handshake_tells_rust_nodes_whether_to_record(): void
    {
        $handshake = fn() => $this->postJson('/api/v2/server/handshake', [
            'token' => 'node-token', 'node_id' => $this->node->id, 'node_type' => 'vless',
        ])->assertOk()->json();

        $this->assertSame(['enabled' => true, 'interval' => 300], $handshake()['access_log']);
        $this->assertSame(['enabled' => false], $handshake()['websocket']);

        $this->configure(['access_log_enable' => 1, 'access_log_s3_bucket' => '', 'access_log_interval' => 120]);
        $this->assertSame(['enabled' => false, 'interval' => 120], $handshake()['access_log'], '存储没配好就不让节点记');

        $this->configure(['access_log_enable' => 0]);
        $this->assertFalse($handshake()['access_log']['enabled']);
    }

    public function test_an_uploaded_batch_is_stored_once_and_summed_per_user_and_day(): void
    {
        $hour = $this->hourAt('2026-11-28 14:00:00');
        $body = $this->batch([[7, 'example.com', 10, 20], [39, 'only39.test', 1, 2], [7, 'ip.sb', 5, 6], [8, 'eight.test', 3, 4]], $hour);

        $this->upload($body)->assertOk()->assertJson(['stored' => true, 'duplicate' => false]);
        $key = "access-logs/v1/dt=2026-11-28/node=vless-{$this->node->id}/hour=14/" . self::LEDGER . '-00000000.tsv.gz';
        $this->assertSame([$key], array_keys($this->objects));
        $this->assertSame(substr($body, strpos($body, "\n") + 1), $this->objects[$key], '对象就是批次的数据部分');
        $this->assertStringContainsString("7\t", gzdecode($this->objects[$key]), '32 段首尾相接仍是合法的 gzip');

        $file = AccessLogFile::sole();
        $this->assertSame(['2026-11-28', 14, 4, 2, 3], [(string) $file->dt, $file->hour, $file->records, $file->dropped, $file->users]);

        // 节点没收到回复而重传：不重复存、不重复计
        $this->upload($body)->assertOk()->assertJson(['stored' => true, 'duplicate' => true]);
        $this->assertSame(1, AccessLogFile::count());

        $this->upload($this->batch([[7, 'example.com', 100, 200]], $this->hourAt('2026-11-28 13:00:00'), 1))->assertOk();
        $this->artisan('access-log:flush')->assertSuccessful();
        $this->upload($this->batch([[7, 'example.com', 1000, 2000]], $hour, 2))->assertOk();
        $this->artisan('access-log:flush')->assertSuccessful();

        $rows = DB::table('v2_access_log_user_day')->orderBy('user_id')->get()
            ->map(fn($r) => [(int) $r->user_id, (string) $r->dt, (int) $r->records, (int) $r->up, (int) $r->down, (int) $r->hours])->all();
        $this->assertSame([
            [7, '2026-11-28', 4, 1115, 2226, (1 << 13) | (1 << 14)],
            [8, '2026-11-28', 1, 3, 4, 1 << 14],
            [39, '2026-11-28', 1, 1, 2, 1 << 14],
        ], $rows);
    }

    public function test_bad_batches_are_rejected_and_storage_outages_ask_for_a_retry(): void
    {
        $hour = $this->hourAt('2026-11-28 14:00:00');
        $this->upload("not a batch")->assertStatus(400);
        $good = $this->batch([[7, 'example.com', 10, 20]], $hour);
        $this->upload(substr($good, 0, -3))->assertStatus(400);
        $this->upload($this->batch([[7, 'example.com', 10, 20]], $hour - 9 * 86400))->assertStatus(400);

        $this->failWrites = true;
        $this->upload($good)->assertStatus(503);
        $this->assertSame(0, AccessLogFile::count());

        $this->failWrites = false;
        $this->upload($good)->assertOk()->assertJson(['duplicate' => false]);
        $this->assertSame(1, AccessLogFile::count());

        // 没带正确的节点凭据：沿用 server 中间件原来的回应
        $this->call('POST', '/api/v2/server/access-log?token=wrong&node_id=' . $this->node->id . '&node_type=vless', [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], $good)
            ->assertStatus(422);
    }

    public function test_batches_are_dropped_while_recording_is_off(): void
    {
        $this->configure(['access_log_enable' => 0]);
        $this->upload($this->batch([[7, 'example.com', 10, 20]], $this->hourAt('2026-11-28 14:00:00')))
            ->assertOk()->assertJson(['stored' => false]);
        $this->assertSame([], $this->objects);
        $this->assertSame(0, AccessLogFile::count());
    }

    public function test_a_detail_query_reads_only_the_users_group_and_exports_csv(): void
    {
        $hour = $this->hourAt('2026-11-28 14:00:00');
        $this->upload($this->batch([[7, 'example.com', 10, 20], [39, 'only39.test', 1, 2], [7, 'ip.sb', 5, 6], [8, 'eight.test', 3, 4]], $hour))->assertOk();
        $this->upload($this->batch([[8, 'eight.test', 3, 4]], $this->hourAt('2026-11-28 12:00:00'), 1))->assertOk();
        $this->upload($this->batch([[7, 'example.com', 100, 200]], $this->hourAt('2026-11-28 13:00:00'), 2))->assertOk();
        $this->artisan('access-log:flush')->assertSuccessful();
        $admin = $this->admin();

        $summary = $this->postJson($this->adminPath('/accessLog/summary'), ['user_id' => 7])->assertOk()->json('data');
        $this->assertSame(['records' => 3, 'up' => 115, 'down' => 226], $summary['total']);
        $this->assertSame('2026-11-28', $summary['days'][0]['dt']);
        $this->assertSame('东京 1', $summary['days'][0]['nodes'][0]['node_name']);
        $this->assertSame([13, 14], $summary['days'][0]['nodes'][0]['hours']);

        $this->requests = [];
        $id = $this->postJson($this->adminPath('/accessLog/query'), ['user_id' => 7, 'from' => '2026-11-28', 'to' => '2026-11-28'])
            ->assertOk()->json('data.id');
        $result = $this->postJson($this->adminPath('/accessLog/result'), ['id' => $id])->assertOk()->json('data');

        $this->assertSame(AccessLogQuery::STATUS_DONE, $result['status']);
        $this->assertSame([2, 0, 3, false], [$result['files'], $result['missing'], $result['rows'], $result['truncated']]);
        $this->assertSame(['example.com', 'ip.sb'], array_column($result['summary']['hosts'], 'host'));
        $this->assertSame(['host' => 'example.com', 'records' => 2, 'up' => 110, 'down' => 220], $result['summary']['hosts'][0]);
        $this->assertSame($admin->email, $result['admin']['email']);
        $this->assertStringContainsString('X-Amz-Signature=', $result['download_url']);
        $this->assertArrayNotHasKey('export_key', $result);

        // 只读了两个小时的对象，每个只读用户 7 所在的那一段；12 点那批里没有他，不读
        $gets = array_values(array_filter($this->requests, fn($r) => $r[0] === 'GET'));
        $this->assertCount(2, $gets);
        foreach ($gets as [, $key, $range]) {
            $file = AccessLogFile::where('object_key', $key)->sole();
            [$offset, $length] = $file->range(7);
            $this->assertSame(sprintf('bytes=%d-%d', $offset, $offset + $length - 1), $range);
        }

        $export = $this->objects[array_values(array_filter(array_keys($this->objects), fn($k) => str_contains($k, '/exports/')))[0]];
        $lines = array_values(array_filter(explode("\n", $export)));
        $this->assertCount(4, $lines);
        $this->assertStringStartsWith("\xEF\xBB\xBF开始时间,", $lines[0]);
        $this->assertSame(['2026-11-28 13:01:00', '1.5', 'tcp', 'example.com', '443', 'direct', '198.51.100.7', '100', '200', '已结束', '东京 1'], str_getcsv($lines[1]));
        $this->assertStringNotContainsString('only39.test', $export);

        // 过滤目标
        $id = $this->postJson($this->adminPath('/accessLog/query'), ['user_id' => 7, 'from' => '2026-11-28', 'to' => '2026-11-28', 'host' => 'IP.SB'])
            ->assertOk()->json('data.id');
        $this->assertSame(1, AccessLogQuery::find($id)->rows);

        // 查询记录就是审计：谁、何时、查了谁
        $list = $this->postJson($this->adminPath('/accessLog/queries'), ['user_id' => 7])->assertOk()->json();
        $this->assertSame(2, $list['total']);
        $this->assertSame($admin->id, $list['data'][0]['admin_id']);

        $this->postJson($this->adminPath('/accessLog/query'), ['user_id' => 7, 'from' => '2026-11-01', 'to' => '2026-11-28'])->assertStatus(422);
    }

    public function test_read_only_tokens_cannot_see_access_records(): void
    {
        $admin = User::create([
            'email' => 'ro@example.test', 'password' => 'x', 'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'is_admin' => 1, 'balance' => 0, 'transfer_enable' => 0, 'u' => 0, 'd' => 0,
        ]);
        Sanctum::actingAs($admin, ['admin:read']);
        $this->postJson($this->adminPath('/accessLog/summary'), ['user_id' => 7])->assertStatus(403);
    }

    public function test_prune_removes_expired_objects_rows_and_exports(): void
    {
        $this->upload($this->batch([[7, 'example.com', 10, 20]], $this->hourAt('2026-11-28 14:00:00')))->assertOk();
        $this->artisan('access-log:flush')->assertSuccessful();
        $this->admin();
        $this->postJson($this->adminPath('/accessLog/query'), ['user_id' => 7, 'from' => '2026-11-28', 'to' => '2026-11-28'])->assertOk();
        $this->assertCount(2, $this->objects);

        $this->travelTo(Carbon::parse('2026-12-20 04:00:00', 'Asia/Shanghai'));
        $this->artisan('access-log:prune')->assertSuccessful();
        $this->assertCount(1, $this->objects, '导出 7 天后删除，批次还在保留期内');
        $this->assertSame(1, AccessLogFile::count());

        $this->travelTo(Carbon::parse('2026-12-28 04:00:00', 'Asia/Shanghai'));
        $this->artisan('access-log:prune')->assertSuccessful();
        $this->assertSame([], $this->objects);
        $this->assertSame(0, AccessLogFile::count());
        $this->assertSame(0, DB::table('v2_access_log_user_day')->count());
        $this->assertSame(1, AccessLogQuery::count(), '查询审计记录留一年');
    }

    public function test_v2bx_pushes_are_answered_as_before_while_recording(): void
    {
        $url = '/api/v1/server/UniProxy/push?token=node-token&node_id=' . $this->node->id . '&node_type=vless';
        $response = $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"7":[100,200]}');
        $response->assertOk();
        $this->assertSame(['status' => 'success', 'message' => '操作成功', 'data' => true, 'error' => null], $response->json());
        $this->assertSame([], $this->objects);
    }

    public function test_batch_headers_are_checked_against_the_data(): void
    {
        $now = $this->hourAt('2026-11-28 14:30:00');
        $good = $this->batch([[7, 'example.com', 10, 20]], $this->hourAt('2026-11-28 14:00:00'));
        $batch = AccessLogBatch::parse($good, $now);
        $this->assertSame([[7, 1, 10, 20]], $batch->users);

        [$head, $data] = explode("\n", $good, 2);
        $header = json_decode($head, true);
        foreach ([
            'records' => fn($h) => ['records' => 2] + $h,
            'ledger' => fn($h) => ['ledger' => 'nothex'] + $h,
            'hour' => fn($h) => ['hour' => $h['hour'] + 1] + $h,
            'future' => fn($h) => ['hour' => $h['hour'] + 2 * 3600] + $h,
            'groups' => fn($h) => ['groups' => array_slice($h['groups'], 1)] + $h,
            'user' => fn($h) => ['users' => [[0, 1, 10, 20]]] + $h,
            'version' => fn($h) => ['v' => 2] + $h,
        ] as $case => $change) {
            try {
                AccessLogBatch::parse(json_encode($change($header)) . "\n" . $data, $now);
                $this->fail("{$case} 应被拒绝");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_redis_buffer_counts_each_batch_once(): void
    {
        config([
            'database.redis.default' => ['host' => '127.0.0.1', 'port' => (int) (getenv('XB_TEST_REDIS_PORT') ?: 6379), 'database' => 15, 'persistent' => false],
            'database.redis.options.prefix' => 'xb_access_log_test:',
        ]);
        $this->app->forgetInstance('redis');
        $this->app->forgetInstance('redis.connection');
        Redis::clearResolvedInstances();
        try {
            Redis::connection()->ping();
        } catch (Throwable $e) {
            $this->markTestSkipped('本机没有可用的 Redis：' . $e->getMessage());
        }
        Redis::flushdb();
        $buffer = new RedisSubtotalBuffer();

        $this->assertTrue($buffer->add('b1', '2026-11-28', 14, 'vless', 3, [[7, 2, 15, 26], [8, 1, 3, 4]]));
        $this->assertFalse($buffer->add('b1', '2026-11-28', 14, 'vless', 3, [[7, 2, 15, 26]]));
        $this->assertTrue($buffer->add('b2', '2026-11-28', 13, 'vless', 3, [[7, 1, 100, 200]]));
        $this->assertTrue($buffer->add('b3', '2026-11-28', 13, 'trojan', 4, [[7, 1, 1, 1]]));

        $seen = [];
        $failed = false;
        try {
            $buffer->drain(function (array $rows) {
                throw new \RuntimeException('db down');
            });
        } catch (\RuntimeException) {
            $failed = true;
        }
        $this->assertTrue($failed);
        // 写库失败的那份留着，下次一起交
        $this->assertSame(3, $buffer->drain(function (array $rows) use (&$seen) {
            array_push($seen, ...$rows);
        }));
        usort($seen, fn($a, $b) => [$a['node_type'], $a['user_id']] <=> [$b['node_type'], $b['user_id']]);
        $this->assertSame([
            ['dt' => '2026-11-28', 'node_type' => 'trojan', 'node_id' => 4, 'user_id' => 7, 'records' => 1, 'up' => 1, 'down' => 1, 'hours' => 1 << 13],
            ['dt' => '2026-11-28', 'node_type' => 'vless', 'node_id' => 3, 'user_id' => 7, 'records' => 3, 'up' => 115, 'down' => 226, 'hours' => (1 << 13) | (1 << 14)],
            ['dt' => '2026-11-28', 'node_type' => 'vless', 'node_id' => 3, 'user_id' => 8, 'records' => 1, 'up' => 3, 'down' => 4, 'hours' => 1 << 14],
        ], $seen);
        $this->assertSame(0, $buffer->drain(fn() => null));
        Redis::flushdb();
    }
}
