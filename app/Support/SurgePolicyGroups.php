<?php

namespace App\Support;

/**
 * 按节点名给 Surge 生成策略组：地区组、线路倍率组、服务组（AI / 电报 / 流媒体 / 漏网之鱼），组内带自动选择。
 *
 * 参考：
 *   - Surge 官方知识库「使用来自代理服务商的线路」：地区组用 smart 自动优选并故障切换，资源池类的组设 hidden=true；
 *   - 社区常用配置（bunizao/TutuBetterRules、ACL4SSR）：主选择 + 自动选择 + 故障转移 + 各地区组，
 *     服务组（OpenAI、Telegram、YouTube/Netflix…）是 select，第一项是默认，后面可换地区；最后是「漏网之鱼」兜底组；
 *   - 分组口径（地区匹配、排除项、AI 优选链、解锁节点名单）与本面板 Clash Meta 模板（数据库 clashmeta）保持一致。
 *
 * 我们这边的取舍：
 *   - 官方推荐的 include-other-group + policy-regex-filter 是让客户端按正则筛，但正则引擎和 lookahead 支持手册没写，
 *     而且组里一个成员都筛不到时 Surge 会让整份托管配置报错；所以这里在服务端把成员算好写死，空组整组不发，
 *     并把它从所有引用方里摘掉；
 *   - smart（iOS 5.11.0+ / Mac 5.7.0+）只给能收 VLESS 的 Surge 6（内核 6.12.0+）用，旧版仍用 url-test；
 *   - 自动类的组不放 cf 加速和 5x/6x/7x/10x 高倍率节点，免得自动选到贵的线路上（现网口径）；
 *   - 线路组（3x 中转 / 6x 专线 / 10x 三网优化 / cf 加速）是工单 #5724 要的，Clash 模板里没有。
 */
class SurgePolicyGroups
{
    /** 规则里引用的组：这几个一定生成 */
    public const MAIN = 'Proxy';
    public const AI = '🤖 AI服务';
    public const TELEGRAM = '📲 电报消息';
    public const STREAM = '🎞️ 国际流媒体';
    public const FINAL = '🐟 漏网之鱼';

    public const AUTO = '自动选择';
    public const FALLBACK = '故障转移';
    private const UNLOCK = '🎬 解锁节点';

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
    /** 能解锁流媒体的线路（Clash 模板「🎬 解锁节点」同口径） */
    private const UNLOCK_MATCH = '/(新加坡0[12]|日本樱花(?!10gbps)|独享webarena$|[|]台湾(🇹🇼)?$|美国一|美国二|美国洛杉矶|KDDI|biglobe|日本星链)/iu';

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
    private bool $smart;
    /** @var array<string, array{type:string, members:string[], params:string}> 有序 */
    private array $groups = [];

