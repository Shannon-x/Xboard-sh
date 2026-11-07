<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Protocols\ClashMeta;
use App\Protocols\General;
use App\Protocols\Loon;
use App\Protocols\QuantumultX;
use App\Protocols\Shadowrocket;
use App\Protocols\SingBox;
use App\Protocols\Stash;
use Tests\TestCase;

/**
 * 开了 VLESS Encryption 的节点只下发给能用的客户端。
 *
 *   · 官方 sing-box 遇到未知字段 encryption 会拒绝整份配置；
 *   · NekoBox、旧版小火箭等会把它当普通 VLESS 导入，连不上也不报错。
 */
class VlessEncryptionClientFilterTest extends TestCase
{
    private function servers(): array
    {
        $base = [
            'host' => 'node.example.com',
            'port' => 443,
            'type' => Server::TYPE_VLESS,
            'password' => 'uuid-1',
        ];

        return [
            $base + ['id' => 1, 'name' => 'plain', 'protocol_settings' => ['encryption' => null, 'network' => 'tcp']],
            $base + ['id' => 2, 'name' => 'none', 'protocol_settings' => ['encryption' => 'none', 'network' => 'tcp']],
            $base + ['id' => 3, 'name' => 'enc', 'protocol_settings' => [
                'encryption' => 'mlkem768x25519plus',
                'encryption_settings' => ['mode' => 'native', 'rtt' => '0rtt', 'password' => 'pub'],
                'network' => 'tcp',
            ]],
        ];
    }

    private function names(string $class, ?string $client, ?string $version, ?string $userAgent = null): array
    {
        $protocol = new $class(['uuid' => 'uuid-1'], $this->servers(), $client, $version, $userAgent);
        $prop = new \ReflectionProperty($protocol, 'servers');
        $prop->setAccessible(true);

        return array_column($prop->getValue($protocol), 'name');
    }

    public function test_supported_clients_keep_encrypted_node(): void
    {
        $this->assertContains('enc', $this->names(General::class, 'v2rayn', '7.15.0'));
        $this->assertContains('enc', $this->names(General::class, 'v2rayng', null));
        $this->assertContains('enc', $this->names(ClashMeta::class, 'mihomo', '1.19.14'));
        $this->assertContains('enc', $this->names(ClashMeta::class, 'meta', '1.19.33'));
        $this->assertContains('enc', $this->names(ClashMeta::class, 'verge', '2.4.3'));
        $this->assertContains('enc', $this->names(ClashMeta::class, 'flclash', '0.8.89'));
        $this->assertContains('enc', $this->names(ClashMeta::class, 'flclash', '0.9.3'));
        $this->assertContains('enc', $this->names(Shadowrocket::class, 'shadowrocket', '3191'));
        $this->assertContains('enc', $this->names(Shadowrocket::class, 'shadowrocket', '3220'));
        $this->assertContains('enc', $this->names(Stash::class, 'stash', '3.6.1'));
        $this->assertContains('enc', $this->names(Stash::class, 'stash', '3.6.5'));
    }

    /**
     * 线上 Clash Verge 的 UA 是 clash-verge/v2.5.10，客户端名会被识别成 clash（见 ClientController::getClientInfo）。
     */
    public function test_clash_verge_user_agent_is_recognized(): void
    {
        $this->assertContains('enc', $this->names(ClashMeta::class, 'clash', '2.5.10', 'clash-verge/v2.5.10'));
        $this->assertContains('enc', $this->names(ClashMeta::class, 'clash', '2.4.3', 'clash-verge/v2.4.3'));
        $this->assertNotContains('enc', $this->names(ClashMeta::class, 'clash', '2.4.2', 'clash-verge/v2.4.2'));
        $this->assertNotContains('enc', $this->names(ClashMeta::class, 'clash', '1.6.0', 'clash-verge/v1.6.0'));
        $this->assertNotContains('enc', $this->names(ClashMeta::class, 'clash', null, 'clash-verge'));
        // 不是 Verge 的 clash 不受影响
        $this->assertNotContains('enc', $this->names(ClashMeta::class, 'clash', '2.5.10', 'clash.meta'));
        // FlClash 的 UA 里也带 clash-verge，但客户端名已识别成 flclash，按 FlClash 自己的版本判断
        $this->assertNotContains('enc', $this->names(ClashMeta::class, 'flclash', '0.8.88', 'flclash/v0.8.88 clash-verge platform/windows'));
        $this->assertContains('enc', $this->names(ClashMeta::class, 'flclash', '0.8.92', 'flclash/v0.8.92 clash-verge platform/windows'));
    }

    public function test_stash_encrypted_node_keeps_flow(): void
    {
        $stash = new Stash(['uuid' => 'uuid-1'], [], 'stash', '3.6.1');
        $node = $stash->buildVless('uuid-1', [
            'name' => 'enc',
            'host' => 'node.example.com',
            'port' => 443,
            'protocol_settings' => [
                'tls' => 0,
                'flow' => 'xtls-rprx-vision',
                'network' => 'tcp',
                'encryption' => 'mlkem768x25519plus',
                'encryption_settings' => ['mode' => 'native', 'rtt' => '0rtt', 'password' => 'pub'],
            ],
        ]);

        $this->assertSame('xtls-rprx-vision', $node['flow'] ?? null);
        $this->assertSame('mlkem768x25519plus.native.0rtt.pub', $node['encryption']);
    }

    public function test_unsupported_or_old_clients_drop_only_encrypted_node(): void
    {
        $cases = [
            [SingBox::class, 'sing-box', '1.14.2'],
            [SingBox::class, 'hiddify', '5.5.5'],
            [ClashMeta::class, 'nekobox', '1.4.2'],
            [ClashMeta::class, 'flclash', '0.8.88'],
            [ClashMeta::class, 'flclash', '0.8.72'],
            [ClashMeta::class, 'flclash', null],
            [ClashMeta::class, 'mihomo', '1.19.13'],
            [ClashMeta::class, 'mihomo', null],
            [ClashMeta::class, 'verge', '2.4.2'],
            [General::class, null, null],
            [General::class, 'sagernet', '0.8'],
            [Shadowrocket::class, 'shadowrocket', '2592'],
            [Shadowrocket::class, 'shadowrocket', '3190'],
            [Shadowrocket::class, 'shadowrocket', null],
            [Stash::class, 'stash', '3.0'],
            [Stash::class, 'stash', '3.6.0'],
            [Stash::class, 'stash', null],
            [Loon::class, 'loon', '900'],
            [QuantumultX::class, 'quantumult%20x', '1.5'],
        ];
        foreach ($cases as [$class, $client, $version]) {
            $names = $this->names($class, $client, $version);
            $label = $class . ' ' . $client . '/' . $version;
            $this->assertNotContains('enc', $names, $label);
            $this->assertContains('plain', $names, $label);
            $this->assertContains('none', $names, $label);
        }
    }
}
