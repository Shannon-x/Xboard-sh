<?php

namespace App\Services;

use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class NodeSyncService
{
    /**
     * Check if node has active WS connection
     */
    public static function isNodeOnline(int $nodeId): bool
    {
        return (bool) Cache::get("node_ws_alive:{$nodeId}");
    }

    /**
     * Push node config update
     */
    public static function notifyConfigUpdated(int $nodeId): void
    {
        if (!self::isNodeOnline($nodeId))
            return;

        $node = Server::find($nodeId);
        if (!$node)
            return;


        self::push($nodeId, 'sync.config', ['config' => ServerService::buildNodeConfig($node)]);
    }

    /**
     * Push all users to all nodes in the group
     */
    public static function notifyUsersUpdatedByGroup(int $groupId): void
    {
        $servers = Server::whereJsonContains('group_ids', (string) $groupId)
            ->get();

        foreach ($servers as $server) {
            if (!self::isNodeOnline($server->id))
                continue;

            $users = ServerService::getAvailableUsers($server)->toArray();
            self::push($server->id, 'sync.users', ['users' => $users]);
        }
    }

    /**
     * Push user changes (add/remove) to affected nodes
     */
    public static function notifyUserChanged(User $user): void
    {
        // 覆盖基础组 ∪ 已生效增值组的全部节点。add/remove 只看用户是否可用：
        // 一个节点会出现在这个列表里，本身就意味着用户对它有资格（组已命中）；
        // 失去资格的组由 NodeUserSyncJob 先推 remove，再进到这里重推仍有资格的。
        $groupIds = $user->effectiveGroupIds();
        if ($groupIds === [])
            return;

        $servers = Server::where(function ($query) use ($groupIds) {
            foreach ($groupIds as $groupId) {
                $query->orWhereJsonContains('group_ids', (string) $groupId);
            }
        })->get();
        foreach ($servers as $server) {
            if (!self::isNodeOnline($server->id))
                continue;

            if ($user->isAvailable()) {
                self::push($server->id, 'sync.user.delta', [
                    'action' => 'add',
                    'users' => [
                        [
                            'id' => $user->id,
                            'uuid' => $user->uuid,
                            'speed_limit' => $user->speed_limit,
                            'device_limit' => $user->device_limit,
                        ]
                    ],
                ]);
            } else {
                self::push($server->id, 'sync.user.delta', [
                    'action' => 'remove',
                    'users' => [['id' => $user->id]],
                ]);
            }
        }
    }

    /**
     * Push user removal from a specific group's nodes
     */
    public static function notifyUserRemovedFromGroup(int $userId, int $groupId): void
    {
        $servers = Server::whereJsonContains('group_ids', (string) $groupId)
            ->get();

        foreach ($servers as $server) {
            if (!self::isNodeOnline($server->id))
                continue;

            self::push($server->id, 'sync.user.delta', [
                'action' => 'remove',
                'users' => [['id' => $userId]],
            ]);
        }
    }

    /**
     * Full sync: push config + users to a node
     */
    public static function notifyFullSync(int $nodeId): void
    {
        if (!self::isNodeOnline($nodeId))
            return;

        $node = Server::find($nodeId);
        if (!$node)
            return;

        self::push($nodeId, 'sync.config', ['config' => ServerService::buildNodeConfig($node)]);

        $users = ServerService::getAvailableUsers($node)->toArray();
        self::push($nodeId, 'sync.users', ['users' => $users]);
    }

    /**
     * Publish a push command to Redis — picked up by the Workerman WS server
     */
    public static function push(int $nodeId, string $event, array $data): void
    {
        try {
            Redis::publish('node:push', json_encode([
                'node_id' => $nodeId,
                'event' => $event,
                'data' => $data,
            ]));
        } catch (\Throwable $e) {
            Log::warning("[NodePush] Redis publish failed: {$e->getMessage()}", [
                'node_id' => $nodeId,
                'event' => $event,
            ]);
        }
    }
}
