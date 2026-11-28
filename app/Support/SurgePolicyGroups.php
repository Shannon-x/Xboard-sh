<?php

namespace App\Support;

/**
 * 按节点名给 Surge 生成策略组：地区组、线路倍率组、AI 组，组内带自动测速。
 *
 * 分组口径与 Clash Meta 订阅模板（数据库 v2_subscribe_templates.clashmeta）对齐：
 *   - 地区组「🇭🇰 香港节点」= select：先放「🇭🇰 香港自动」(url-test)、再放「自动选择」、再列该地区全部节点；
 *     自动组和「自动选择」一样不放 cf 加速和 5x/6x/7x/10x 高倍率节点，免得测速把客户自动选到贵的线路上；
 *   - 线路组（3x 中转 / 6x 专线 / 10x 三网优化 / cf 加速）是 Surge 这边新加的，Clash 模板里没有：
 *     select：先放该线路的自动组，再列全部节点；
 *   - AI 组「🤖 AI服务」与 Clash 一致：默认「🤖 AI优选」(fallback：AI自动 → 日本自动 → 美国自动 → 台湾自动 → 新加坡自动)。
 *
 * 和 Clash 不同，Surge 不支持在组里写正则让客户端自己筛（只能靠 policy-path 等，旧版不支持），
 * 而且 Surge 遇到「组里一个成员都没有」或「引用了不存在的组」会让整份托管配置报错，
 * 所以这里在服务端把成员算好写死，空组整组不发，并把它从所有引用方里摘掉。
 */
class SurgePolicyGroups
{
    /** 规则里引用的主策略组名，模板 [Rule] 段用它 */
    public const MAIN = 'Proxy';
    public const AUTO = '自动选择';
    public const FALLBACK = '故障转移';
    public const AI = '🤖 AI服务';

    /** 公告/提示类「节点」和不能直连的落地节点：任何组都不放 */
    private const INFO = '/(官网|发布页|防失联|测试|勿连|不可直连|用中转|自行切换|剩余流量|套餐到期|距离下次重置|过滤掉)/iu';
    /** 高倍率：自动组不放 */
    private const HEAVY = '/(5x|6x|7x|10x)/iu';
    private const CF = '/cf加速/iu';
    /**
     * 自动组排除的个别节点（照抄 Clash 模板的排除项）：
     * seednet 3x/6x 路由特殊、vpngate 出口的日本 NTT/sony/softbank 不稳、尼日利亚和 AT&T 无限流量 vless 是特殊落地。
     */
    private const AUTO_SKIP = '/(^中转[|]尼日利亚家宽🇳🇬$|^美国AT&T家宽无限流量🇺🇸vless$|^【[36]x】(中转|透传专线)[|]台湾seednet|日本(NTT|sony|softbank)家宽$)/iu';
    /** 日本组整组不放的节点（Clash 模板同口径） */
    private const JP_SKIP = '/(^【[36]x】(中转|透传专线)[|]日本(NTT|sony|softbank)家宽$|^cf加速[|]日本(动态|KDDI|softbank)家宽🇯🇵$)/iu';

    private const TEST_URL = 'http://www.gstatic.com/generate_204';
    private const AI_TEST_URL = 'https://chatgpt.com/cdn-cgi/trace';

    /**
     * 地区：[select 组名, 自动组名, 匹配, 自动组是否排除 cf 加速]
     * 韩国几乎只有 cf 加速和 3x，Clash 模板的韩国自动组也不排除 cf，这里保持一致。
     */
    private const REGIONS = [
        ['🇭🇰 香港节点', '🇭🇰 香港自动', '/(香港|HK|🇭🇰)/iu', true],
        ['🇹🇼 台湾节点', '🇹🇼 台湾自动', '/(台湾|🇹🇼)/iu', true],
        ['🇯🇵 日本节点', '🇯🇵 日本自动', '/(日本|🇯🇵)/iu', true],
        ['🇺🇸 美国节点', '🇺🇸 美国自动', '/(美国|🇺🇸|frontier)/iu', true],
        ['🇸🇬 新加坡节点', '🇸🇬 新加坡自动', '/(新加坡|狮城|🇸🇬)/iu', true],
        ['🇰🇷 韩国节点', '🇰🇷 韩国自动', '/(韩国|🇰🇷)/iu', false],
    ];
    private const OTHER_REGION = '🌍 其他地区';

