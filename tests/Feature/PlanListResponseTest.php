<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlanListResponseTest extends TestCase
{
    use RefreshDatabase;

    public static function listEndpoints(): array
    {
        return [
            'guest' => ['/api/v1/guest/plan/fetch?include_addon_groups=1', false],
            'user' => ['/api/v1/user/plan/fetch?include_addon_groups=1', true],
            'customization user' => ['/api/v1/user/plan/fetch?include_customization=1&include_addon_groups=1', true],
        ];
    }

    private function signIn(): void
    {
        Sanctum::actingAs(User::create([
            'email' => Str::random(12) . '@example.test', 'password' => 'test',
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'balance' => 0, 'transfer_enable' => 0, 'expired_at' => 0,
        ]));
    }

    private function plan(int $sort, ?int $capacity = null, ?array $customization = null): Plan
    {
        return Plan::create([
            'name' => 'Plan ' . $sort, 'sort' => $sort, 'group_id' => 17,
            'capacity_limit' => $capacity, 'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => 100,
            'reset_traffic_method' => 1, 'prices' => ['monthly' => 3],
            'customization' => $customization,
        ]);
    }

    #[DataProvider('listEndpoints')]
    public function test_filtered_list_is_a_json_array_and_addon_ids_remain_object_keys(string $path, bool $authenticated): void
    {
        if ($authenticated) {
            $this->signIn();
        }

        $group = new ServerGroup();
        $group->id = 23;
        $group->name = 'Premium';
        $group->save();

        // Removing the first and middle sold-out entries leaves collection keys 1 and 3.
        $this->plan(1, 0);
        $fixed = $this->plan(2);
        $this->plan(3, 0);
        $custom = $this->plan(4, null, [
            'transfer_enable' => ['mode' => 'fixed'],
            'device_limit' => ['mode' => 'fixed'],
            'speed_limit' => ['mode' => 'fixed'],
            'addon_groups' => [$group->id => ['mode' => 'optional', 'price' => 500]],
        ]);

        $response = $this->getJson($path)->assertOk()->assertJsonPath('status', 'success');
        // Decode without associative mode: an HTTP 200 JSON object is not a list.
        $payload = json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload->data, $response->getContent());
        $this->assertSame([$fixed->id, $custom->id], array_column($payload->data, 'id'));
        $this->assertSame(300.0, (float) $payload->data[0]->month_price);
        $this->assertNull($payload->data[0]->customization);

        $addons = $payload->data[1]->customization->addon_groups;
        $this->assertInstanceOf(\stdClass::class, $addons);
        $this->assertSame(['23'], array_map('strval', array_keys(get_object_vars($addons))));
        $this->assertSame('Premium', $addons->{'23'}->name);
        $this->assertSame(500, $addons->{'23'}->price);
    }

    #[DataProvider('listEndpoints')]
    public function test_all_sold_out_plans_return_an_empty_json_array(string $path, bool $authenticated): void
    {
        if ($authenticated) {
            $this->signIn();
        }
        $this->plan(1, 0);
        $payload = json_decode($this->getJson($path)->assertOk()->getContent(), false, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], $payload->data);
    }
}
