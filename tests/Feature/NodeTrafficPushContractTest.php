<?php

namespace Tests\Feature;

use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 机群里大多数节点仍是 Go 版 V2bX：它的上报路径、请求格式和得到的响应必须保持原样。
 * 这里按 V2bX 的原样请求（query 带 token/node_id/node_type，正文是 uid → [上行, 下行]）
 * 调用推送接口，核对响应和入账结果；Rust 节点多带的批次头不改变任何行为。
 */
class NodeTrafficPushContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        app()->forgetScopedInstances();
        Redis::shouldReceive('sadd')->andReturn(1)->byDefault();
    }

    public static function senders(): array
    {
        return [
            'V2bX' => [[]],
            'sufe-node-rust' => [['X-Sufe-Traffic-Batch' => '25']],
        ];
    }

    #[Test]
    #[DataProvider('senders')]
    public function a_push_is_answered_and_billed_as_before(array $headers): void
    {
        admin_setting(['server_token' => 'node-token']);
        $node = Server::create([
            'type' => 'vless', 'name' => 'n', 'rate' => 2, 'group_ids' => ['1'], 'show' => true,
            'host' => 'n.example.test', 'port' => '443', 'server_port' => 443,
        ]);
        foreach ([1, 2] as $uid) {
            DB::table('v2_user')->insert([
                'id' => $uid, 'email' => "u{$uid}@example.test", 'password' => 'x', 'uuid' => "uuid-{$uid}",
                'token' => "token-{$uid}", 'u' => 0, 'd' => 0, 't' => 0, 'created_at' => time(), 'updated_at' => time(),
            ]);
        }
        $url = '/api/v1/server/UniProxy/push?token=node-token&node_id=' . $node->id . '&node_type=vless';
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $response = $this->call('POST', $url, [], [], [], $server, '{"1":[100,200],"2":[300,400]}');

        $response->assertOk();
        $this->assertSame(['status' => 'success', 'message' => '操作成功', 'data' => true, 'error' => null], $response->json());
        $users = DB::table('v2_user')->orderBy('id')->get(['u', 'd'])
            ->map(fn ($row) => [(int) $row->u, (int) $row->d])->all();
        $this->assertSame([[200, 400], [600, 800]], $users);
        $stat = DB::table('v2_stat_server')->where('server_id', $node->id)->sole();
        $this->assertSame([400, 600], [(int) $stat->u, (int) $stat->d]);
        $this->assertSame(2, (int) Cache::get(\App\Utils\CacheKey::get('SERVER_VLESS_ONLINE_USER', $node->id)));

        // 非 JSON 正文仍是原来的 422
        $this->call('POST', $url, [], [], [], $server, 'not json')->assertStatus(422);
    }
}
