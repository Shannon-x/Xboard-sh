<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\NodeSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class CheckTrafficExceeded extends Command
{
    protected $signature = 'check:traffic-exceeded';
    protected $description = '检查流量超标用户并通知节点';

    public function handle()
    {
        $count = Redis::scard('traffic:pending_check');
        if ($count <= 0) {
            return;
        }

        $pendingUserIds = array_map('intval', Redis::spop('traffic:pending_check', $count));

        // 要算增值组，用模型（effectiveGroupIds 读 plan_id / plan_options / admin_group_ids）
        $exceededUsers = User::query()
            ->whereIn('id', $pendingUserIds)
            ->whereRaw('u + d >= transfer_enable')
            ->where('transfer_enable', '>', 0)
            ->where('banned', 0)
            ->get();

        if ($exceededUsers->isEmpty()) {
            return;
        }

        // 基础组和已生效增值组的节点都要移除；每个节点一条推送
        $notifiedCount = NodeSyncService::notifyUsersUnavailable($exceededUsers);

        $this->info("Checked " . count($pendingUserIds) . " users, notified {$notifiedCount} nodes for " . $exceededUsers->count() . " exceeded users.");
    }
}
