<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\User;
use App\Services\AuthService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 生产日志 2026-10-21 里的 "Call to a member function load() on null"：
 * 查不到的工单 / 用户 id 原来在 null 上调用 load()，用户和后台拿到 500。现在是明确的 400。
 */
class NullGuardRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
    }

    private function user(string $email, int $isAdmin = 0): User
    {
        return User::create([
            'email' => $email,
            'password' => Hash::make('correct-horse-battery'),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'is_admin' => $isAdmin,
        ]);
    }

    public function test_fetching_someone_elses_ticket_is_a_400_not_a_500(): void
    {
        $owner = $this->user('owner@example.test');
        $other = $this->user('other@example.test');
        $ticket = Ticket::create([
            'user_id' => $owner->id,
            'subject' => '别人的工单',
            'level' => 0,
            'status' => 0,
            'reply_status' => 0,
        ]);

        Sanctum::actingAs($other);
        $this->getJson('/api/v1/user/ticket/fetch?id=' . $ticket->id)->assertStatus(400);
        $this->getJson('/api/v1/user/ticket/fetch?id=999999')->assertStatus(400);

        app('auth')->forgetGuards();
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/user/ticket/fetch?id=' . $ticket->id)
            ->assertOk()
            ->assertJsonPath('data.id', $ticket->id)
            ->assertJsonPath('data.message', []);
    }

    public function test_admin_fetching_a_missing_user_is_a_400_not_a_500(): void
    {
        $admin = $this->user('admin@example.test', 1);
        Sanctum::actingAs($admin, [AuthService::ABILITY_USER, AuthService::ABILITY_ADMIN]);
        $path = '/api/v2/' . admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));

        $this->getJson($path . '/user/getUserInfoById?id=999999')
            ->assertStatus(400)
            ->assertJsonPath('message', '用户不存在');
        $this->getJson($path . '/user/getUserInfoById?id=' . $admin->id)
            ->assertOk()
            ->assertJsonPath('data.id', $admin->id);
    }
}
