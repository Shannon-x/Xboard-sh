<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Protocols\ClashMeta;
use App\Support\Setting;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Clash Mi（UA "ClashMeta/<内核版本>; mihomo/<内核版本>"）改发精简模板。
 *
 * iOS 只给 VPN 扩展约 50MB 内存，完整 clashmeta 模板在 mihomo 里要占 110MB 以上，
 * Clash Mi 一连就被系统杀掉。精简模板去掉规则集、嗅探和常驻的自动测速组，
 * 其它客户端（Clash Verge、FlClash、CMFA、Mihomo Party、Clash Plus、Karing……）照旧拿完整模板。
 */
class ClashMiLiteTemplateTest extends TestCase
{
    private function template(): array
    {
        return Yaml::parse(file_get_contents(base_path(ClashMeta::CLASH_MI_TEMPLATE_FILE)));
    }

    private function isClashMi(?string $userAgent): bool
    {
        $protocol = new ClashMeta([], [], 'mihomo', null, $userAgent);
        $method = new \ReflectionMethod($protocol, 'isClashMi');
        $method->setAccessible(true);

        return $method->invoke($protocol);
    }

    /**
     * 原始 UA 取自线上订阅日志（ClientController 传进来的是小写的）
     */
    public function test_only_clash_mi_user_agent_gets_the_lite_template(): void
    {
        $this->assertTrue($this->isClashMi('clashmeta/1.19.32; mihomo/1.19.32'));
        $this->assertTrue($this->isClashMi('ClashMeta/1.19.31; mihomo/1.19.31'));
        $this->assertTrue($this->isClashMi('clashmeta/1.19.33;mihomo/1.19.33'));

        foreach ([
            'clash-verge/v2.5.10',
            'clash-verge/mihomo',
            'flclash/v0.9.3 clash-verge platform/windows',
            'clashmetaforandroid/2.11.28.meta',
            'flclash/clashmetaforandroid/2.11.33.bettbox',
            'mihomo.party/v2.0.4 (clash.meta)',
            'mihomo/1.19.24',
            'mihomo/alpha-94b13ae',
            'clash.meta/v1.19.14',
            'clashmeta',
            'clashmeta/v1.4.1 clash plus/v1.4.1 platform/ios',
            'karing/1.2.25.2802 platform/ios;mihomo/1.19.28;clash-verge;flclash;mihomo.party/v2.0.0 (clash.meta);clashmeta',
            'nekobox/android/1.4.2 (prefer clashmeta format)',
            'hiddifynext/4.0.0 (ios) like clashmeta v2ray sing-box',
            '',
            null,
        ] as $userAgent) {
            $this->assertFalse($this->isClashMi($userAgent), "UA {$userAgent} 不该拿精简模板");
        }
    }

    public function test_lite_template_has_no_rule_providers_sniffer_or_eager_health_checks(): void
    {
        $config = $this->template();

        $this->assertArrayNotHasKey('rule-providers', $config);
        $this->assertFalse($config['sniffer']['enable']);
        $this->assertFalse($config['geo-auto-update']);
        $this->assertArrayNotHasKey('global-client-fingerprint', $config);
        foreach ($config['dns']['fake-ip-filter'] as $entry) {
            $this->assertStringNotContainsString('geosite:', $entry);
        }

        $autoGroups = array_filter($config['proxy-groups'], fn($g) => in_array($g['type'], ['url-test', 'fallback', 'load-balance'], true));
        $this->assertLessThanOrEqual(3, count($autoGroups));
        foreach ($autoGroups as $group) {
            $this->assertTrue($group['lazy'] ?? false, "{$group['name']} 要 lazy，用到才测速");
        }

        foreach ($config['rules'] as $rule) {
            $this->assertStringNotContainsStringIgnoringCase('RULE-SET', $rule);
            $this->assertStringNotContainsStringIgnoringCase('GEOSITE', $rule);
        }
    }

