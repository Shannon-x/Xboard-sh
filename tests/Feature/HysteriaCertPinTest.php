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
 * 背景：cert_mode=remote 时证书是面板自签的，客户端的证书链校验一定过不了。
 * 出路只有两条 —— **下发指纹**（客户端改用指纹校验），或者**跳过链校验**。
 * 一条都不给，节点在那个客户端上就是连不上；这正是 2026-09 之前
 * Shadowrocket / Surge / Loon 的状况（只读 allow_insecure，站长一关就全挂）。
 *
 * 「指纹要不要配合 insecure」是**按内核而异**的，不能一刀切：
 *   · hysteria 原生内核：pin 挂在 VerifyPeerCertificate 上，Go 的链校验失败会先中断握手，
 *     所以自签证书下必须 insecure=1 + pinSHA256 一起给。
 *   · Shadowrocket / mihomo / sing-box 1.13+ / 新版 Xray：pin **替代**链校验，
 *     再强开 insecure 反而可能让客户端跳过指纹校验。
 *   · Surge / Loon：压根没有指纹输入口，只能跳过校验。
 */
class HysteriaCertPinTest extends TestCase
{
    /** sha256(证书 DER) 的样子：64 位小写 hex */
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
     * 每个生成器的期望：指纹下发成什么样、是否额外跳过链校验。
     * 两者都为 null 是不允许的 —— 那意味着节点在该客户端上必然连不上。
     *
     * @return array<string, array{0: callable, 1: ?string, 2: ?string}>
     */
    public static function generators(): array
    {
        $pin = self::PIN;

        return [
            // 原生 hysteria 内核：指纹 + insecure 必须同时给
            '通用 URI (hysteria 原生内核)' => [
                fn(array $s) => General::buildHysteria('pw', $s),
                "pinSHA256={$pin}",
                'insecure=1',
            ],
            // 小火箭 TLS 设置页有 SHA256 指纹口，指纹替代链校验，不强开 insecure
            'Shadowrocket (小火箭)' => [
                fn(array $s) => Shadowrocket::buildHysteria('pw', $s),
                "pinSHA256={$pin}",
                null,
            ],
            // Surge / Loon 没有指纹输入口，只能跳过校验
            'Surge' => [
                fn(array $s) => Surge::buildHysteria('pw', $s),
                null,
                'skip-cert-verify=true',
            ],
            'Loon' => [
                fn(array $s) => Loon::buildHysteria('pw', $s, []),
                null,
                'skip-cert-verify=true',
            ],
            'ClashMeta (mihomo)' => [
                fn(array $s) => json_encode(ClashMeta::buildHysteria('pw', $s, [])),
                "\"fingerprint\":\"{$pin}\"",
                null,
            ],
            'Stash' => [
                fn(array $s) => json_encode(Stash::buildHysteria('pw', $s)),
                "\"fingerprint\":\"{$pin}\"",
                null,
            ],
        ];
    }

    /**
     * 核心不变量：有指纹（= 面板自签证书）时，每个客户端要么收到指纹、要么被放行跳过校验。
     * 两者皆无 = 该客户端上的节点必然连不上，这就是站长踩到的坑。
     */
    #[DataProvider('generators')]
    public function test_pinned_node_is_never_left_unconnectable(callable $build, ?string $pinNeedle, ?string $insecureNeedle): void
    {
        // 关键场景：站长把「允许不安全」关着（默认值），只配了指纹
        $output = (string) $build($this->server(withPin: true, allowInsecure: false));

        $hasPin = $pinNeedle !== null && str_contains($output, $pinNeedle);
        $skipsVerify = $insecureNeedle !== null && str_contains($output, $insecureNeedle);

        $this->assertTrue(
            $hasPin || $skipsVerify,
            "自签证书下既没下发指纹、也没跳过链校验，节点在该客户端上连不上：\n{$output}"
        );
    }

