<?php

namespace Tests\Feature;

use App\Protocols\ClashMeta;
use App\Protocols\General;
use App\Protocols\Loon;
use App\Protocols\Shadowrocket;
use App\Protocols\SingBox;
use App\Protocols\Stash;
use App\Protocols\Surge;
use App\Support\CertPinHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Hysteria2 证书指纹（pinned_peer_cert_sha256）下发。
 *
 * 背景：cert_mode=remote 时证书是面板自签的，任何客户端的证书链校验都必然失败。
 * 能钉指纹的客户端靠指纹完成校验，不能钉的只能跳过链校验——两者都不给的话，
 * 节点在那个客户端上直接连不上。2026-09 之前 Shadowrocket / Surge / Loon 就是
 * 只读 allow_insecure，管理员一关这个开关，带指纹的 hy2 节点在这三家全挂。
 *
 * 所以这里锁住一条跨客户端的不变量：**有指纹 ⇒ 输出里必须跳过链校验**。
 */
class HysteriaCertPinTest extends TestCase
{
    /** sha256(证书 DER) 的样子：64 位 hex */
    private const PIN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private function server(bool $withPin, bool $allowInsecure = false, int $version = 2): array
    {
        $tls = [
            'server_name' => 'www.example.com',
            'allow_insecure' => $allowInsecure,
        ];
        if ($withPin) {
            $tls['pinned_peer_cert_sha256'] = self::PIN;
            $tls['pinned_public_key_sha256'] = self::PIN;
        }

        return [
            'id' => 1,
            'name' => 'hy2-node',
            'host' => '1.2.3.4',
            'port' => 443,
            'type' => 'hysteria',
            'protocol_settings' => [
                'version' => $version,
                'tls' => $tls,
                'bandwidth' => ['up' => 100, 'down' => 200],
            ],
        ];
    }

    /**
     * 每个生成器：有指纹时输出必须命中「跳过链校验」的标记。
     *
     * @return array<string, array{0: callable, 1: string}>
     */
    public static function generators(): array
    {
        return [
            '通用 URI (v2rayN / 官方客户端)' => [
                fn(array $s) => General::buildHysteria('pw', $s),
                'insecure=1',
            ],
            'Shadowrocket (小火箭)' => [
                fn(array $s) => Shadowrocket::buildHysteria('pw', $s),
                'insecure=1',
            ],
            'Surge' => [
                fn(array $s) => Surge::buildHysteria('pw', $s),
                'skip-cert-verify=true',
            ],
            'Loon' => [
                fn(array $s) => Loon::buildHysteria('pw', $s, []),
                'skip-cert-verify=true',
            ],
            'ClashMeta (mihomo)' => [
                fn(array $s) => json_encode(ClashMeta::buildHysteria('pw', $s, [])),
                '"skip-cert-verify":true',
            ],
            'Stash' => [
                fn(array $s) => json_encode(Stash::buildHysteria('pw', $s)),
                '"skip-cert-verify":true',
            ],
        ];
    }

    #[DataProvider('generators')]
    public function test_pinned_node_always_skips_chain_verification(callable $build, string $needle): void
    {
        // 关键场景：管理员把 allow_insecure 关着（默认值），只配了指纹
        $output = $build($this->server(withPin: true, allowInsecure: false));

        $this->assertStringContainsString(
            $needle,
            (string) $output,
            "有指纹时必须跳过链校验，否则自签证书的节点在该客户端上连不上：\n{$output}"
        );
    }

    #[DataProvider('generators')]
    public function test_unpinned_node_respects_admin_switch(callable $build, string $needle): void
    {
        // 没指纹（真证书）且管理员没开 → 不该擅自跳过校验
        $output = (string) $build($this->server(withPin: false, allowInsecure: false));

        $this->assertStringNotContainsString($needle, $output, "没有指纹时不该擅自跳过证书校验：\n{$output}");
    }

    /** 支持指纹固定的客户端要真的收到指纹，而不是只跳过校验 */
    public function test_pin_is_delivered_to_clients_that_support_it(): void
    {
        $server = $this->server(withPin: true);

        $uri = General::buildHysteria('pw', $server);
        $this->assertStringContainsString('pinSHA256=' . self::PIN, $uri);
        // xray-core 没有 hysteria2 出站，pcs 塞进 hy2 链接是冗余参数
        $this->assertStringNotContainsString('pcs=', $uri);

        $this->assertSame(self::PIN, ClashMeta::buildHysteria('pw', $server, [])['fingerprint']);
        $this->assertSame(self::PIN, Stash::buildHysteria('pw', $server)['fingerprint']);
    }

    /** hysteria v1 出站没有 fingerprint 字段，写了无效；但仍要跳过链校验 */
    public function test_hysteria_v1_gets_no_fingerprint_but_still_skips_verify(): void
    {
        $server = $this->server(withPin: true, version: 1);

        $clash = ClashMeta::buildHysteria('pw', $server, []);
        $this->assertArrayNotHasKey('fingerprint', $clash);
        $this->assertTrue($clash['skip-cert-verify']);

        $stash = Stash::buildHysteria('pw', $server);
        $this->assertArrayNotHasKey('fingerprint', $stash);
        $this->assertTrue($stash['skip-cert-verify']);

        // v1 的官方客户端不支持 pinSHA256，只保留 insecure
        $uri = General::buildHysteria('pw', $server);
        $this->assertStringNotContainsString('pinSHA256', $uri);
        $this->assertStringContainsString('insecure=1', $uri);
    }

    /** sing-box 用的是公钥 SPKI 哈希且要 base64，hex 直发会被静默解错成 48 字节 */
    public function test_singbox_pin_is_base64_public_key_hash(): void
    {
        $protocol = new SingBox([], [], 'sing-box', '1.13.0', 'sing-box/1.13.0');
        $method = new \ReflectionMethod($protocol, 'buildHysteria');
        $method->setAccessible(true);
        /** @var array $out */
        $out = $method->invoke($protocol, 'pw', $this->server(withPin: true));

        $this->assertSame(
            [base64_encode(hex2bin(self::PIN))],
            $out['tls']['certificate_public_key_sha256']
        );
        // pin 生效时 sing-box 自己置 InsecureSkipVerify，不应再单独写 insecure
        $this->assertArrayNotHasKey('insecure', $out['tls']);
    }

    /** 脏指纹（长度不对 / 非 hex）当成没有指纹处理，绝不下发一个永远比不上的哈希 */
    public function test_malformed_pin_is_ignored(): void
    {
        $this->assertSame(self::PIN, CertPinHelper::normalizePin(self::PIN));
        $this->assertSame(self::PIN, CertPinHelper::normalizePin(strtoupper(implode(':', str_split(self::PIN, 2)))));
        $this->assertNull(CertPinHelper::normalizePin('deadbeef'));
        $this->assertNull(CertPinHelper::normalizePin(str_repeat('z', 64)));
        $this->assertNull(CertPinHelper::normalizePin(null));

        $this->assertSame(base64_encode(hex2bin(self::PIN)), CertPinHelper::pinToBase64(self::PIN));
        $this->assertNull(CertPinHelper::pinToBase64('nope'));
    }
}
