<?php

namespace App\Mcp\Tools;

use App\Models\StatUser;
use App\Models\User;
use App\Services\Mcp\McpPresenter as P;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_traffic_daily')]
#[Title('每日流量')]
#[Description('查看最近若干天每天用了多少流量（GB，已按节点倍率计入），以及最近 7 天的日均用量。用来回答「哪天用得最多」「照这个速度够不够用到重置日」。')]
#[IsReadOnly]
#[IsIdempotent]
class GetTrafficDailyTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'days' => $schema->integer()->description('看最近多少天，1–31，默认 14。')->min(1)->max(31),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $days = (int) ($request->get('days') ?? 14);
        $days = max(1, min(31, $days));
        $since = now()->startOfDay()->subDays($days - 1)->getTimestamp();

        $byDay = StatUser::where('user_id', $user->id)
            ->where('record_at', '>=', $since)
            ->get(['u', 'd', 'record_at'])
            // u / d 落库时已乘过节点倍率（StatUserJob），这里直接相加就是扣费流量
            ->groupBy(fn ($r) => date('Y-m-d', (int) $r->record_at))
            ->map(fn ($rows) => $rows->sum(fn ($r) => (int) $r->u + (int) $r->d));

        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->startOfDay()->subDays($i)->format('Y-m-d');
            $series[] = ['date' => $date, 'used_gb' => P::gb($byDay[$date] ?? 0)];
        }
        $last7 = array_slice($series, -7);
        $avg = count($last7) ? round(array_sum(array_column($last7, 'used_gb')) / count($last7), 2) : 0;

        $fresh = User::find($user->id);
        $remaining = max(0, (int) $fresh->transfer_enable - (int) $fresh->u - (int) $fresh->d);

        return self::result([
            'days' => $series,
            'avg_last_7_days_gb' => $avg,
            'remaining_gb' => P::gb($remaining),
            'days_left_at_this_pace' => $avg > 0 ? round(P::gb($remaining) / $avg, 1) : null,
            'note' => '当天数据每隔几分钟汇总一次，可能略有延迟。',
        ]);
    }
}
