<?php

namespace Tests\Feature;

use App\Http\Controllers\V2\Admin\Server\GroupController;
use App\Models\{Plan, Server, ServerGroup, User};
use App\Services\{PlanCustomizationService, ServerGroupService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, DB};
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServerGroupManagementTest extends TestCase
{
    use RefreshDatabase;

    private function group(string $name): ServerGroup
    {
        $group = new ServerGroup;
        $group->name = $name;
        $group->save();
        return $group;
    }

    private function user(array $attributes = []): User
    {
        return User::create($attributes + [
            'email' => Str::random(12) . '@example.test', 'password' => 'test',
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'balance' => 0, 'transfer_enable' => 107374182400, 'expired_at' => time() + 86400,
        ]);
    }

    private function plan(ServerGroup $base, array $customization = []): Plan
    {
        return Plan::create([
            'name' => 'Starlink plan', 'group_id' => $base->id, 'show' => true, 'sell' => true,
            'renew' => true, 'transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => 100,
            'reset_traffic_method' => 1, 'prices' => ['monthly' => 20], 'customization' => $customization,
        ]);
    }

    private function node(array $groups, bool $show = true): Server
    {
        return Server::create([
            'type' => 'trojan', 'name' => $show ? 'Starlink node' : 'Hidden node', 'rate' => 10,
            'group_ids' => array_map('strval', $groups), 'show' => $show,
            'host' => 'private.example.test', 'port' => '443', 'server_port' => 443,
            'cert_config' => ['tls_key' => 'private-key-never-return'],
        ]);
    }

    private function endpoint(string $action): string
    {
        return '/' . app('router')->getRoutes()->getByAction(GroupController::class . '@' . $action)->uri();
    }

    public function test_details_are_admin_only_and_never_include_connection_secrets(): void
    {
        $group = $this->group('Starlink');
        $node = $this->node([$group->id]);
        $path = $this->endpoint('fetch') . '?include_details=1';
        $this->getJson($path)->assertForbidden();
        Sanctum::actingAs($this->user());
        $this->getJson($path)->assertForbidden();
        Sanctum::actingAs($this->user(['is_admin' => true]));
        $response = $this->getJson($path)->assertOk();
        $response->assertJsonPath('data.0.nodes.0.id', $node->id);
        $this->assertSame(['id', 'name', 'type', 'show', 'rate'], array_keys($response->json('data.0.nodes.0')));
        $this->assertStringNotContainsString('private.example.test', $response->getContent());
        $this->assertStringNotContainsString('private-key-never-return', $response->getContent());
    }

    public function test_details_keep_group_scopes_and_pricing_only_references_separate_without_writes(): void
    {
        $base = $this->group('Base');
        $starlink = $this->group('Starlink');
        $comcast = $this->group('Comcast');
        $history = $this->group('Historical');
        $included = $this->group('Included');
        $visible = $this->node([$starlink->id, $history->id]);
        $hidden = $this->node([$starlink->id], false);
        $this->node([$comcast->id]);
        $plan = $this->plan($base, [
            'addon_groups' => [
                $starlink->id => ['mode' => 'optional', 'label' => '三网优化线路', 'price' => 1000],
                $included->id => ['mode' => 'included'],
            ],
            'traffic_topup' => ['group_prices' => [$history->id => 12]],
        ]);
        $user = $this->user(['plan_id' => $plan->id, 'group_id' => $base->id, 'admin_group_ids' => [$history->id]]);
        $before = [$plan->fresh()->getRawOriginal(), $user->fresh()->getRawOriginal(), $visible->fresh()->getRawOriginal()];
        $rows = app(ServerGroupService::class)->summaries(true)->keyBy('id');
        $this->assertSame([$visible->id, $hidden->id], array_column($rows[$starlink->id]['nodes'], 'id'));
        $this->assertSame(2, $rows[$starlink->id]['server_count']);
        $this->assertSame(1, $rows[$starlink->id]['visible_server_count']);
        $this->assertSame('optional', $rows[$starlink->id]['plans'][0]['mode']);
        $this->assertSame('三网优化线路', $rows[$starlink->id]['plans'][0]['label']);
        $this->assertSame(1000, $rows[$starlink->id]['plans'][0]['price']);
        $this->assertSame('included', $rows[$included->id]['plans'][0]['mode']);
        $this->assertSame('base', $rows[$base->id]['plans'][0]['mode']);
        $this->assertSame(1, $rows[$base->id]['users_count']);
        $this->assertSame([], $rows[$history->id]['plans']);
        $this->assertSame(1, $rows[$history->id]['pricing_plan_count']);
        $this->assertSame([$visible->id], array_column($rows[$history->id]['nodes'], 'id'));
        $this->assertSame([], $rows[$comcast->id]['plans']);
        $this->assertSame($before, [$plan->fresh()->getRawOriginal(), $user->fresh()->getRawOriginal(), $visible->fresh()->getRawOriginal()]);
    }

    public function test_group_list_uses_bounded_queries_and_compact_clients_do_not_receive_details(): void
    {
        $groups = [];
        for ($i = 0; $i < 12; $i++) $groups[] = $this->group('Group ' . $i)->id;
        $this->node($groups);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = app(ServerGroupService::class)->summaries(true);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(12, $rows);
        $this->assertCount(3, $queries);
        $compact = app(ServerGroupService::class)->summaries(false)->first();
        $this->assertSame(1, $compact['server_count']);
        $this->assertArrayNotHasKey('nodes', $compact);
        $this->assertArrayNotHasKey('plans', $compact);
    }

    public function test_rename_refreshes_cached_name_and_preserves_membership_and_display_label(): void
    {
        $group = $this->group('三网优化线路');
        $base = $this->group('Base');
        $plan = $this->plan($base, ['addon_groups' => [$group->id => ['mode' => 'optional', 'label' => '三网优化线路', 'price' => 1000]]]);
        $node = $this->node([$group->id]);
        $user = $this->user(['admin_group_ids' => [$group->id]]);
        $before = [$plan->fresh()->getRawOriginal(), $node->fresh()->getRawOriginal(), $user->fresh()->getRawOriginal()];
        Cache::put(PlanCustomizationService::CACHE_GROUP_NAMES, [$group->id => $group->name], 60);
        Sanctum::actingAs($this->user(['is_admin' => true]));
        $this->postJson($this->endpoint('save'), ['id' => $group->id, 'name' => ' 三网优化线路·美国星链 '])->assertOk();
        $this->assertSame('三网优化线路·美国星链', $group->fresh()->name);
        $this->assertFalse(Cache::has(PlanCustomizationService::CACHE_GROUP_NAMES));
        $this->assertSame($before, [$plan->fresh()->getRawOriginal(), $node->fresh()->getRawOriginal(), $user->fresh()->getRawOriginal()]);
    }

    public static function references(): array
    {
        return array_map(fn ($kind) => [$kind], ['node', 'base_plan', 'addon_plan', 'pricing_only', 'purchased', 'admin', 'base_user']);
    }

    #[DataProvider('references')]
    public function test_referenced_groups_cannot_be_deleted(string $kind): void
    {
        $group = $this->group('Retained');
        $base = $this->group('Base');
        match ($kind) {
            'node' => $this->node([$group->id]),
            'base_plan' => $this->plan($group),
            'addon_plan' => $this->plan($base, ['addon_groups' => [$group->id => ['mode' => 'optional', 'price' => 1000]]]),
            'pricing_only' => $this->plan($base, ['traffic_topup' => ['group_prices' => [$group->id => 12]]]),
            'purchased' => $this->user(['plan_options' => ['addon_groups' => [$group->id]]]),
            'admin' => $this->user(['admin_group_ids' => [$group->id]]),
            'base_user' => $this->user(['group_id' => $group->id]),
        };
        Sanctum::actingAs($this->user(['is_admin' => true]));
        $this->postJson($this->endpoint('drop'), ['id' => $group->id])->assertStatus(400);
        $this->assertNotNull($group->fresh());
    }

    public function test_unused_group_can_be_deleted_and_invalid_names_are_rejected(): void
    {
        $group = $this->group('Unused');
        Sanctum::actingAs($this->user(['is_admin' => true]));
        foreach (['   ', str_repeat('x', 256), ['not a name']] as $name) {
            $this->postJson($this->endpoint('save'), ['id' => $group->id, 'name' => $name])->assertStatus(422);
        }
        $this->assertSame('Unused', $group->fresh()->name);
        $this->postJson($this->endpoint('save'), ['id' => 999999, 'name' => 'Missing'])->assertStatus(422);
        $this->postJson($this->endpoint('drop'), ['id' => $group->id])->assertOk();
        $this->assertNull($group->fresh());
    }
}