    /**
     * 每条规则、每个分组引用的去向都得存在，否则 mihomo 整份拒载
     */
    public function test_every_rule_and_group_target_exists(): void
    {
        $config = $this->template();
        $names = array_column($config['proxy-groups'], 'name');
        $known = array_flip(array_merge($names, ['DIRECT', 'REJECT']));

        foreach ($config['proxy-groups'] as $group) {
            foreach ($group['proxies'] as $member) {
                if (str_starts_with($member, '/')) {
                    continue;
                }
                $this->assertArrayHasKey($member, $known, "分组 {$group['name']} 引用了不存在的 {$member}");
            }
        }

        foreach ($config['rules'] as $rule) {
            $parts = array_map('trim', explode(',', $rule));
            $target = $parts[0] === 'MATCH' ? $parts[1] : $parts[2];
            $this->assertArrayHasKey($target, $known, "规则 {$rule} 的去向不存在");
            // 没有广告拦截，测速地址（generate_204）不会被拦
            $this->assertNotSame('REJECT', $target, "规则 {$rule} 不该拦截");
        }
    }

    public function test_key_routes_match_the_full_template(): void
    {
        $rules = $this->template()['rules'];

        $this->assertContains('DOMAIN-SUFFIX,gstatic.com,📢 谷歌服务', $rules);
        $this->assertContains('DOMAIN-SUFFIX,google.com,📢 谷歌服务', $rules);
        $this->assertContains('DOMAIN-SUFFIX,apple.com,🍎 苹果服务', $rules);
        $this->assertContains('DOMAIN-SUFFIX,microsoft.com,Ⓜ️ 微软服务', $rules);
        $this->assertContains('DOMAIN-SUFFIX,claude.ai,🤖 AI服务', $rules);
        $this->assertContains('DOMAIN-SUFFIX,sufe.uk,DIRECT', $rules);
        $this->assertContains('GEOIP,CN,DIRECT', $rules);
        $this->assertSame('MATCH,🐟 漏网之鱼', end($rules));

        $groups = array_column($this->template()['proxy-groups'], 'proxies', 'name');
        // 苹果、微软默认直连，谷歌默认走代理，和完整模板一致
        $this->assertSame('DIRECT', $groups['🍎 苹果服务'][1]);
        $this->assertSame('DIRECT', $groups['Ⓜ️ 微软服务'][1]);
        $this->assertSame('🇯🇵 日本节点', $groups['📢 谷歌服务'][1]);
    }

    public function test_handle_fills_the_lite_template_for_clash_mi(): void
    {
        $this->mock(Setting::class, function ($mock) {
            $mock->shouldReceive('get')->andReturnUsing(fn($key, $default = null) => $key === 'app_name' ? '苏菲家宽' : $default);
        });

        $base = ['port' => 443, 'type' => Server::TYPE_SHADOWSOCKS, 'password' => 'pw', 'protocol_settings' => ['cipher' => 'aes-128-gcm']];
        $servers = [
            $base + ['id' => 1, 'host' => 'hk.example.com', 'name' => '【3x】中转|香港🇭🇰'],
            $base + ['id' => 2, 'host' => 'jp.example.com', 'name' => '【3x】中转|日本樱花🇯🇵'],
            $base + ['id' => 3, 'host' => 'us.example.com', 'name' => '【3x】中转|美国一🇺🇸'],
        ];

        $protocol = new ClashMeta(['uuid' => 'u', 'u' => 0, 'd' => 0, 'transfer_enable' => 1, 'expired_at' => null], $servers, 'mihomo', '1.19.32', 'clashmeta/1.19.32; mihomo/1.19.32');
        $config = Yaml::parse($protocol->handle()->getContent());

        $this->assertArrayNotHasKey('rule-providers', $config);
        $this->assertCount(3, $config['proxies']);
        $groups = array_column($config['proxy-groups'], 'proxies', 'name');
        $this->assertArrayHasKey('苏菲家宽', $groups);
        $this->assertContains('【3x】中转|香港🇭🇰', $groups['🇭🇰 香港节点']);
        $this->assertNotContains('【3x】中转|香港🇭🇰', $groups['🇯🇵 日本节点']);
        $this->assertSame(['🇯🇵 日本节点', '苏菲家宽', '自动选择', 'DIRECT', '🇺🇸 美国节点'], $groups['📢 谷歌服务']);
        $this->assertContains('【3x】中转|日本樱花🇯🇵', $groups['🎬 解锁节点']);
        $this->assertContains('DOMAIN-SUFFIX,sufe.pro,苏菲家宽', $config['rules']);
    }
}
