<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\PlanCustomizationService;
use App\Services\ServerGroupService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class GroupController extends Controller
{
    public function fetch(Request $request): JsonResponse
    {
        return $this->success(app(ServerGroupService::class)->summaries($request->boolean('include_details')));
    }

    public function save(Request $request)
    {
        $params = $request->validate([
            'id' => 'nullable|integer|exists:v2_server_group,id',
            'name' => 'required|string|max:255',
        ]);
        $name = trim($params['name']);
        if ($name === '') return $this->fail([422, '组名不能为空']);
        $serverGroup = isset($params['id']) ? ServerGroup::findOrFail($params['id']) : new ServerGroup();
        $serverGroup->name = $name;
        $saved = $serverGroup->save();
        PlanCustomizationService::forgetAddonCaches();
        return $this->success($saved);
    }

    public function drop(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer|min:1']);
        $groupId = (int) $params['id'];

        $serverGroup = ServerGroup::find($groupId);
        if (!$serverGroup) {
            return $this->fail([400202, '组不存在']);
        }
        if (Server::whereJsonContains('group_ids', (string) $groupId)->orWhereJsonContains('group_ids', $groupId)->exists()) {
            return $this->fail([400, '该组已被节点所使用，无法删除']);
        }

        if (Plan::where('group_id', $groupId)->exists()) {
            return $this->fail([400, '该组已被订阅所使用，无法删除']);
        }
        if (User::where('group_id', $groupId)->exists()) {
            return $this->fail([400, '该组已被用户所使用，无法删除']);
        }
        foreach (Plan::whereNotNull('customization')->select('customization')->get() as $plan) {
            if (array_key_exists($groupId, $plan->customization['addon_groups'] ?? [])
                || array_key_exists($groupId, $plan->customization['traffic_topup']['group_prices'] ?? [])) {
                return $this->fail([400, '该组已被套餐增值或历史授权计价使用，无法删除']);
            }
        }
        if (User::whereJsonContains('plan_options->addon_groups', $groupId)
            ->orWhereJsonContains('plan_options->addon_groups', (string) $groupId)
            ->orWhereJsonContains('admin_group_ids', $groupId)
            ->orWhereJsonContains('admin_group_ids', (string) $groupId)->exists()) {
            return $this->fail([400, '该组仍有已购或管理员授权用户，无法删除']);
        }
        $deleted = $serverGroup->delete();
        PlanCustomizationService::forgetAddonCaches();
        return $this->success($deleted);
    }
}
