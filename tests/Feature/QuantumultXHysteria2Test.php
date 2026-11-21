<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Protocols\QuantumultX;
use Tests\TestCase;

/**
 * 圈X 从 1.9.0（build 965）起支持 hysteria2=；更旧的版本、读不到版本号的请求都不发 hy2 节点。
 */
class QuantumultXHysteria2Test extends TestCase
{
    private const PIN = 'ab:cd:ef:01:23:45:67:89:ab:cd:ef:01:23:45:67:89:ab:cd:ef:01:23:45:67:89:ab:cd:ef:01:23:45:67:89';

    private function servers(): array
    {
        return [
            [
                'id' => 1, 'type' => Server::TYPE_HYSTERIA, 'name' => 'hy2-pin-hop', 'host' => 'node.example.com',
                'port' => 31234, 'ports' => '31000-31999', 'password' => 'uuid-1',
                'protocol_settings' => [
                    'version' => 2,
                    'bandwidth' => ['up' => 100, 'down' => 500],
                    'obfs' => ['open' => false, 'type' => 'salamander', 'password' => null],
                    'tls' => ['server_name' => 'www.bing.com', 'allow_insecure' => false, 'pinned_peer_cert_sha256' => self::PIN],
                    'hop_interval' => 30,
                ],
            ],
            [
                'id' => 2, 'type' => Server::TYPE_HYSTERIA, 'name' => 'hy2-obfs-insecure', 'host' => '2001:db8::1',
                'port' => 443, 'password' => 'uuid-1',
                'protocol_settings' => [
                    'version' => 2,
                    'obfs' => ['open' => true, 'type' => 'salamander', 'password' => 'obfs-key'],
                    'tls' => ['server_name' => 'a.example.com', 'allow_insecure' => true],
                ],
            ],
            [
                'id' => 3, 'type' => Server::TYPE_HYSTERIA, 'name' => 'hy1', 'host' => 'old.example.com',
                'port' => 443, 'password' => 'uuid-1',
                'protocol_settings' => ['version' => 1],
            ],
        ];
    }

    private function lines(?string $client, ?string $version): array
    {
        $protocol = new QuantumultX(
            ['u' => 0, 'd' => 0, 'transfer_enable' => 0, 'expired_at' => 0],
            $this->servers(),
            $client,
            $version
        );
        $body = base64_decode($protocol->handle()->getContent());

        return array_values(array_filter(explode("\r\n", $body)));
    }

    public function test_new_quantumult_x_gets_hysteria2_lines(): void
    {
        $lines = $this->lines('quantumult%20x', '1.9.0');

        $this->assertCount(2, $lines, 'hy1 节点不发');
        $this->assertSame(
            'hysteria2=node.example.com:31234,password=uuid-1,tls-host=www.bing.com,tls-verification=true,'
            . 'tls-cert-sha256=abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789,'
            . 'download-bandwidth=500,port-hopping=31000-31999,port-hopping-interval=30,udp-relay=true,tag=hy2-pin-hop',
            $lines[0]
        );
        $this->assertSame(
            'hysteria2=[2001:db8::1]:443,password=uuid-1,salamander-password=obfs-key,tls-host=a.example.com,'
            . 'tls-verification=false,udp-relay=true,tag=hy2-obfs-insecure',
            $lines[1]
        );
    }

    public function test_old_or_unknown_quantumult_x_gets_no_hysteria2(): void
    {
        foreach ([['quantumult%20x', '1.8.1'], ['quantumult%20x', '1.5'], ['quantumult%20x', null]] as [$client, $version]) {
            $this->assertSame([], $this->lines($client, $version), "{$client} " . ($version ?? '无版本'));
        }
    }
}