    /**
     * 线路倍率：[select 组名, 自动组名, 匹配]
     * 节点名里的倍率写法有「【3x】」「[3x]」「【3x】日本…hy2」几种，按 3x/6x/10x 字样认。
     */
    private const LINES = [
        ['🚇 3x 中转节点', '🚇 3x 自动', '/(?<!\d)3x/iu'],
        ['🛣️ 6x 专线节点', '🛣️ 6x 自动', '/(?<!\d)6x/iu'],
        ['🌐 10x 三网优化', '🌐 10x 自动', '/(?<!\d)10x/iu'],
        ['☁️ cf加速节点', '☁️ cf加速自动', '/cf加速/iu'],
    ];

    /** @var string[] */
    private array $names;
    /** @var array<string, array{type:string, members:string[], params:string}> 有序 */
    private array $groups = [];

    /**
     * @param string[] $names 已经确定下发给这个 Surge 的节点名（顺序即面板排序）
     */
    public function __construct(array $names)
    {
        $this->names = array_values(array_unique(array_filter($names, fn($n) => is_string($n) && $n !== '')));
    }

    /**
     * 生成 [Proxy Group] 段的全部行。
     */
    public function render(): string
    {
        $this->build();
        $lines = [];
        foreach ($this->groups as $name => $g) {
            $line = $name . ' = ' . $g['type'] . ', ' . implode(', ', $g['members']);
            if ($g['params'] !== '') {
                $line .= ', ' . $g['params'];
            }
            $lines[] = $line;
        }
        return implode("\r\n", $lines);
    }

    /**
     * @return array<string, array{type:string, members:string[]}> 组名 => 类型和成员（测试用）
     */
    public function groups(): array
    {
        $this->build();
        return array_map(fn($g) => ['type' => $g['type'], 'members' => $g['members']], $this->groups);
    }

