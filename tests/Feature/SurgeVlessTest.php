<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Protocols\Surge;
use Tests\TestCase;

/**
 * Surge 6 新功能下发：VLESS 节点 + hysteria2 端口跳跃 / Salamander / 证书指纹。
 *
 * 官方手册标注「VLESS iOS 6.0.0+ Mac 6.12.0+」，两端共用的内核是 SurgeCore 6.12.0（UA 里的 SurgeCore/6012000）。
 * 旧版 Surge 读到 `= vless` 会让整份托管配置报错，所以只给 UA 里能读出达标版本的 Surge。
 */
class SurgeVlessTest extends TestCase
{
    private const UUID = '11111111-2222-3333-4444-555555555555';
    private const PBK = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
    private const PIN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private function vless(array $ps, string $name = 'vless-node'): array
    {
        return [
            'id' => 1,
            'name' => $name,
            'host' => 'node.example.com',
            'port' => 443,
            'type' => Server::TYPE_VLESS,
            'password' => self::UUID,
            'protocol_settings' => $ps,
        ];
    }

    private function reality(array $extra = []): array
    {
        return array_merge([
            'tls' => 2,
            'flow' => 'xtls-rprx-vision',
            'network' => 'tcp',
            'network_settings' => ['acceptProxyProtocol' => true],
            'reality_settings' => [
                'server_name' => 'sni.example.com',
                'server_port' => '443',
                'public_key' => self::PBK,
                'private_key' => 'must-never-leak',
                'short_id' => '0123abcd',
                'allow_insecure' => false,
            ],
            'utls' => ['enabled' => true, 'fingerprint' => 'chrome'],
        ], $extra);
    }

    private function wsTls(array $extra = []): array
    {
        return array_merge([
            'tls' => 1,
            'flow' => 'none',
            'network' => 'ws',
            'network_settings' => ['path' => '/ws', 'headers' => ['Host' => 'cdn.example.com']],
            'tls_settings' => [
                'server_name' => 'cdn.example.com',
                'allow_insecure' => false,
                'ech' => ['enabled' => true, 'type' => 'cloudflare'],
            ],
        ], $extra);
    }

    private function hy2(array $tls = [], array $obfs = ['open' => false], ?string $ports = null): array
    {
        $server = [
            'id' => 2,
            'name' => 'hy2-node',
            'host' => 'hy.example.com',
            'port' => 53123,
            'type' => Server::TYPE_HYSTERIA,
            'password' => self::UUID,
            'protocol_settings' => [
                'version' => 2,
                'bandwidth' => ['up' => 500, 'down' => 500],
                'obfs' => $obfs + ['type' => 'salamander', 'password' => null],
                'tls' => $tls + ['server_name' => 'www.bing.com', 'allow_insecure' => false],
                'hop_interval' => null,
            ],
        ];
        if ($ports !== null) {
            $server['ports'] = $ports;
        }
        return $server;
    }

    private function surge6(string $ua): bool
    {
        $protocol = new Surge(['uuid' => self::UUID], [], 'surge', null, strtolower($ua));
        $m = new \ReflectionMethod($protocol, 'supportsSurge6Features');
        $m->setAccessible(true);
        return $m->invoke($protocol);
    }

    // ---------- 版本判断 ----------

    public function test_surge6_detection_follows_surgecore_version(): void
    {
        // 带 SurgeCore 段：按内核版本，平台通用（真实 UA 取自现网访问日志）
        $this->assertTrue($this->surge6('Surge Mac/12900 SurgeCore/6012000'));
        $this->assertTrue($this->surge6('Surge iOS/4037 SurgeCore/6012000'), 'iOS 6 + 6.12 内核');
        $this->assertFalse($this->surge6('Surge iOS/4024 SurgeCore/6011000'), '6.11 内核还没有 VLESS');
        $this->assertFalse($this->surge6('Surge Mac/12800 SurgeCore/6011000'));
        $this->assertFalse($this->surge6('Surge tvOS/3869 SurgeCore/6010000'));
        // 不带 SurgeCore 段：只认 Mac 构建号
        $this->assertTrue($this->surge6('Surge Mac/12910'));
        $this->assertFalse($this->surge6('Surge Mac/12420'));
        $this->assertFalse($this->surge6('Surge iOS/99999'), 'iOS 构建号对不上版本，不能猜');
        $this->assertFalse($this->surge6('surge'), '?flag=surge 手动指定，读不到版本');
        $this->assertFalse($this->surge6(''));
    }

