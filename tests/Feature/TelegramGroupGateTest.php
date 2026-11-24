<?php

namespace Tests\Feature;

use App\Http\Controllers\V1\Guest\TelegramGateController;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 群管理机器人的入群门槛：只让面板注册、未封禁的用户进群（付费与否不论）。
 */
class TelegramGroupGateTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-gate-secret-0123456789abcdef0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram_group_gate.secret' => self::SECRET]);
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

    private function gate(string $op, array $body, ?string $secret = self::SECRET)
    {
        $headers = $secret === null ? [] : ['X-Gate-Secret' => $secret];
        return $this->withHeaders($headers)->postJson("/api/v1/guest/telegram/gate/{$op}", $body);
    }

    public function test_routes_are_closed_when_secret_not_configured(): void
    {
        config(['services.telegram_group_gate.secret' => '']);
        $this->gate('check', ['telegram_id' => 1])->assertNotFound();
    }

    public function test_wrong_or_missing_secret_is_rejected(): void
    {
        $this->gate('check', ['telegram_id' => 1], 'nope')->assertStatus(401);
        $this->gate('check', ['telegram_id' => 1], null)->assertStatus(401);
    }

    public function test_check_reports_member_banned_and_unbound(): void
    {
        // 没有套餐、没有流量的免费注册用户也算 member
        $this->makeUser('free@example.test', ['telegram_id' => 1001]);
        $this->makeUser('banned@example.test', ['telegram_id' => 1002, 'banned' => 1]);

        $this->gate('check', ['telegram_id' => 1001])->assertOk()->assertJsonPath('data.status', 'member');
        $this->gate('check', ['telegram_id' => 1002])->assertOk()->assertJsonPath('data.status', 'banned');
        $this->gate('check', ['telegram_id' => 1003])->assertOk()->assertJsonPath('data.status', 'unbound');
    }

    public function test_check_response_carries_no_account_details(): void
    {
        $this->makeUser('private@example.test', ['telegram_id' => 1001]);
        $data = $this->gate('check', ['telegram_id' => 1001])->assertOk()->json('data');
        $this->assertSame(['status'], array_keys($data));
    }

    public function test_bind_with_subscribe_url_writes_telegram_id(): void
    {
        $user = $this->makeUser('new@example.test');

        $this->gate('bind', ['telegram_id' => 2001, 'subscribe_url' => "https://sub.example.test/api/v1/client/subscribe?token={$user->token}"])
            ->assertOk()->assertJsonPath('data.status', 'bound');

        $this->assertSame(2001, (int) $user->fresh()->telegram_id);
        $this->gate('check', ['telegram_id' => 2001])->assertJsonPath('data.status', 'member');
    }

    public function test_bind_accepts_path_style_subscribe_url(): void
    {
        $user = $this->makeUser('path@example.test');
        $this->gate('bind', ['telegram_id' => 2002, 'subscribe_url' => "https://sub.example.test/s/{$user->token}"])
            ->assertOk()->assertJsonPath('data.status', 'bound');
    }

    public function test_bind_rejects_unknown_or_malformed_link(): void
    {
        $this->gate('bind', ['telegram_id' => 2003, 'subscribe_url' => 'https://sub.example.test/s/' . str_repeat('a', 32)])
            ->assertOk()->assertJsonPath('data.status', 'invalid_link');
        $this->gate('bind', ['telegram_id' => 2003, 'subscribe_url' => 'hello'])
            ->assertOk()->assertJsonPath('data.status', 'invalid_link');
    }

    public function test_bind_does_not_steal_an_account_bound_to_another_telegram(): void
    {
        $user = $this->makeUser('taken@example.test', ['telegram_id' => 3001]);
        $this->gate('bind', ['telegram_id' => 3002, 'subscribe_url' => "https://x.test/s/{$user->token}"])
            ->assertOk()->assertJsonPath('data.status', 'account_taken');
        $this->assertSame(3001, (int) $user->fresh()->telegram_id);
    }

    public function test_bind_refuses_banned_account(): void
    {
        $user = $this->makeUser('b@example.test', ['banned' => 1]);
        $this->gate('bind', ['telegram_id' => 4001, 'subscribe_url' => "https://x.test/s/{$user->token}"])
            ->assertOk()->assertJsonPath('data.status', 'banned');
        $this->assertNull($user->fresh()->telegram_id);
    }

    public function test_bind_for_already_bound_telegram_reports_existing_status(): void
    {
        $this->makeUser('a@example.test', ['telegram_id' => 5001]);
        $other = $this->makeUser('c@example.test');
        $this->gate('bind', ['telegram_id' => 5001, 'subscribe_url' => "https://x.test/s/{$other->token}"])
            ->assertOk()->assertJsonPath('data.status', 'member');
        $this->assertNull($other->fresh()->telegram_id);
    }

    public function test_extract_subscribe_token(): void
    {
        $t = str_repeat('ab', 16);
        $this->assertSame($t, TelegramGateController::extractSubscribeToken("https://a.test/sub?token=" . strtoupper($t)));
        $this->assertSame($t, TelegramGateController::extractSubscribeToken("https://a.test/s/{$t}/"));
        $this->assertNull(TelegramGateController::extractSubscribeToken('https://a.test/s/short'));
    }
}
