<?php

namespace App\Observers;

use App\Models\Server;
use App\Services\NodeSyncService;
use App\Services\PlanCustomizationService;

class ServerObserver
{
    /**
     * 事务提交后再推：保存发生在事务里时，WS 进程读到的还是提交前的数据。
     * 提交后 isDirty() 已经复位，所以下面用 wasChanged() 判断改了什么。
     */
    public bool $afterCommit = true;

    public function created(Server $server): void
    {
        PlanCustomizationService::forgetAddonCaches();
    }

    public function updated(Server $server): void
    {
        if ($server->wasChanged(['group_ids'])) {
            PlanCustomizationService::forgetAddonCaches(); // 套餐页展示的「N 个节点」按组计数
            // 节点换了组，它的名单变了：给这个节点推全量。原先把节点 id 当成组 id 传给
            // notifyUsersUpdatedByGroup，推到了不相干的节点上，这个节点自己反而没收到。
            NodeSyncService::notifyFullSync($server->id);
        } else if (
            $server->wasChanged([
                'server_port',
                'protocol_settings',
                'type',
                'route_ids',
                'custom_outbounds',
                'custom_routes',
                'cert_config',
            ])
        ) {
            NodeSyncService::notifyConfigUpdated($server->id);
        }
    }

    public function deleted(Server $server): void
    {
        PlanCustomizationService::forgetAddonCaches();
        NodeSyncService::notifyConfigUpdated($server->id);
    }
}
