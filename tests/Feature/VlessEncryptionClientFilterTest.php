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
 * 开了 VLESS Encryption 的节点按黑名单下发：只有明确不支持的客户端不发。
 *
 *   · 官方 sing-box 遇到未知字段 encryption 会拒绝整份配置，整个格式不发；
 *   · mihomo 1.19.13 是早期格式，会整份报错，内核是它的客户端版本必须拦；
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

    /**
     * [下发格式, 识别出的客户端名, 版本, 原始 UA]，UA 取自线上订阅日志
     */
    private function delivered(): array
    {
        return [
            [ClashMeta::class, 'clash', '2.5.10', 'clash-verge/v2.5.10'],
            [ClashMeta::class, 'clash', '2.4.3', 'clash-verge/v2.4.3'],
            [ClashMeta::class, 'clash', '2.5.8', 'clash-verge/v2.5.8+autobuild.1214.195f470'],
            [ClashMeta::class, 'clash', null, 'clash-verge'],
            [ClashMeta::class, 'flclash', '0.8.89', 'flclash/v0.8.89 clash-verge platform/android'],
            [ClashMeta::class, 'flclash', '0.9.3', 'flclash/v0.9.3 clash-verge platform/windows'],
            [ClashMeta::class, 'clashmetaforandroid', '2.11.17', 'clashmetaforandroid/2.11.17.meta'],
            [ClashMeta::class, 'clashmetaforandroid', '2.11.28', 'clashmetaforandroid/2.11.28.meta'],
            [ClashMeta::class, 'mihomo', '2.0.4', 'mihomo.party/v2.0.4 (clash.meta)'],
            [ClashMeta::class, 'mihomo', '1.8.8', 'mihomo.party/v1.8.8 (clash.meta)'],
            [ClashMeta::class, 'mihomo', '1.19.27', 'clashmeta/1.19.27; mihomo/1.19.27'],
            [ClashMeta::class, 'meta', '1.19.14', 'clash.meta/v1.19.14'],
            [ClashMeta::class, 'mihomo', '1.19.33', 'mihomo/1.19.33'],
            [ClashMeta::class, 'clash', null, 'clash.meta'],
            [ClashMeta::class, 'clash', null, 'clash.meta/alpha-2d94970'],
            [General::class, 'v2rayn', '7.15.0', 'v2rayn/7.15.0'],
            [General::class, 'v2rayng', null, 'v2rayng'],
            [General::class, null, null, 'v2raytun/android'],
            [General::class, null, '2.21.0', 'egern/2.21.0 (ios 27.2; build/788)'],
            [General::class, null, null, null],
            [Shadowrocket::class, 'shadowrocket', '3191', 'shadowrocket/3191'],
            [Shadowrocket::class, 'shadowrocket', '3220', 'shadowrocket/3220'],
            [Shadowrocket::class, 'shadowrocket', null, 'shadowrocket'],
            [Stash::class, 'stash', '3.6.1', 'stash/3.6.1'],
            [Stash::class, 'stash', null, 'stash'],
        ];
    }

    private function dropped(): array
    {
        return [
            [ClashMeta::class, 'clash', '2.4.2', 'clash-verge/v2.4.2'],
            [ClashMeta::class, 'clash', '1.6.0', 'clash-verge/v1.6.0 mozilla/5.0 chrome/124'],
            [ClashMeta::class, 'flclash', '0.8.88', 'flclash/v0.8.88 clash-verge platform/android'],
            [ClashMeta::class, 'verge', '0.8.61', 'flc/0.8.61/meta/verge/android 16'],
            [ClashMeta::class, 'clashmetaforandroid', '2.11.16', 'clashmetaforandroid/2.11.16.meta'],
            [ClashMeta::class, 'mihomo', '1.8.7', 'mihomo.party/v1.8.7 (clash.meta)'],
            [ClashMeta::class, 'meta', '1.19.13', 'clash.meta/v1.19.13'],
            [ClashMeta::class, 'mihomo', '1.19.13', 'mihomo/1.19.13'],
            [ClashMeta::class, 'nekobox', '1.4.2', 'nekobox/android/1.4.2 (prefer clashmeta format)'],
            [ClashMeta::class, 'flclash', '1.2.23', 'karing/1.2.23.2606 platform/android;mihomo/1.19.28;clashmeta;clash-verge'],
            [General::class, 'sagernet', '0.8', 'sagernet/0.8'],
            [General::class, null, '1.2.23', 'karing/1.2.23.2606 platform/android'],
            [SingBox::class, 'sing-box', '1.14.2', 'sing-box/1.14.2'],
            [SingBox::class, 'hiddify', '5.5.5', 'hiddify/5.5.5'],
            [SingBox::class, 'sing-box', '4.0.0', 'hiddifynext/4.0.0 (ios) like clashmeta v2ray sing-box'],
            [Shadowrocket::class, 'shadowrocket', '3190', 'shadowrocket/3190'],
            [Stash::class, 'stash', '3.6.0', 'stash/3.6.0'],
            [Loon::class, 'loon', '900', 'loon/900'],
            [QuantumultX::class, 'quantumult%20x', '1.5', 'quantumult%20x/1.5'],
        ];
    }

    public function test_clients_not_blacklisted_get_encrypted_node(): void
    {
        foreach ($this->delivered() as [$class, $client, $version, $ua]) {
            $this->assertContains('enc', $this->names($class, $client, $version, $ua), class_basename($class) . ' ' . $ua);
        }
    }

    public function test_blacklisted_clients_drop_only_encrypted_node(): void
    {
        foreach ($this->dropped() as [$class, $client, $version, $ua]) {
            $names = $this->names($class, $client, $version, $ua);
            $label = class_basename($class) . ' ' . $ua;
            $this->assertNotContains('enc', $names, $label);
            $this->assertContains('plain', $names, $label);
            $this->assertContains('none', $names, $label);
        }
    }

    /**
     * 用线上真实 UA 走一遍 ClientController 的识别和格式匹配，确认上面手填的客户端名、版本没写错
     */
    public function test_real_user_agents_through_client_detection(): void
    {
        $controller = app(\App\Http\Controllers\V1\Client\ClientController::class);
        $detect = new \ReflectionMethod($controller, 'getClientInfo');
        $cases = [
            'clash-verge/v2.5.10' => true,
            'clash-verge/v2.4.2' => false,
            'FlClash/v0.8.92 clash-verge Platform/windows' => true,
            'FlClash/v0.8.88 clash-verge Platform/android' => false,
            'ClashMetaForAndroid/2.11.28.Meta' => true,
            'ClashMetaForAndroid/2.11.16.Meta' => false,
            'mihomo.party/v2.0.4 (clash.meta)' => true,
            'mihomo.party/v1.8.7 (clash.meta)' => false,
            'clash.meta/v1.19.33' => true,
            'clash.meta/v1.19.13' => false,
            'ClashMeta/1.19.27; mihomo/1.19.27' => true,
            'NekoBox/Android/1.4.2 (Prefer ClashMeta Format)' => false,
            'Karing/1.2.23.2606 platform/android;mihomo/1.19.28;ClashMeta;clash-verge;NekoBox/Android/1.4.1 (Prefer ClashMeta Format);HiddifyNext;v2ray;FLClash' => false,
            'HiddifyNext/4.0.0 (ios) like ClashMeta v2ray sing-box' => false,
            'Shadowrocket/3191' => true,
            'Stash/3.6.1' => true,
            'v2rayN/7.15.0' => true,
        ];
        foreach ($cases as $ua => $expected) {
            $request = \Illuminate\Http\Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => $ua]);
            $info = $detect->invoke($controller, $request);
            $class = app('protocols.manager')->matchProtocolClassName($info['flag']) ?? General::class;
            $names = $this->names($class, $info['name'] ?? null, $info['version'] ?? null, $info['flag']);
            $this->assertSame($expected, in_array('enc', $names, true), $ua . ' → ' . class_basename($class));
        }
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
}
