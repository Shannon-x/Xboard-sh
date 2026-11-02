<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\ServerSave;
use App\Models\Server;
use App\Protocols\ClashMeta;
use App\Protocols\General;
use App\Protocols\SingBox;
use App\Services\ServerService;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * AnyTLS 跑在 Xray 内核（V2bX PR #26 / v2node）上的面板侧适配。
 *
 *   · 节点端读 network / network_settings（V1 另读 networkSettings）决定外层传输，
 *     为空即 tcp；面板必须能保存并原样下发这两个键。
 *   · 现有客户端（mihomo / sing-box / Surge …）的 anytls 出站没有传输层选项，
 *     非 tcp 的 AnyTLS 节点不能出现在订阅里。
 *   · Xray 的 TLS 监听未配 ALPN 时声明 h2 + http/1.1，sing-box 订阅默认报 h3 会被拒绝握手。
 */
class AnyTlsXrayCompatTest extends TestCase
{
    private function anyTlsNode(array $protocolSettings): Server
    {
        $node = new Server();
        $node->type = Server::TYPE_ANYTLS;
        $node->host = 'node.example.com';
        $node->server_port = 8443;
        $node->protocol_settings = $protocolSettings;

        return $node;
    }

    private function subscriptionServer(?string $network, string $name = 'anytls-node'): array
    {
        return [
            'id' => 1,
            'name' => $name,
            'host' => 'node.example.com',
            'port' => 443,
            'type' => Server::TYPE_ANYTLS,
            'password' => 'uuid-1',
            'protocol_settings' => [
                'network' => $network,
                'tls' => ['server_name' => 'www.example.com', 'allow_insecure' => false],
            ],
        ];
    }

    private function filteredServers(string $protocolClass, array $servers): array
    {
        $protocol = new $protocolClass(['uuid' => 'uuid-1'], $servers);
        $prop = new \ReflectionProperty($protocol, 'servers');
        $prop->setAccessible(true);

        return $prop->getValue($protocol);
    }

    public function test_model_keeps_network_and_network_settings(): void
    {
        $node = $this->anyTlsNode([
            'network' => 'ws',
            'network_settings' => ['path' => '/anytls', 'headers' => ['Host' => 'cdn.example.com']],
        ]);

        $this->assertSame('ws', $node->protocol_settings['network']);
        $this->assertSame('/anytls', $node->protocol_settings['network_settings']['path']);
        // 原有字段不受影响
        $this->assertNotEmpty($node->protocol_settings['padding_scheme']);
    }

    public function test_node_config_delivers_transport_for_xray_core(): void
    {
        $node = $this->anyTlsNode([
            'network' => 'ws',
            'network_settings' => ['path' => '/anytls'],
            'padding_scheme' => ['stop=8', '0=30-30'],
            'tls' => ['server_name' => 'www.example.com'],
        ]);

        $config = ServerService::buildNodeConfig($node);

        $this->assertSame('anytls', $config['protocol']);
        $this->assertSame('ws', $config['network']);
        $this->assertSame(['path' => '/anytls'], $config['network_settings']);
        // V2bX V1 解析时也认 networkSettings
        $this->assertSame(['path' => '/anytls'], $config['networkSettings']);
        $this->assertSame(['stop=8', '0=30-30'], $config['padding_scheme']);
        $this->assertSame('www.example.com', $config['server_name']);
    }

    public function test_node_config_without_network_stays_plain_tcp(): void
    {
        $config = ServerService::buildNodeConfig($this->anyTlsNode([]));

        // null 被 array_filter 去掉，节点端按 tcp 处理；与改动前的载荷一致
        $this->assertArrayNotHasKey('network', $config);
        $this->assertArrayNotHasKey('network_settings', $config);
        $this->assertNotEmpty($config['padding_scheme']);
    }

    public function test_admin_save_validation_keeps_transport_fields(): void
    {
        $payload = [
            'type' => 'anytls',
            'name' => 'anytls-ws',
            'host' => 'node.example.com',
            'port' => 443,
            'server_port' => 8443,
            'rate' => 1,
            'protocol_settings' => [
                'tls' => ['server_name' => 'www.example.com', 'allow_insecure' => false],
                'padding_scheme' => ['stop=8'],
                'network' => 'grpc',
                'network_settings' => ['serviceName' => 'anytls'],
            ],
        ];
        $rules = ServerSave::create('/', 'POST', $payload)->rules();

        $validated = Validator::make($payload, $rules)->validated();

        $this->assertSame('grpc', $validated['protocol_settings']['network']);
        $this->assertSame(['serviceName' => 'anytls'], $validated['protocol_settings']['network_settings']);

        $payload['protocol_settings']['network'] = 'mkcp';
        $this->assertTrue(Validator::make($payload, $rules)->fails(), 'V2bX 的 AnyTLS 不支持 mkcp');
    }

    public function test_subscription_drops_non_tcp_anytls_nodes(): void
    {
        $servers = [
            $this->subscriptionServer(null, 'legacy'),
            $this->subscriptionServer('tcp', 'tcp'),
            $this->subscriptionServer('ws', 'ws'),
            $this->subscriptionServer('grpc', 'grpc'),
        ];

        foreach ([General::class, ClashMeta::class, SingBox::class] as $class) {
            $names = array_column($this->filteredServers($class, $servers), 'name');
            $this->assertSame(['legacy', 'tcp'], $names, $class);
        }
    }

    public function test_singbox_anytls_has_no_h3_alpn_by_default(): void
    {
        $protocol = new SingBox(['uuid' => 'uuid-1'], []);
        $method = new \ReflectionMethod($protocol, 'buildAnyTLS');
        $method->setAccessible(true);

        $outbound = $method->invoke($protocol, 'uuid-1', $this->subscriptionServer(null));
        $this->assertArrayNotHasKey('alpn', $outbound['tls']);
        $this->assertSame('www.example.com', $outbound['tls']['server_name']);

        $server = $this->subscriptionServer(null);
        $server['protocol_settings']['alpn'] = 'h2, http/1.1';
        $outbound = $method->invoke($protocol, 'uuid-1', $server);
        $this->assertSame(['h2', 'http/1.1'], $outbound['tls']['alpn']);
    }
}
