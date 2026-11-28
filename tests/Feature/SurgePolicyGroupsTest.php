<?php

namespace Tests\Feature;

use App\Support\SurgePolicyGroups;
use Tests\TestCase;

/**
 * Surge 订阅按地区 / 线路倍率分组（工单 #5724：「现在是一个大列表，非常杂乱，建议像 clash 那样分组，组内可以 auto」）。
 * 节点名取自现网命名规则。
 */
class SurgePolicyGroupsTest extends TestCase
{
    private const NAMES = [
        '苏菲家宽官网地址：sufe.us/ai.sufe.pro',
        '官网地址防失联发布页：sufe.uk',
        '【6x】透传专线|香港动态家宽-01🇭🇰',
        '【6x】透传专线|美国一🇺🇸',
        '【6x】透传专线|日本NTT家宽',
        '优秀|【3x】中转|香港动态家宽-01🇭🇰',
        '【3x】中转|美国一🇺🇸',
        '【3x】中转|高速新加坡01🇸🇬',
        '优秀｜【3x】中转|新日本KDDI家宽🇯🇵',
        '【3x】中转|台湾🇹🇼',
        '优秀|[3x]中转|台湾hinet家宽02',
        '【3x】中转|台湾seednet动态家宽🇹🇼',
        '【3x】中转|日本NTT家宽',
        '【3x】中转|韩国KT家宽',
        '【3x】中转|英国',
        '【10x】三网优化|美国一🇺🇸',
        '【10x】中转|美国二🇺🇸',
        '优秀|cf加速|香港动态家宽-01🇭🇰',
        'cf加速|日本KDDI家宽🇯🇵',
        '香港动态家宽-01 hy2🇭🇰',
        '日本樱花10gbps🌸hy2',
        '【第一】美国AT&T静态',
        '【10x】日本星链家宽ss【不可直连！小白不要连接！】',
    ];

    private function policyGroups(array $names = self::NAMES): array
    {
        return (new SurgePolicyGroups($names))->groups();
    }

    public function test_main_group_lists_groups_not_every_node(): void
    {
        $g = $this->policyGroups();
        $this->assertSame([
            '自动选择', '故障转移',
            '🇭🇰 香港节点', '🇹🇼 台湾节点', '🇯🇵 日本节点', '🇺🇸 美国节点', '🇸🇬 新加坡节点', '🇰🇷 韩国节点', '🌍 其他地区',
            '🚇 3x 中转节点', '🛣️ 6x 专线节点', '🌐 10x 三网优化', '☁️ cf加速节点',
            'DIRECT',
        ], $g['Proxy']['members']);
    }

    public function test_auto_pools_skip_info_heavy_and_cf_nodes(): void
    {
        $auto = $this->policyGroups()['自动选择']['members'];
        foreach ($auto as $n) {
            $this->assertDoesNotMatchRegularExpression('/(6x|10x|cf加速|官网|不可直连|seednet|NTT)/u', $n);
        }
        $this->assertContains('【3x】中转|美国一🇺🇸', $auto);
        $this->assertContains('香港动态家宽-01 hy2🇭🇰', $auto);
    }

    public function test_region_groups_start_with_their_auto_group(): void
    {
        $g = $this->policyGroups();
        $this->assertSame('url-test', $g['🇭🇰 香港自动']['type']);
        $this->assertSame(['🇭🇰 香港自动', '自动选择'], array_slice($g['🇭🇰 香港节点']['members'], 0, 2));
        // 手动选择里有该地区全部节点（含 6x/cf），自动组里只有 1x/3x
        $this->assertContains('【6x】透传专线|香港动态家宽-01🇭🇰', $g['🇭🇰 香港节点']['members']);
        $this->assertContains('优秀|cf加速|香港动态家宽-01🇭🇰', $g['🇭🇰 香港节点']['members']);
        $this->assertNotContains('【6x】透传专线|香港动态家宽-01🇭🇰', $g['🇭🇰 香港自动']['members']);
        // 日本组不放 vpngate 出口的 NTT 和 cf 日本 KDDI（与 Clash 模板同口径）
        $this->assertNotContains('【3x】中转|日本NTT家宽', $g['🇯🇵 日本节点']['members']);
        $this->assertNotContains('cf加速|日本KDDI家宽🇯🇵', $g['🇯🇵 日本节点']['members']);
        $this->assertContains('【第一】美国AT&T静态', $g['🇺🇸 美国节点']['members']);
        $this->assertContains('【3x】中转|英国', $g['🌍 其他地区']['members']);
    }