    /** 每个客户端具体该收到什么，逐个钉死 */
    #[DataProvider('generators')]
    public function test_each_client_gets_its_expected_form(callable $build, ?string $pinNeedle, ?string $insecureNeedle): void
    {
        $output = (string) $build($this->server(withPin: true, allowInsecure: false));

        if ($pinNeedle !== null) {
            $this->assertStringContainsString($pinNeedle, $output, "该客户端支持指纹，必须把指纹发下去：\n{$output}");
        }
        if ($insecureNeedle !== null) {
            $this->assertStringContainsString($insecureNeedle, $output, "该客户端需要跳过链校验：\n{$output}");
        }
    }

    /**
     * 支持指纹的客户端不该被强开 insecure —— 那可能让它跳过指纹校验，
     * 等于把站长开的安全特性悄悄关掉。
     */
    public function test_pin_capable_clients_are_not_forced_insecure(): void
    {
        $uri = (string) Shadowrocket::buildHysteria('pw', $this->server(withPin: true, allowInsecure: false));

        $this->assertStringContainsString('pinSHA256=' . self::PIN, $uri);
        $this->assertStringContainsString('insecure=0', $uri, '小火箭靠指纹校验，不该强开允许不安全');
    }

    /** 站长确实想跳过校验时，开关照常生效 */
    public function test_admin_switch_still_wins_when_turned_on(): void
    {
        $uri = (string) Shadowrocket::buildHysteria('pw', $this->server(withPin: true, allowInsecure: true));

        $this->assertStringContainsString('insecure=1', $uri);
    }

    /** 没有指纹（真证书）且站长没开 → 不该擅自跳过校验 */
    #[DataProvider('generators')]
    public function test_unpinned_node_respects_admin_switch(callable $build, ?string $pinNeedle, ?string $insecureNeedle): void
    {
        $output = (string) $build($this->server(withPin: false, allowInsecure: false));

        if ($pinNeedle !== null) {
            $this->assertStringNotContainsString($pinNeedle, $output, "没有指纹时不该凭空造一个：\n{$output}");
        }
        if ($insecureNeedle !== null) {
            $this->assertStringNotContainsString($insecureNeedle, $output, "没有指纹时不该擅自跳过证书校验：\n{$output}");
        }
    }

    /** 指纹必须是 64 位小写 hex：xray 系客户端拿到 base64 会直接报 encoding/hex: invalid byte */
    public function test_pin_is_lowercase_hex_not_base64(): void
    {
        $uri = (string) General::buildHysteria('pw', $this->server(withPin: true));

        $this->assertMatchesRegularExpression('/pinSHA256=[0-9a-f]{64}(&|$|#)/', $uri);
        // pcs 是 VLESS/Trojan 分享链接的约定，hysteria2 URI 规范里没有它
        $this->assertStringNotContainsString('pcs=', $uri);
    }

    /** hysteria v1 出站没有 fingerprint 字段，官方 v1 客户端也不支持 pinSHA256 */
    public function test_hysteria_v1_gets_no_fingerprint_but_still_connects(): void
    {
        $server = $this->server(withPin: true, version: 1);

        $clash = ClashMeta::buildHysteria('pw', $server, []);
        $this->assertArrayNotHasKey('fingerprint', $clash);
        $this->assertTrue($clash['skip-cert-verify']);

        $stash = Stash::buildHysteria('pw', $server);
        $this->assertArrayNotHasKey('fingerprint', $stash);
        $this->assertTrue($stash['skip-cert-verify']);

        $uri = (string) General::buildHysteria('pw', $server);
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
        // openssl x509 -fingerprint 输出的是大写带冒号，要能归一
        $this->assertSame(self::PIN, CertPinHelper::normalizePin(strtoupper(implode(':', str_split(self::PIN, 2)))));
        $this->assertNull(CertPinHelper::normalizePin('deadbeef'));
        $this->assertNull(CertPinHelper::normalizePin(str_repeat('z', 64)));
        $this->assertNull(CertPinHelper::normalizePin(null));

        $this->assertSame(base64_encode(hex2bin(self::PIN)), CertPinHelper::pinToBase64(self::PIN));
        $this->assertNull(CertPinHelper::pinToBase64('nope'));
    }
}
