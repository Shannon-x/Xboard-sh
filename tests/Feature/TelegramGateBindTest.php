<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuthService;
use App\Services\Telegram\GateBindTicket;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 群管理机器人「一键绑定」：面板登录用户确认后，把机器人票据里的 Telegram 绑到自己账号上。
 */
class TelegramGateBindTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-gate-secret-0123456789abcdef0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram_group_gate.secret' => self::SECRET]);
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

    private function ticket(int $tg = 9001, array $over = []): string
    {
        return GateBindTicket::sign(array_merge([
            'v' => 1, 'tg' => $tg, 'n' => 'Alice', 'u' => 'alice', 'b' => 'sufe_bot', 'exp' => time() + 900,
        ], $over), self::SECRET);
    }

    private function bind(User $user, array $body)
    {
        $auth = (new AuthService($user))->generateAuthData('1.2.3.4', 'Mozilla/5.0 Test')['auth_data'];
        app('auth')->forgetGuards();
        return $this->withHeader('Authorization', $auth)->postJson('/api/v1/user/telegram/gateBind', $body);
    }

    public function test_requires_login(): void
    {
        $this->postJson('/api/v1/user/telegram/gateBind', ['ticket' => $this->ticket()])->assertStatus(403);
    }

    public function test_preview_then_bind(): void
    {
        $user = $this->makeUser('a@example.test');
        $t = $this->ticket();

        $this->bind($user, ['ticket' => $t, 'preview' => true])->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.telegram.name', 'Alice')
            ->assertJsonPath('data.bot', 'sufe_bot');
        $this->assertNull($user->fresh()->telegram_id);

        $this->bind($user, ['ticket' => $t])->assertOk()->assertJsonPath('data.status', 'bound');
        $this->assertSame(9001, (int) $user->fresh()->telegram_id);
    }

    public function test_ticket_is_single_use(): void
    {
        $user = $this->makeUser('a@example.test');
        $t = $this->ticket();
        $this->bind($user, ['ticket' => $t])->assertJsonPath('data.status', 'bound');
        $other = $this->makeUser('b@example.test');
        $this->bind($other, ['ticket' => $t])->assertJsonPath('data.status', 'invalid');
        $this->assertNull($other->fresh()->telegram_id);
    }

    public function test_rejects_forged_or_expired_ticket(): void
    {
        $user = $this->makeUser('a@example.test');
        $forged = GateBindTicket::sign(['v' => 1, 'tg' => 1, 'n' => 'x', 'exp' => time() + 900], 'wrong-secret');
        $this->bind($user, ['ticket' => $forged])->assertJsonPath('data.status', 'invalid');
        $this->bind($user, ['ticket' => $this->ticket(9001, ['exp' => time() - 1])])->assertJsonPath('data.status', 'invalid');
        $this->bind($user, ['ticket' => $this->ticket(9001, ['exp' => time() + 86400])])->assertJsonPath('data.status', 'invalid');
        $this->assertNull($user->fresh()->telegram_id);
    }

    public function test_does_not_take_a_telegram_bound_elsewhere(): void
    {
        $this->makeUser('owner@example.test', ['telegram_id' => 9001]);
        $user = $this->makeUser('a@example.test');
        $this->bind($user, ['ticket' => $this->ticket()])->assertJsonPath('data.status', 'telegram_taken');
        $this->assertNull($user->fresh()->telegram_id);
    }

    public function test_replacing_another_telegram_needs_confirmation(): void
    {
        $user = $this->makeUser('a@example.test', ['telegram_id' => 1234]);
        $t = $this->ticket();
        $this->bind($user, ['ticket' => $t])->assertJsonPath('data.status', 'needs_replace');
        $this->assertSame(1234, (int) $user->fresh()->telegram_id);

        $this->bind($user, ['ticket' => $t, 'replace' => true])->assertJsonPath('data.status', 'bound');
        $this->assertSame(9001, (int) $user->fresh()->telegram_id);
    }

    public function test_already_bound_is_a_no_op(): void
    {
        $user = $this->makeUser('a@example.test', ['telegram_id' => 9001]);
        $this->bind($user, ['ticket' => $this->ticket()])->assertJsonPath('data.status', 'already');
    }
}
