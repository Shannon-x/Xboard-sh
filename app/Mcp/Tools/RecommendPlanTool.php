<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\Mcp\McpPlanAdvisor;
use App\Support\PlanCatalog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('recommend_plan')]
#[Title('帮我选套餐')]
#[Description('帮客户选套餐、选流量档、算按量该充多少：按客户自己最近 90 天的真实用量（分线路，已换算倍率：×3 中转 1 GB 扣 3 GB，×10 三网扣 10 倍）估出每月用量，把每个包月档（含要不要加三网等增值线路、流量够不够、超出要加购多少）和按量（每月约多少钱、充多少够用几个月、赠送）都算成「每月多少钱」，给出推荐、备选和理由。客户问「买哪个」「流量选多大」「按量充多少」「换套餐划不划算」时先调它。客户没有历史用量或想按自己的估计算时，传 monthly_gb。')]
#[IsReadOnly]
#[IsIdempotent]
class RecommendPlanTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'monthly_gb' => $schema->number()->min(0)->max(100000)
                ->description('客户估计每月实际用多少 GB（不含倍率）。不传就按客户最近的真实用量；新客户先问清用途和每天大概用多久，参考 usage_guide 估一个数再传。'),
            'line' => $schema->string()->enum(array_keys(McpPlanAdvisor::LINES))
                ->description('客户主要走哪条线路：direct 直连 ×1（电信为主）、relay 中转 ×3（联通 / 移动）、dedicated 透传专线 ×6（游戏 / 会议）、triple 三网优化 ×10。不传按客户历史的线路占比；没有历史时按直连。'),
            'segment' => $schema->string()->enum(PlanCatalog::SEGMENTS)
                ->description('在哪一类套餐里选：basic 普通上网（含按量）、static 静态 IP 家宽、dynamic 动态 IP 家宽、global 日本 / 星链 / 全球、custom 定制拼车。不传就是客户当前套餐那一类，没有套餐时是普通上网。'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $user = User::findOrFail($user->id);
        $advisor = app(McpPlanAdvisor::class);
        $profile = $advisor->profile($user);

        $given = $request->get('monthly_gb');
        $monthly = is_numeric($given) ? max(0.0, (float) $given) : $profile['monthly_gb'];
        $line = $request->get('line');
        $mix = is_string($line) && isset(McpPlanAdvisor::LINES[$line])
            ? [McpPlanAdvisor::rateKey((float) McpPlanAdvisor::LINES[$line]['rate']) => 1.0]
            : ($profile['mix'] !== [] ? $profile['mix'] : ['1' => 1.0]);

        $history = [
            'days_of_data' => $profile['days_of_data'],
            'last_30_days' => $profile['last_30_days'],
            'months' => $profile['months'],
        ];
        if ($monthly === null) {
            return self::result([
                'need_customer_estimate' => true,
                'history' => $history,
                'usage_guide' => McpPlanAdvisor::USAGE_GUIDE,
                'line_guide' => self::lineGuide(),
                'note' => '客户的用量数据不到 ' . McpPlanAdvisor::MIN_HISTORY_DAYS . ' 天，没法按历史估。先问客户：主要用来做什么、每天大概用多久、家里宽带是电信 / 联通 / 移动；按 usage_guide 估出每月 GB，再带上 monthly_gb 和 line 调一次。',
            ]);
        }

        $result = $advisor->recommend($user, (float) $monthly, $mix, $request->get('segment'));

        return self::result([
            'basis' => [
                'source' => is_numeric($given) ? '客户自己的估计' : '客户最近的真实用量',
                'monthly_gb' => round((float) $monthly, 1),
                'monthly_quota_needed_gb' => $result['monthly_quota_needed_gb'],
                'line_mix' => array_map(fn ($rate, $share) => [
                    'line' => McpPlanAdvisor::lineName($rate),
                    'share_percent' => (int) round($share * 100),
                ], array_keys($mix), $mix),
            ],
            'history' => $history,
            ...$result,
            ...(is_numeric($given) || $profile['days_of_data'] < 28 ? ['usage_guide' => McpPlanAdvisor::USAGE_GUIDE] : []),
            'line_guide' => self::lineGuide(),
            'how_to_present' => '先说推荐哪个、每月大约多少钱、为什么（够不够用、余量多少、要不要三网、包月和按量哪个省），再用一句话说一两个备选。用量起伏大或说不准时按量更不浪费；用得多又稳定时包月更省。不要为了多卖推荐更贵的档位。客户选定后把 quote_args 原样传给 quote_plan 报价，确认后 create_order 用同样的参数。',
        ]);
    }

    private static function lineGuide(): array
    {
        return array_values(array_map(fn (array $l, string $id) => [
            'line' => $id,
            'name' => $l['name'],
            'rate' => $l['rate'],
            'good_for' => $l['note'],
        ], McpPlanAdvisor::LINES, array_keys(McpPlanAdvisor::LINES)));
    }
}
