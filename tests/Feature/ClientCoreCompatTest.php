<?php

namespace Tests\Feature;

use App\Protocols\ClashMeta;
use App\Protocols\SingBox;
use Tests\TestCase;

/**
 * 新内核的弃用项迁移（2026-10）。
 *
 * sing-box：
 *   · 1.14 起 rule_set.download_detour 弃用（1.14 警告、1.15 起不设环境变量直接 FATAL、1.16 删除），
 *     改为顶层 http_clients + rule_set.http_client；
 *   · 1.15 起 TUN stack 弃用（1.17 删除），删掉即用 sing-tun 自带协议栈。
 * mihomo：顶层 global-client-fingerprint 已移除（加载报 error 且不再生效），下放到 proxy 级 client-fingerprint。
 *
 * 老内核不认 http_clients（未知字段整份拒载），所以门槛不能放宽；测试版 1.15.0-alpha.N
 * 必须按 1.15 处理（version_compare 会把它判成 < 1.15.0）。
 */
class ClientCoreCompatTest extends TestCase
{
    private function adaptSingBox(array $config, ?string $clientVersion, ?string $userAgent): array
    {
        $protocol = new SingBox([], [], 'sing-box', $clientVersion, $userAgent);
        $prop = new \ReflectionProperty($protocol, 'config');
        $prop->setAccessible(true);
        $prop->setValue($protocol, $config);
        $method = new \ReflectionMethod($protocol, 'adaptConfigForVersion');
        $method->setAccessible(true);
        $method->invoke($protocol);

        return $prop->getValue($protocol);
    }

    private function singBoxTemplate(): array
    {
        return [
            'inbounds' => [
                ['type' => 'tun', 'tag' => 'tun-in', 'stack' => 'system', 'address' => ['172.19.0.1/30']],
                ['type' => 'mixed', 'tag' => 'mixed-in', 'listen' => '127.0.0.1', 'listen_port' => 2080],
            ],
            'outbounds' => [['type' => 'direct', 'tag' => 'direct'], ['type' => 'selector', 'tag' => 'proxy', 'outbounds' => ['direct']]],
            'dns' => ['servers' => [['tag' => 'local', 'type' => 'udp', 'server' => '223.5.5.5']]],
            'route' => [
                'final' => 'proxy',
                'rules' => [],
                'rule_set' => [
                    ['tag' => 'geosite-cn', 'type' => 'remote', 'format' => 'binary', 'url' => 'https://example.com/cn.srs', 'download_detour' => 'direct'],
                    ['tag' => 'geosite-x', 'type' => 'remote', 'format' => 'binary', 'url' => 'https://example.com/x.srs', 'download_detour' => 'proxy'],
                    ['tag' => 'inline-y', 'type' => 'inline', 'rules' => [['domain' => ['a.example']]]],
                ],
            ],
        ];
    }

    public function test_singbox_115_alpha_migrates_download_detour_and_drops_tun_stack(): void
    {
        $out = $this->adaptSingBox($this->singBoxTemplate(), '1.15.0', 'SFA/1.15.0-alpha.10 (Android 14; sing-box 1.15.0-alpha.10)');

        foreach ($out['route']['rule_set'] as $ruleSet) {
            $this->assertArrayNotHasKey('download_detour', $ruleSet);
        }
        $this->assertSame('direct', $out['route']['rule_set'][0]['http_client']);
        $this->assertSame('via-proxy', $out['route']['rule_set'][1]['http_client']);
        $this->assertArrayNotHasKey('http_client', $out['route']['rule_set'][2]);
        // direct 客户端不带 detour：detour 指向空 direct 出站在新内核上是致命错误
        $this->assertSame([['tag' => 'direct'], ['tag' => 'via-proxy', 'detour' => 'proxy']], $out['http_clients']);
        $this->assertSame('direct', $out['route']['default_http_client']);
        $this->assertArrayNotHasKey('stack', $out['inbounds'][0]);
    }

    public function test_prerelease_client_version_is_treated_as_its_release(): void
    {
        // 走 clientVersion 分支（UA 里没有 "sing-box x.y.z"）时也要剥掉 -alpha 后缀
        $out = $this->adaptSingBox($this->singBoxTemplate(), '1.15.0-alpha.10', 'SFA/1.15.0-alpha.10');

        $this->assertArrayNotHasKey('stack', $out['inbounds'][0]);
        $this->assertArrayHasKey('http_clients', $out);
    }

    public function test_singbox_114_migrates_download_detour_but_keeps_tun_stack(): void
    {
        $out = $this->adaptSingBox($this->singBoxTemplate(), '1.14.2', 'sing-box 1.14.2');

        $this->assertArrayHasKey('http_clients', $out);
        $this->assertArrayNotHasKey('download_detour', $out['route']['rule_set'][0]);
        $this->assertSame('system', $out['inbounds'][0]['stack']);
    }

    public function test_singbox_113_and_older_are_untouched(): void
    {
        foreach (['sing-box 1.13.21', 'sing-box 1.11.15'] as $ua) {
            $out = $this->adaptSingBox($this->singBoxTemplate(), null, $ua);

            $this->assertArrayNotHasKey('http_clients', $out, $ua);
            $this->assertSame('direct', $out['route']['rule_set'][0]['download_detour'], $ua);
            $this->assertSame('system', $out['inbounds'][0]['stack'], $ua);
        }
    }

    public function test_rule_set_without_detour_keeps_old_default_outbound_semantics(): void
    {
        $config = $this->singBoxTemplate();
        unset($config['route']['rule_set'][0]['download_detour']);

        $out = $this->adaptSingBox($config, null, 'sing-box 1.14.2');

        // 旧语义：没写 download_detour = 走默认出站（route.final）
        $this->assertSame('via-proxy', $out['route']['rule_set'][0]['http_client']);
    }

    private function moveFingerprint(array $config): array
    {
        $method = new \ReflectionMethod(ClashMeta::class, 'moveGlobalFingerprintToProxies');
        $method->setAccessible(true);
        $method->invokeArgs(null, [&$config]);

        return $config;
    }

    public function test_mihomo_global_fingerprint_is_moved_onto_tls_proxies(): void
    {
        $out = $this->moveFingerprint([
            'global-client-fingerprint' => 'chrome',
            'proxies' => [
                ['name' => 'cf', 'type' => 'vless', 'tls' => true],
                ['name' => 'reality', 'type' => 'vless', 'tls' => true, 'client-fingerprint' => 'firefox'],
                ['name' => 'plain', 'type' => 'vless', 'tls' => false],
                ['name' => 'vm', 'type' => 'vmess', 'tls' => true],
                ['name' => 'tj', 'type' => 'trojan'],
                ['name' => 'hy2', 'type' => 'hysteria2'],
                ['name' => 'ss', 'type' => 'ss'],
            ],
        ]);

        $this->assertArrayNotHasKey('global-client-fingerprint', $out);
        $fp = array_column($out['proxies'], 'client-fingerprint', 'name');
        $this->assertSame(['cf' => 'chrome', 'reality' => 'firefox', 'vm' => 'chrome', 'tj' => 'chrome'], $fp);
    }

    public function test_mihomo_without_global_fingerprint_adds_nothing(): void
    {
        $out = $this->moveFingerprint(['proxies' => [['name' => 'cf', 'type' => 'vless', 'tls' => true]]]);

        $this->assertArrayNotHasKey('client-fingerprint', $out['proxies'][0]);
        $this->assertArrayNotHasKey('global-client-fingerprint', $out);
    }
}