    private function build(): void
    {
        if ($this->groups) {
            return;
        }
        $usable = array_values(array_filter($this->names, fn($n) => !preg_match(self::INFO, $n)));
        $autoable = array_values(array_filter($usable, fn($n) => !preg_match(self::CF, $n)
            && !preg_match(self::HEAVY, $n) && !preg_match(self::AUTO_SKIP, $n)));
        // 只拿到高倍率或 cf 节点的客户（比如只买了 10x 套餐）自动组就用全部可用节点，免得组是空的
        $autoPool = $autoable ?: $usable;

        $test = 'url=' . self::TEST_URL . ', interval=600, timeout=5';
        $urlTest = $test . ', tolerance=100';

        $main = [self::AUTO, self::FALLBACK];
        $groups = [];
        $groups[self::MAIN] = ['type' => 'select', 'members' => [], 'params' => ''];
        $groups[self::AUTO] = ['type' => 'url-test', 'members' => $autoPool, 'params' => $urlTest];
        $groups[self::FALLBACK] = ['type' => 'fallback', 'members' => $autoPool, 'params' => $test];

        // 地区组
        $regionAuto = [];
        $regionSelect = [];
        foreach (self::REGIONS as [$selectName, $autoName, $pattern, $autoSkipCf]) {
            $members = array_values(array_filter($usable, fn($n) => preg_match($pattern, $n)
                && !($selectName === '🇯🇵 日本节点' && preg_match(self::JP_SKIP, $n))));
            $autoMembers = array_values(array_filter($members, fn($n) => !preg_match(self::HEAVY, $n)
                && !preg_match(self::AUTO_SKIP, $n) && !($autoSkipCf && preg_match(self::CF, $n))));
            if ($autoMembers) {
                $groups[$autoName] = ['type' => 'url-test', 'members' => $autoMembers, 'params' => $urlTest];
                $regionAuto[$selectName] = $autoName;
            }
            $regionSelect[$selectName] = $members;
            if (!$members) {
                continue;
            }
            $groups[$selectName] = [
                'type' => 'select',
                'members' => array_merge($autoMembers ? [$autoName] : [], [self::AUTO], $members),
                'params' => '',
            ];
            $main[] = $selectName;
        }
        $other = array_values(array_filter($usable, function ($n) {
            foreach (self::REGIONS as [, , $pattern]) {
                if (preg_match($pattern, $n)) {
                    return false;
                }
            }
            return true;
        }));
        $regionSelect[self::OTHER_REGION] = $other;
        if ($other) {
            $groups[self::OTHER_REGION] = ['type' => 'select', 'members' => array_merge([self::AUTO], $other), 'params' => ''];
            $main[] = self::OTHER_REGION;
        }

        // 线路倍率组
        foreach (self::LINES as [$selectName, $autoName, $pattern]) {
            $members = array_values(array_filter($usable, fn($n) => preg_match($pattern, $n)));
            if (!$members) {
                continue;
            }
            $autoMembers = array_values(array_filter($members, fn($n) => !preg_match(self::AUTO_SKIP, $n))) ?: $members;
            $groups[$autoName] = ['type' => 'url-test', 'members' => $autoMembers, 'params' => $urlTest];
            $groups[$selectName] = ['type' => 'select', 'members' => array_merge([$autoName], $members), 'params' => ''];
            $main[] = $selectName;
        }

        // AI：AI自动 = 日本/台湾的中转线路（不含香港、不含高倍率），与 Clash 模板同口径
        $aiAuto = array_values(array_filter($usable, fn($n) => !preg_match('/(香港|🇭🇰)/u', $n)
            && !preg_match(self::HEAVY, $n) && !preg_match(self::AUTO_SKIP, $n)
            && preg_match('/中转/u', $n) && preg_match('/(日本|台湾|🇯🇵|🇹🇼)/u', $n)));
        $aiChain = [];
        if ($aiAuto) {
            $groups['🧠 AI自动'] = ['type' => 'url-test', 'members' => $aiAuto,
                'params' => 'url=' . self::AI_TEST_URL . ', interval=600, timeout=5, tolerance=100'];
            $aiChain[] = '🧠 AI自动';
        }
        foreach (['🇯🇵 日本节点', '🇺🇸 美国节点', '🇹🇼 台湾节点', '🇸🇬 新加坡节点'] as $r) {
            if (isset($regionAuto[$r])) {
                $aiChain[] = $regionAuto[$r];
            }
        }
        $aiSelect = [];
        if ($aiChain) {
            $groups['🤖 AI优选'] = ['type' => 'fallback', 'members' => $aiChain,
                'params' => 'url=' . self::AI_TEST_URL . ', interval=600, timeout=5'];
            $aiSelect[] = '🤖 AI优选';
        }
        if ($aiAuto) {
            $aiSelect[] = '🧠 AI自动';
        }
        foreach (['🇺🇸 美国节点', '🇯🇵 日本节点'] as $r) {
            if ($regionSelect[$r]) {
                $aiSelect[] = $r;
            }
        }
        $aiSelect[] = self::MAIN;
        $aiSelect[] = self::AUTO;
        foreach (['🇹🇼 台湾节点', '🇸🇬 新加坡节点', '🇰🇷 韩国节点', self::OTHER_REGION] as $r) {
            if ($regionSelect[$r]) {
                $aiSelect[] = $r;
            }
        }
        $groups[self::AI] = ['type' => 'select', 'members' => $aiSelect, 'params' => ''];

        $main[] = 'DIRECT';
        $groups[self::MAIN]['members'] = $main;

        $this->groups = $this->prune($groups);
    }

    /**
     * 去掉没有成员的组，并把它从引用方里摘掉；摘完又空了的组继续去掉。
     * 主策略组和 AI 组被规则引用，永远保留（至少还有 DIRECT / Proxy）。
     */
    private function prune(array $groups): array
    {
        $keep = [self::MAIN, self::AI];
        do {
            $changed = false;
            foreach ($groups as $name => $g) {
                if ($g['members'] || in_array($name, $keep, true)) {
                    continue;
                }
                unset($groups[$name]);
                foreach ($groups as $other => $og) {
                    $groups[$other]['members'] = array_values(array_filter($og['members'], fn($m) => $m !== $name));
                }
                $changed = true;
            }
        } while ($changed);
        return $groups;
    }
}