    public function test_line_groups_by_multiplier(): void
    {
        $g = $this->policyGroups();
        $this->assertSame('🚇 3x 自动', $g['🚇 3x 中转节点']['members'][0]);
        $this->assertContains('优秀|[3x]中转|台湾hinet家宽02', $g['🚇 3x 中转节点']['members']);
        $this->assertNotContains('【3x】中转|台湾seednet动态家宽🇹🇼', $g['🚇 3x 自动']['members']);
        $this->assertSame(['🌐 10x 自动', '【10x】三网优化|美国一🇺🇸', '【10x】中转|美国二🇺🇸'], $g['🌐 10x 三网优化']['members']);
        // 10gbps 不能被认成 10x
        $this->assertNotContains('日本樱花10gbps🌸hy2', $g['🌐 10x 三网优化']['members']);
        $this->assertContains('日本樱花10gbps🌸hy2', $g['自动选择']['members']);
    }

    public function test_ai_group_defaults_to_auto_chain(): void
    {
        $g = $this->policyGroups();
        $this->assertSame('🤖 AI优选', $g['🤖 AI服务']['members'][0]);
        $this->assertSame(['🧠 AI自动', '🇯🇵 日本自动', '🇺🇸 美国自动', '🇹🇼 台湾自动', '🇸🇬 新加坡自动'], $g['🤖 AI优选']['members']);
        foreach ($g['🧠 AI自动']['members'] as $n) {
            $this->assertMatchesRegularExpression('/中转/u', $n);
            $this->assertDoesNotMatchRegularExpression('/(香港|10x|6x)/u', $n);
        }
    }

    public function test_no_group_is_empty_or_dangling(): void
    {
        foreach ([self::NAMES, [], ['【10x】三网优化|美国一🇺🇸'], ['【10x】日本星链家宽ss【不可直连！小白不要连接！】']] as $names) {
            $g = $this->policyGroups($names);
            $this->assertArrayHasKey('Proxy', $g);
            $this->assertArrayHasKey('🤖 AI服务', $g);
            foreach ($g as $name => $group) {
                $this->assertNotEmpty($group['members'], $name);
                foreach ($group['members'] as $m) {
                    $this->assertTrue(in_array($m, $names, true) || isset($g[$m]) || $m === 'DIRECT', "{$name} 引用了不存在的 {$m}");
                }
            }
        }
    }

    public function test_render_is_one_surge_line_per_group(): void
    {
        $out = (new SurgePolicyGroups(self::NAMES))->render();
        $lines = explode("\r\n", $out);
        $this->assertSame('Proxy = select, 自动选择, 故障转移, 🇭🇰 香港节点', implode(', ', array_slice(explode(', ', $lines[0]), 0, 4)));
        $this->assertStringContainsString('🇭🇰 香港自动 = url-test, ', $out);
        $this->assertStringContainsString('url=http://www.gstatic.com/generate_204, interval=600, timeout=5, tolerance=100', $out);
        $this->assertStringContainsString('🤖 AI优选 = fallback, 🧠 AI自动, ', $out);
        foreach ($lines as $line) {
            $this->assertMatchesRegularExpression('/^[^=,]+ = (select|url-test|fallback), /u', $line);
        }
    }

    public function test_default_template_only_references_generated_groups(): void
    {
        $tpl = file_get_contents(base_path('resources/rules/default.surge.conf'));
        $this->assertStringContainsString('$proxy_groups', $tpl);
        $this->assertStringNotContainsString('$proxy_group,', $tpl);
        preg_match('/\[Rule\](.*?)(\n\[|$)/s', $tpl, $m);
        $g = $this->policyGroups([]);
        foreach (explode("\n", $m[1]) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = array_map('trim', explode(',', $line));
            $policy = $parts[0] === 'FINAL' ? $parts[1] : $parts[2];
            $this->assertTrue(isset($g[$policy]) || in_array($policy, ['DIRECT', 'REJECT', 'REJECT-TINYGIF'], true), "规则引用了不存在的策略 {$policy}：{$line}");
        }
    }
}
