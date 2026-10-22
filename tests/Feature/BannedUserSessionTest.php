<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuthService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * 封禁要立刻对已登录的会话生效：banned 原本只在登录时检查，封号前签发的 token 照样能调用户接口。
 * 2026-10 批量封掉的渗透小号拿旧 token 还能开工单、下单。
 */
class BannedUserSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        app()->forgetScopedInstances();
    }

    private function makeUser(string $email, array $attrs = []): User
    {
        return User::create(array_merge([
            'email' => $email,
            'password' => Hash::make('secret-password'),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'is_admin' => 0,
        ], $attrs));
    }

    private function adminPath(string $path): string
    {
        return '/api/v2/' . admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))) . $path;
    }

    public function test_existing_user_token_is_rejected_once_user_is_banned(): void
    {
        $user = $this->makeUser('victim@example.test');
        $auth = (new AuthService($user))->generateAuthData('1.2.3.4', 'Mozilla/5.0 Test')['auth_data'];

        $this->withHeader('Authorization', $auth)->getJson('/api/v1/user/info')->assertOk();

        // 绕过后台单个封禁（那条路径会顺手清 token），模拟直接改库 / 批量封禁
        User::whereKey($user->id)->update(['banned' => 1]);
        app('auth')->forgetGuards();

        $this->withHeader('Authorization', $auth)->getJson('/api/v1/user/info')->assertStatus(403);
        app('auth')->forgetGuards();
        $this->withHeader('Authorization', $auth)->postJson('/api/v1/user/ticket/save', [
            'subject' => 's', 'level' => 0, 'message' => 'm',
        ])->assertStatus(403);
    }

    public function test_banned_admin_token_is_rejected_by_admin_api(): void
    {
        $admin = $this->makeUser('admin@example.test', ['is_admin' => 1]);
        $auth = (new AuthService($admin))->generateAdminAuthData('1.2.3.4', 'Mozilla/5.0 Test')['auth_data'];

        User::whereKey($admin->id)->update(['banned' => 1]);

        $this->withHeader('Authorization', $auth)->getJson($this->adminPath('/config/fetch'))->assertStatus(403);
    }

    public function test_batch_ban_revokes_sessions_of_banned_users_only(): void
    {
        $admin = $this->makeUser('admin@example.test', ['is_admin' => 1]);
        $adminAuth = (new AuthService($admin))->generateAdminAuthData('1.2.3.4', 'Mozilla/5.0 Test')['auth_data'];
        $target = $this->makeUser('target@example.test');
        $bystander = $this->makeUser('bystander@example.test');
        (new AuthService($target))->generateAuthData('5.6.7.8', 'Mozilla/5.0 Target');
        (new AuthService($bystander))->generateAuthData('9.9.9.9', 'Mozilla/5.0 Bystander');

        $this->withHeader('Authorization', $adminAuth)
            ->postJson($this->adminPath('/user/ban'), ['scope' => 'selected', 'user_ids' => [$target->id]])
            ->assertOk();

        $this->assertSame(1, (int) $target->fresh()->banned);
        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $target->id)->count());
        $this->assertSame(0, (int) $bystander->fresh()->banned);
        $this->assertSame(1, PersonalAccessToken::where('tokenable_id', $bystander->id)->count());
    }
}
