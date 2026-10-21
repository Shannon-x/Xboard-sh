<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** 节点拉名单接口放宽 memory_limit 后必须还原：Octane 常驻进程里 ini_set 不随请求结束复位。 */
class UniProxyMemoryLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        app()->forgetScopedInstances();
    }

    #[Test]
    public function the_user_list_endpoint_restores_the_memory_limit_on_200_and_304(): void
    {
        admin_setting(['server_token' => 'node-token']);
        $node = Server::create([
            'type' => 'vless', 'name' => 'n', 'rate' => 1, 'group_ids' => ['1'], 'show' => true,
            'host' => 'n.example.test', 'port' => '443', 'server_port' => 443,
        ]);
        $user = User::create([
            'email' => 'u@example.test', 'password' => 'x', 'uuid' => 'uuid-1', 'token' => 'token-1',
            'group_id' => 1, 'plan_id' => 1, 'transfer_enable' => 1024, 'expired_at' => null,
        ]);
        $url = '/api/v1/server/UniProxy/user?token=node-token&node_id=' . $node->id . '&node_type=vless';
        $limit = ini_get('memory_limit');

        $response = $this->getJson($url)->assertOk();
        $this->assertSame($limit, ini_get('memory_limit'));
        $this->assertSame([$user->id], array_column($response->json('users'), 'id'));

        $this->getJson($url, ['If-None-Match' => $response->headers->get('ETag')])->assertStatus(304);
        $this->assertSame($limit, ini_get('memory_limit'));
    }
}