    /**
     * @param string[] $names 已经确定下发给这个 Surge 的节点名（顺序即面板排序）
     * @param bool $smart 客户端支持 smart 组（Surge 6 内核 6.12.0+ 一定支持）时，地区/线路的自动组用 smart
     */
    public function __construct(array $names, bool $smart = false)
    {
        $this->names = array_values(array_unique(array_filter($names, fn($n) => is_string($n) && $n !== '')));
        $this->smart = $smart;
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
     * @return array<string, array{type:string, members:string[], params:string}> 组名 => 类型、成员、参数（测试用）
     */
    public function toArray(): array
    {
        $this->build();
        return $this->groups;
    }

    /** 地区/线路下面的自动子组：Surge 6 用 smart（按真实连接打分、按站点记忆），旧版用 url-test */
    private function autoGroup(array $members): array
    {
        if ($this->smart) {
            return ['type' => 'smart', 'members' => $members, 'params' => 'hidden=true'];
        }
        return ['type' => 'url-test', 'members' => $members,
            'params' => 'url=' . self::TEST_URL . ', interval=600, timeout=5, tolerance=100, hidden=true'];
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

        $main = [self::AUTO, self::FALLBACK];
        $groups = [];
        $groups[self::MAIN] = ['type' => 'select', 'members' => [], 'params' => ''];
        // 顶层「自动选择」保持 url-test：成员多、要和 Clash 一样按测速结果走，也给旧版 Surge 用
        $groups[self::AUTO] = ['type' => 'url-test', 'members' => $autoPool, 'params' => $test . ', tolerance=100'];
        $groups[self::FALLBACK] = ['type' => 'fallback', 'members' => $autoPool, 'params' => $test];

        // 地区组：select，第一项是该地区自动子组，然后「自动选择」，然后该地区全部节点可手动选
        $regionAuto = [];
        $regionHas = [];
        foreach (self::REGIONS as [$selectName, $autoName, $pattern, $autoSkipCf]) {
            $members = array_values(array_filter($usable, fn($n) => preg_match($pattern, $n)
                && !($selectName === '🇯🇵 日本节点' && preg_match(self::JP_SKIP, $n))));
            $regionHas[$selectName] = (bool) $members;
            if (!$members) {
                continue;
            }
            $autoMembers = array_values(array_filter($members, fn($n) => !preg_match(self::HEAVY, $n)
                && !preg_match(self::AUTO_SKIP, $n) && !($autoSkipCf && preg_match(self::CF, $n))));
            if ($autoMembers) {
                $groups[$autoName] = $this->autoGroup($autoMembers);
                $regionAuto[$selectName] = $autoName;
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
        $regionHas[self::OTHER_REGION] = (bool) $other;
        if ($other) {
            $groups[self::OTHER_REGION] = ['type' => 'select', 'members' => array_merge([self::AUTO], $other), 'params' => ''];
            $main[] = self::OTHER_REGION;
        }
        $regions = fn(array $order) => array_values(array_filter($order, fn($r) => $regionHas[$r] ?? false));

        // 线路倍率组
        foreach (self::LINES as [$selectName, $autoName, $pattern]) {
            $members = array_values(array_filter($usable, fn($n) => preg_match($pattern, $n)));
            if (!$members) {
                continue;
            }
            $autoMembers = array_values(array_filter($members, fn($n) => !preg_match(self::AUTO_SKIP, $n))) ?: $members;
            $groups[$autoName] = $this->autoGroup($autoMembers);
            $groups[$selectName] = ['type' => 'select', 'members' => array_merge([$autoName], $members), 'params' => ''];
            $main[] = $selectName;
        }
        $main[] = 'DIRECT';
        $groups[self::MAIN]['members'] = $main;

        // AI：AI自动 = 日本/台湾的中转线路（不含香港、不含高倍率），与 Clash 模板同口径。
        // 用 chatgpt.com 测速，所以保持 url-test（smart 不认测速地址）
        $aiAuto = array_values(array_filter($usable, fn($n) => !preg_match('/(香港|🇭🇰)/u', $n)
            && !preg_match(self::HEAVY, $n) && !preg_match(self::AUTO_SKIP, $n)
            && preg_match('/中转/u', $n) && preg_match('/(日本|台湾|🇯🇵|🇹🇼)/u', $n)));
        $aiChain = [];
        if ($aiAuto) {
            $groups['🧠 AI自动'] = ['type' => 'url-test', 'members' => $aiAuto,
                'params' => 'url=' . self::AI_TEST_URL . ', interval=600, timeout=5, tolerance=100, hidden=true'];
            $aiChain[] = '🧠 AI自动';
        }
        foreach (['🇯🇵 日本节点', '🇺🇸 美国节点', '🇹🇼 台湾节点', '🇸🇬 新加坡节点'] as $r) {
            if (isset($regionAuto[$r])) {
                $aiChain[] = $regionAuto[$r];
            }
        }
        $ai = [];
        if ($aiChain) {
            $groups['🤖 AI优选'] = ['type' => 'fallback', 'members' => $aiChain,
                'params' => 'url=' . self::AI_TEST_URL . ', interval=600, timeout=5, hidden=true'];
            $ai[] = '🤖 AI优选';
        }
        $groups[self::AI] = ['type' => 'select', 'params' => '', 'members' => array_merge(
            $ai,
            $regions(['🇺🇸 美国节点', '🇯🇵 日本节点']),
            [self::MAIN, self::AUTO],
            $regions(['🇹🇼 台湾节点', '🇸🇬 新加坡节点', '🇰🇷 韩国节点', self::OTHER_REGION])
        )];

        // 电报：与 Clash 模板同顺序，默认跟主选择走
        $groups[self::TELEGRAM] = ['type' => 'select', 'params' => '', 'members' => array_merge(
            [self::MAIN, self::AUTO],
            $regions(['🇭🇰 香港节点', '🇹🇼 台湾节点', '🇯🇵 日本节点', '🇺🇸 美国节点', '🇸🇬 新加坡节点', '🇰🇷 韩国节点', self::OTHER_REGION])
        )];

        // 流媒体：默认「🎬 解锁节点」（能解锁的线路里自动选，不含 cf/高倍率），与 Clash 模板同口径
        $unlock = array_values(array_filter($usable, fn($n) => preg_match(self::UNLOCK_MATCH, $n)
            && !preg_match(self::CF, $n) && !preg_match(self::HEAVY, $n)));
        if ($unlock) {
            $groups[self::UNLOCK] = $this->autoGroup($unlock);
        }
        $groups[self::STREAM] = ['type' => 'select', 'params' => '', 'members' => array_merge(
            $unlock ? [self::UNLOCK] : [],
            $regions(['🇯🇵 日本节点']),
            [self::MAIN, self::AUTO],
            $regions(['🇭🇰 香港节点', '🇹🇼 台湾节点', '🇺🇸 美国节点', '🇸🇬 新加坡节点', '🇰🇷 韩国节点', self::OTHER_REGION])
        )];

        // 兜底：没命中任何规则的流量，默认跟主选择走，客户可以改成直连
        $groups[self::FINAL] = ['type' => 'select', 'params' => '', 'members' => [self::MAIN, self::AUTO, 'DIRECT']];

        $this->groups = $this->prune($groups);
    }

    /**
     * 去掉没有成员的组，并把它从引用方里摘掉；摘完又空了的组继续去掉。
     * 规则引用的组永远保留（至少还有 DIRECT / Proxy）。
     */
    private function prune(array $groups): array
    {
        $keep = [self::MAIN, self::AI, self::TELEGRAM, self::STREAM, self::FINAL];
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
