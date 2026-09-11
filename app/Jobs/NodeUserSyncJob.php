<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\NodeSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NodeUserSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 10;

    // Queue deserialization does not run the constructor. A declared default is
    // required for payloads produced before addon groups existed.
    private array $oldAddonGroups = [];

    /**
     * @param int|null $oldGroupId    基础组变更前的旧组（沿用原有语义）
     * @param int[]    $oldAddonGroups 本次变更后**失去**的增值组（granted_groups 的差集）。
     *                                 先对这些组的节点推 remove，再由 notifyUserChanged 重推
     *                                 仍有资格的节点 —— 同时挂在基础组和失去的增值组上的节点会
     *                                 先 remove 后 add，与基础组变更时的既有顺序一致，净结果正确。
     */
    public function __construct(
        private readonly int $userId,
        private readonly string $action,
        private readonly ?int $oldGroupId = null,
        array $oldAddonGroups = []
    ) {
        $this->oldAddonGroups = $oldAddonGroups;
        $this->onQueue('node_sync');
    }

    public function handle(): void
    {
        $user = User::find($this->userId);

        if ($this->action === 'updated' || $this->action === 'created') {
            if ($this->oldGroupId) {
                NodeSyncService::notifyUserRemovedFromGroup($this->userId, $this->oldGroupId);
            }
            foreach ($this->oldAddonGroups as $groupId) {
                NodeSyncService::notifyUserRemovedFromGroup($this->userId, (int) $groupId);
            }
            if ($user) {
                NodeSyncService::notifyUserChanged($user);
            }
        } elseif ($this->action === 'deleted') {
            if ($this->oldGroupId) {
                NodeSyncService::notifyUserRemovedFromGroup($this->userId, $this->oldGroupId);
            }
            foreach ($this->oldAddonGroups as $groupId) {
                NodeSyncService::notifyUserRemovedFromGroup($this->userId, (int) $groupId);
            }
        }
    }
}