    public function test_vless_is_not_delivered_to_old_surge_but_kept_for_surge6(): void
    {
        $servers = [$this->vless($this->reality())];
        // 基类过滤后 VLESS 仍保留（交给 handle 判断），旧版在 handle 里跳过
        $old = new Surge(['uuid' => self::UUID], $servers, 'surge', '3857', 'surge ios/3857');
        $prop = new \ReflectionProperty($old, 'servers');
        $prop->setAccessible(true);
        $this->assertCount(1, $prop->getValue($old));
    }

    // ---------- VLESS 各组合 ----------

    public function test_reality_vision_line(): void
    {
        $line = Surge::buildVless(self::UUID, $this->vless($this->reality()));
        $this->assertSame(
            'vless-node = vless,node.example.com,443,username=' . self::UUID
            . ',flow=xtls-rprx-vision,sni=sni.example.com,reality-public-key=' . self::PBK
            . ',reality-short-id=0123abcd,tfo=true' . "\r\n",
            $line
        );
        $this->assertStringNotContainsString('must-never-leak', $line, '私钥绝不能下发');
        $this->assertStringNotContainsString('tls=true', $line, 'REALITY 隐含 TLS');
        $this->assertStringNotContainsString('skip-cert-verify', $line);
    }

    public function test_ws_tls_line_drops_cloudflare_ech(): void
    {
        $line = Surge::buildVless(self::UUID, $this->vless($this->wsTls()));
        $this->assertSame(
            'vless-node = vless,node.example.com,443,username=' . self::UUID
            . ',ws=true,ws-path=/ws,ws-headers=Host:cdn.example.com,tls=true,sni=cdn.example.com,tfo=true' . "\r\n",
            $line
        );
        $this->assertStringNotContainsString('flow=', $line, 'flow=none 不能写出来');
        $this->assertStringNotContainsString('ech', $line);
    }

    public function test_tls_pin_and_insecure(): void
    {
        $pinned = $this->wsTls(['tls_settings' => ['server_name' => 'a.example.com', 'pinned_peer_cert_sha256' => strtoupper(self::PIN)]]);
        $this->assertStringContainsString(',server-cert-fingerprint-sha256=' . self::PIN, Surge::buildVless(self::UUID, $this->vless($pinned)));

        $insecure = $this->wsTls(['tls_settings' => ['server_name' => 'a.example.com', 'allow_insecure' => true, 'pinned_peer_cert_sha256' => self::PIN]]);
        $line = Surge::buildVless(self::UUID, $this->vless($insecure));
        $this->assertStringContainsString(',skip-cert-verify=true', $line);
        $this->assertStringNotContainsString('fingerprint', $line);
    }

    public function test_other_transports(): void
    {
        $grpc = Surge::buildVless(self::UUID, $this->vless(['tls' => 1, 'network' => 'grpc', 'network_settings' => ['serviceName' => 'svc'], 'tls_settings' => ['server_name' => 'g.example.com']]));
        $this->assertStringContainsString(',grpc=true,grpc-service-name=svc,tls=true,sni=g.example.com', $grpc);

        $xhttp = Surge::buildVless(self::UUID, $this->vless(['tls' => 0, 'network' => 'xhttp', 'network_settings' => ['path' => '/x', 'host' => 'h.example.com', 'mode' => 'packet-up']]));
        $this->assertStringContainsString(',xhttp=true,xhttp-path=/x,xhttp-host=h.example.com,xhttp-mode=packet-up', $xhttp);
        $this->assertStringNotContainsString('tls=', $xhttp);

        $upgrade = Surge::buildVless(self::UUID, $this->vless(['tls' => 1, 'network' => 'httpupgrade', 'network_settings' => ['path' => '/up', 'host' => 'u.example.com'], 'tls_settings' => ['server_name' => 'u.example.com']]));
        $this->assertStringContainsString(',ws=true,ws-http-upgrade=true,ws-path=/up,ws-headers=Host:u.example.com,tls=true', $upgrade);

        $plain = Surge::buildVless(self::UUID, $this->vless(['tls' => 0, 'network' => 'tcp', 'flow' => 'none']));
        $this->assertSame('vless-node = vless,node.example.com,443,username=' . self::UUID . ",tfo=true\r\n", $plain);
    }

