<?php

namespace App\Observers;

use App\Models\Server;
use App\Services\NodeSyncService;
use App\Services\PlanCustomizationService;

class ServerObserver
{
    public function created(Server $server): void
    {
        PlanCustomizationService::forgetAddonCaches();
    }

    public function updated(Server $server): void
    {
        if (
            $server->isDirty([
                'group_ids',
            ])
        ) {
            PlanCustomizationService::forgetAddonCaches(); // 套餐页展示的「N 个节点」按组计数
            NodeSyncService::notifyUsersUpdatedByGroup($server->id);
        } else if (
            $server->isDirty([
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
