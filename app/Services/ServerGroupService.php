<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use Illuminate\Support\Collection;

class ServerGroupService
{
    /** Admin-only inventory. Node connection details and credentials are never included. */
    public function summaries(bool $includeDetails = false): Collection
    {
        $groups = ServerGroup::query()->orderByDesc('id')->withCount('users')->get();
        $columns = $includeDetails ? ['id', 'name', 'type', 'show', 'rate', 'group_ids'] : ['group_ids'];
        $nodes = Server::query()->orderBy('sort')->orderBy('id')->get($columns);
        $counts = []; $nodesByGroup = []; $plansByGroup = []; $pricingCounts = [];
        foreach ($nodes as $node) {
            foreach (array_unique(array_map('intval', (array) $node->group_ids)) as $groupId) {
                $counts[$groupId] = ($counts[$groupId] ?? 0) + 1;
                if ($includeDetails) {
                    $nodesByGroup[$groupId][] = [
                        'id' => (int) $node->id, 'name' => $node->name, 'type' => $node->type,
                        'show' => (bool) $node->show, 'rate' => $node->rate,
                    ];
                }
            }
        }
        if ($includeDetails) {
            foreach (Plan::query()->orderBy('id')->get(['id', 'name', 'group_id', 'show', 'sell', 'customization']) as $plan) {
                $summary = ['id' => (int) $plan->id, 'name' => $plan->name, 'show' => (bool) $plan->show, 'sell' => (bool) $plan->sell];
                if ($plan->group_id) $plansByGroup[$plan->group_id][] = $summary + ['mode' => 'base'];
                foreach ($plan->customization['addon_groups'] ?? [] as $id => $rule) {
                    if (!is_array($rule) || !in_array($rule['mode'] ?? null, ['included', 'optional'], true)) continue;
                    $plansByGroup[(int) $id][] = $summary + [
                        'mode' => $rule['mode'], 'label' => trim((string) ($rule['label'] ?? '')),
                        'price' => (int) ($rule['price'] ?? 0),
                    ];
                }
                // Historical pricing references do not grant access and are not purchase options.
                foreach ($plan->customization['traffic_topup']['group_prices'] ?? [] as $id => $price) {
                    $pricingCounts[(int) $id] = ($pricingCounts[(int) $id] ?? 0) + 1;
                }
            }
        }
        return $groups->map(function ($group) use ($includeDetails, $counts, $nodesByGroup, $plansByGroup, $pricingCounts) {
            // Do not set the server_count attribute: its model accessor would run another COUNT per group.
            $summary = $group->toArray() + ['server_count' => $counts[$group->id] ?? 0];
            if ($includeDetails) {
                $groupNodes = $nodesByGroup[$group->id] ?? [];
                $summary += [
                    'nodes' => $groupNodes,
                    'visible_server_count' => count(array_filter($groupNodes, fn ($node) => $node['show'])),
                    'plans' => $plansByGroup[$group->id] ?? [],
                    'pricing_plan_count' => $pricingCounts[$group->id] ?? 0,
                ];
            }
            return $summary;
        });
    }
}
