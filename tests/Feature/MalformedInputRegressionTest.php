<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 生产日志 2026-10-24 ~ 10-27（渗透第四~六波）：参数传成数组 / 非数字 / 缺失时直接进了
 * 类型化代码，用户与节点接口拿到 500。现在都是明确的 4xx，正常参数行为不变。
 */
class MalformedInputRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        app()->forgetScopedInstances();
    }

    private function user(string $email): User
    {
        return User::create([
            'email' => $email,
            'password' => Hash::make('correct-horse-battery'),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
        ]);
    }

    #[Test]
    public function passport_rate_limiter_tolerates_an_array_email(): void
    {
        // RouteServiceProvider 的限流键原来 (string) 强转数组 → Array to string → 500
        $this->postJson('/api/v1/passport/auth/login', ['email' => ['a@example.test'], 'password' => 'x'])
            ->assertStatus(422);
        $this->postJson('/api/v1/passport/auth/forget', ['email' => ['a@example.test'], 'email_code' => '1', 'password' => 'xxxxxxxx'])
            ->assertStatus(422);

        $this->user('real@example.test');
        $this->postJson('/api/v1/passport/auth/login', ['email' => 'real@example.test', 'password' => 'correct-horse-battery'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['auth_data']]);
    }

    #[Test]
    public function non_numeric_page_parameters_fall_back_to_defaults(): void
    {
        Sanctum::actingAs($this->user('pager@example.test'));

        $this->getJson('/api/v1/user/notice/fetch?current=abc')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/user/invite/details?current=abc&page_size=xyz')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/user/invite/details?current[]=1')->assertOk();
    }

    #[Test]
    public function gift_card_check_requires_a_code(): void
    {
        Sanctum::actingAs($this->user('gift@example.test'));

        $this->postJson('/api/v1/user/gift-card/check', [])->assertStatus(422);
        $this->postJson('/api/v1/user/gift-card/check', ['code' => ['x']])->assertStatus(422);
    }

    #[Test]
    public function remove_active_session_validates_session_id(): void
    {
        $user = $this->user('sessions@example.test');
        $keep = $user->createToken('keep');
        $drop = $user->createToken('drop');
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/user/removeActiveSession', [])->assertStatus(422);
        $this->postJson('/api/v1/user/removeActiveSession', ['session_id' => ['1']])->assertStatus(422);

        $this->postJson('/api/v1/user/removeActiveSession', ['session_id' => $drop->accessToken->id])->assertOk();
        $this->assertSame([$keep->accessToken->id], $user->tokens()->pluck('id')->all());
    }

    #[Test]
    public function order_save_rejects_a_non_string_coupon_code(): void
    {
        Sanctum::actingAs($this->user('order@example.test'));

        // 原来数组直接传进 OrderService::createFromRequest(?string $couponCode) → TypeError → 500
        $this->postJson('/api/v1/user/order/save', [
            'plan_id' => 1,
            'period' => 'monthly',
            'coupon_code' => ['x'],
        ])->assertStatus(422)->assertJsonValidationErrors('coupon_code');
    }

    #[Test]
    public function node_api_rejects_array_parameters_without_a_500(): void
    {
        admin_setting(['server_token' => 'node-token']);
        $node = Server::create([
            'type' => 'vless', 'name' => 'n', 'rate' => 1, 'group_ids' => ['1'], 'show' => true,
            'host' => 'n.example.test', 'port' => '443', 'server_port' => 443,
        ]);
        $base = '/api/v1/server/UniProxy/config';

        $this->getJson("{$base}?token[]=node-token&node_id={$node->id}&node_type=vless")->assertStatus(422);
        $this->getJson("{$base}?token=node-token&node_id[]={$node->id}&node_type=vless")->assertStatus(422);
        $this->getJson("{$base}?token=node-token&node_id={$node->id}&node_type[]=vless")->assertStatus(422);
        $this->getJson("{$base}?token=wrong&node_id={$node->id}&node_type=vless")->assertStatus(422);

        $this->getJson("{$base}?token=node-token&node_id={$node->id}&node_type=vless")->assertOk();
    }
}
