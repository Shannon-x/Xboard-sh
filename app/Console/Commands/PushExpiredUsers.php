<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\NodeSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * 把刚到期的用户从 WS 在线的节点上移除。
 *
 * 流量用完有 check:traffic-exceeded 推送，按时间到期原先没有：WS 在线的节点要等下次对账才断开。
 * 每分钟查一次 (上次游标, 现在] 之间到期的用户，按 (plan_id, expired_at) 索引走区间扫描，不扫全表。
 * WS 服务没在跑时没有可推的节点，只推进游标。重复推送无害（移除是幂等的）。
 */
class PushExpiredUsers extends Command
{
    protected $signature = 'node:push-expired';
    protected $description = '把刚到期的用户从 WS 在线节点上移除';

    public const CURSOR_KEY = 'node_push_expired:cursor';

    /** 调度停过很久时最多往回补 10 分钟；更早到期的由节点定时对账兜底 */
    private const MAX_LOOKBACK = 600;

    private const CHUNK = 500;

    public function handle(): int
    {
        $now = time();
        $from = max((int) Cache::get(self::CURSOR_KEY, $now - 120), $now - self::MAX_LOOKBACK);
        if ($from >= $now) {
            return self::SUCCESS;
        }
        if (!Cache::has(NodeWebSocketServer::HEARTBEAT_CACHE_KEY)) {
            Cache::put(self::CURSOR_KEY, $now, 86400);
            return self::SUCCESS;
        }

        // plan_id 的取值很少，DISTINCT 走 plan_id 索引；按每个取值在 expired_at 上取区间
        $planIds = User::query()->distinct()->pluck('plan_id')->all();
        $withPlan = array_values(array_filter($planIds, fn ($id) => $id !== null));
        $hasNull = count($withPlan) !== count($planIds);

        $users = 0;
        $nodes = 0;
        User::query()
            ->where(function ($query) use ($withPlan, $hasNull) {
                if ($withPlan !== []) {
                    $query->whereIn('plan_id', $withPlan);
                }
                if ($hasNull || $withPlan === []) {
                    $query->orWhereNull('plan_id');
                }
            })
            ->where('expired_at', '>', $from)
            ->where('expired_at', '<=', $now)
            ->where('banned', 0)
            ->chunkById(self::CHUNK, function ($chunk) use (&$users, &$nodes) {
                $users += $chunk->count();
                $nodes += NodeSyncService::notifyUsersUnavailable($chunk);
            });

        Cache::put(self::CURSOR_KEY, $now, 86400);
        if ($users > 0) {
            $this->info("Expired {$users} users in ({$from}, {$now}], notified {$nodes} node pushes.");
        }
        return self::SUCCESS;
    }
}