    public function test_vless_encryption(): void
    {
        $line = Surge::buildVless(self::UUID, $this->vless([
            'tls' => 0,
            'network' => 'tcp',
            'flow' => 'xtls-rprx-vision',
            'encryption' => 'mlkem768x25519plus',
            'encryption_settings' => ['mode' => 'native', 'rtt' => '0rtt', 'password' => 'clientkey'],
        ]));
        $this->assertStringContainsString(',flow=xtls-rprx-vision,encryption=mlkem768x25519plus.native.0rtt.clientkey', $line);
    }

    public function test_unsupported_combinations_are_skipped(): void
    {
        $cases = [
            'h2' => ['tls' => 1, 'network' => 'h2'],
            'kcp' => ['tls' => 0, 'network' => 'kcp'],
            'tcp+http 伪装' => ['tls' => 0, 'network' => 'tcp', 'network_settings' => ['header' => ['type' => 'http']]],
            'ws+REALITY' => $this->reality(['network' => 'ws', 'flow' => 'none']),
            'gRPC 无 TLS' => ['tls' => 0, 'network' => 'grpc'],
            'vision 搭 ws' => $this->wsTls(['flow' => 'xtls-rprx-vision']),
            'vision 无 TLS 无加密' => ['tls' => 0, 'network' => 'tcp', 'flow' => 'xtls-rprx-vision'],
            'REALITY 缺公钥' => $this->reality(['reality_settings' => ['server_name' => 's.example.com', 'public_key' => '']]),
            '未知 flow' => $this->reality(['flow' => 'xtls-rprx-direct']),
        ];
        foreach ($cases as $label => $ps) {
            $this->assertSame('', Surge::buildVless(self::UUID, $this->vless($ps)), $label);
        }
    }

    // ---------- hysteria2 ----------

    public function test_hysteria2_old_surge_unchanged(): void
    {
        // 旧版：端口范围不写 port-hopping，有指纹只能跳过校验（与改动前一致）
        $line = Surge::buildHysteria('pw', $this->hy2(['pinned_peer_cert_sha256' => self::PIN], ['open' => false], '53000-54000'));
        $this->assertSame(
            'hy2-node = hysteria2,hy.example.com,53123,password=pw,sni=www.bing.com,udp-relay=true,upload-bandwidth=500,download-bandwidth=500,skip-cert-verify=true' . "\r\n",
            $line
        );
    }

    public function test_hysteria2_surge6_port_hopping_and_pin(): void
    {
        $line = Surge::buildHysteria('pw', $this->hy2(['pinned_peer_cert_sha256' => self::PIN], ['open' => false], '53000-54000'), true);
        $this->assertStringContainsString(',port-hopping="53000-54000"', $line);
        $this->assertStringContainsString(',server-cert-fingerprint-sha256=' . self::PIN, $line);
        $this->assertStringNotContainsString('skip-cert-verify', $line, '有指纹时不应再关掉校验');
    }

    public function test_hysteria2_salamander(): void
    {
        $obfs = ['open' => true, 'type' => 'salamander', 'password' => 'obfspw'];
        $this->assertSame('', Surge::buildHysteria('pw', $this->hy2([], $obfs)), '旧版写不出混淆，不发');
        $this->assertStringContainsString(',salamander-password="obfspw"', Surge::buildHysteria('pw', $this->hy2([], $obfs), true));
    }
}
