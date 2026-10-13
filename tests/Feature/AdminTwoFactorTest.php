<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\AdminTwoFactor;
use App\Models\User;
use App\Services\Auth\AdminTwoFactorService;
use App\Services\AuthService;
use App\Support\Totp;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * 后台登录强制两步验证：密码只换来挑战、首次登录引导绑定、验证码 / 恢复码换 token、
 * 旧 token 与用户面板 token 进不了后台、重放与错满作废、账号安全页接口。
 */
class AdminTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        app()->forgetScopedInstances();
        config(['v2board.app_name' => '苏菲家宽', 'v2board.telegram_bot_enable' => 0]);
    }

    private function admin(array $attrs = []): User
    {
        return User::create(array_merge([
            'email' => 'admin@example.test',
            'password' => Hash::make(self::PASSWORD),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'is_admin' => 1,
        ], $attrs));
    }

    private function user(array $attrs = []): User
    {
        return User::create(array_merge([
            'email' => 'user@example.test',
            'password' => Hash::make(self::PASSWORD),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'is_admin' => 0,
        ], $attrs));
    }

    private function adminPath(string $path): string
    {
        return '/api/v2/' . admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))) . $path;
    }

    /** 已绑定验证器的管理员（直接写库），返回 [user, secret] */
    private function enrolledAdmin(): array
    {
        $admin = $this->admin();
        $secret = Totp::generateSecret();
        AdminTwoFactor::create([
            'user_id' => $admin->id,
            'secret' => $secret,
            'confirmed_at' => time() - 100,
            'recovery_codes' => [Hash::make('abcde-fghjk'), Hash::make('mnpqr-stuvw')],
            'last_used_step' => Totp::currentStep() - 10,
        ]);
        return [$admin, $secret];
    }

    private function challengeFor(User $admin): array
    {
        $res = $this->postJson($this->adminPath('/auth/login'), ['email' => $admin->email, 'password' => self::PASSWORD]);
        $res->assertOk();
        return $res->json('data');
    }

    public function test_totp_matches_rfc6238_vectors(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);
        $this->assertSame('287082', Totp::codeAt($secret, Totp::currentStep(59)));
        $this->assertSame('081804', Totp::codeAt($secret, Totp::currentStep(1111111109)));
        $this->assertSame('12345678901234567890', Totp::base32Decode($secret));
        $this->assertSame(Totp::currentStep(59), Totp::matchStep($secret, '287 082', 59));
        $this->assertNull(Totp::matchStep($secret, '000000', 59));
        $this->assertStringContainsString('otpauth://totp/%E8%8B%8F%E8%8F%B2%E5%AE%B6%E5%AE%BD:a%40b.c?secret=' . $secret, Totp::otpauthUrl('苏菲家宽', 'a@b.c', $secret));
    }

    public function test_user_panel_login_gives_admin_a_token_that_cannot_reach_admin_api(): void
    {
        $admin = $this->admin();
        $res = $this->postJson('/api/v1/passport/auth/login', ['email' => $admin->email, 'password' => self::PASSWORD]);
        $res->assertOk();
        $auth = $res->json('data.auth_data');
        $this->assertStringStartsWith('Bearer ', $auth);
        $this->assertTrue((bool) $res->json('data.is_admin'));

        $token = PersonalAccessToken::findToken(substr($auth, 7));
        $this->assertSame([AuthService::ABILITY_USER], $token->abilities);

        // 用户面板接口照常
        $this->withHeader('Authorization', $auth)->getJson('/api/v1/user/info')->assertOk();
        app('auth')->forgetGuards();
        // 后台接口被挡
        $this->withHeader('Authorization', $auth)->getJson($this->adminPath('/config/fetch'))
            ->assertStatus(403)->assertJsonPath('code', 'ADMIN_2FA_REQUIRED');
    }

    public function test_legacy_wildcard_token_is_rejected_by_admin_api(): void
    {
        $admin = $this->admin();
        $plain = $admin->createToken('legacy', ['*'], now()->addYear())->plainTextToken;
        $bearer = 'Bearer ' . explode('|', $plain)[1];
        $this->withHeader('Authorization', $bearer)->getJson($this->adminPath('/config/fetch'))
            ->assertStatus(403)->assertJsonPath('code', 'ADMIN_2FA_REQUIRED');
        $this->withHeader('Authorization', $bearer)->getJson('/api/v1/user/info')->assertOk();
    }

    public function test_admin_login_rejects_non_admin_and_wrong_password_without_distinguishing_them(): void
    {
        $this->user();
        $admin = $this->admin();

        $a = $this->postJson($this->adminPath('/auth/login'), ['email' => 'user@example.test', 'password' => self::PASSWORD]);
        $b = $this->postJson($this->adminPath('/auth/login'), ['email' => 'nobody@example.test', 'password' => self::PASSWORD]);
        $c = $this->postJson($this->adminPath('/auth/login'), ['email' => $admin->email, 'password' => 'wrong-password-1']);
        foreach ([$a, $b, $c] as $res) {
            $res->assertStatus(400);
            $this->assertSame(__('Incorrect email or password'), $res->json('message'));
            $this->assertNull($res->json('data'));
        }
        // 管理员邮箱的密码错误进了审计日志；非管理员邮箱不计
        $this->assertSame(1, AdminAuditLog::where('action', AdminTwoFactorService::ACTION_LOGIN_FAILED)->count());
        $this->assertSame($admin->id, (int) AdminAuditLog::first()->admin_id);
    }

    public function test_first_admin_login_requires_enrollment_and_issues_admin_token_after_confirm(): void
    {
        $admin = $this->admin();
        $challenge = $this->challengeFor($admin);
        $this->assertSame(AdminTwoFactorService::STATE_SETUP_REQUIRED, $challenge['two_factor']);
        $this->assertArrayNotHasKey('auth_data', $challenge);

        // 拿着挑战不能直接 verify
        $this->postJson($this->adminPath('/auth/two-factor/verify'), ['challenge' => $challenge['challenge'], 'otp' => '123456'])
            ->assertStatus(400);

        $setup = $this->postJson($this->adminPath('/auth/two-factor/setup'), ['challenge' => $challenge['challenge']]);
        $setup->assertOk();
        $secret = $setup->json('data.secret');
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertStringStartsWith('otpauth://totp/', $setup->json('data.otpauth_url'));
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $setup->json('data.qr_svg'));
        $this->assertStringContainsString('<svg', base64_decode(substr($setup->json('data.qr_svg'), 26)));

        // 错一次不作废
        $this->postJson($this->adminPath('/auth/two-factor/confirm'), ['challenge' => $challenge['challenge'], 'otp' => '000000'])
            ->assertStatus(400);

        $code = Totp::codeAt($secret, Totp::currentStep());
        $confirm = $this->postJson($this->adminPath('/auth/two-factor/confirm'), ['challenge' => $challenge['challenge'], 'otp' => $code]);
        $confirm->assertOk();
        $codes = $confirm->json('data.recovery_codes');
        $this->assertCount(AdminTwoFactorService::RECOVERY_CODE_COUNT, $codes);
        foreach ($codes as $c) {
            $this->assertMatchesRegularExpression('/^[a-z2-9]{5}-[a-z2-9]{5}$/', $c);
        }
        $auth = $confirm->json('data.auth.auth_data');
        $this->assertStringStartsWith('Bearer ', $auth);

        // 挑战用完即废
        $this->postJson($this->adminPath('/auth/two-factor/confirm'), ['challenge' => $challenge['challenge'], 'otp' => $code])
            ->assertStatus(401);

        // 后台 token：带 admin 能力、按设置天数过期
        $token = PersonalAccessToken::findToken(substr($auth, 7));
        $this->assertSame([AuthService::ABILITY_USER, AuthService::ABILITY_ADMIN], $token->abilities);
        $this->assertEqualsWithDelta(now()->addDays(AuthService::ADMIN_SESSION_DAYS_DEFAULT)->getTimestamp(), $token->expires_at->getTimestamp(), 5);
        $this->assertStringStartsWith('admin|', $token->name);

        app('auth')->forgetGuards();
        $this->withHeader('Authorization', $auth)->getJson($this->adminPath('/config/fetch'))->assertOk();
        $status = $this->withHeader('Authorization', $auth)->getJson($this->adminPath('/security/two-factor'));
        $status->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.recovery_codes_remaining', 8);

        // 库里的密钥是密文
        $raw = \DB::table('v2_admin_two_factor')->where('user_id', $admin->id)->value('secret');
        $this->assertStringNotContainsString($secret, (string) $raw);
        $this->assertNotNull($admin->fresh()->last_login_at);
        $this->assertSame(1, AdminAuditLog::where('action', AdminTwoFactorService::ACTION_TWO_FACTOR_ENROLLED)->count());
        $this->assertSame(1, AdminAuditLog::where('action', AdminTwoFactorService::ACTION_LOGIN)->count());
    }

    public function test_enrolled_admin_logs_in_with_totp_and_the_same_code_cannot_be_replayed(): void
    {
        [$admin, $secret] = $this->enrolledAdmin();
        $challenge = $this->challengeFor($admin);
        $this->assertSame(AdminTwoFactorService::STATE_REQUIRED, $challenge['two_factor']);

        // 已绑定的账号不能再走 setup（否则拿到密码的人能换掉验证器）
        $this->postJson($this->adminPath('/auth/two-factor/setup'), ['challenge' => $challenge['challenge']])->assertStatus(400);

        $code = Totp::codeAt($secret, Totp::currentStep());
        $verify = $this->postJson($this->adminPath('/auth/two-factor/verify'), ['challenge' => $challenge['challenge'], 'otp' => $code]);
        $verify->assertOk()->assertJsonPath('data.used_recovery_code', false)->assertJsonPath('data.recovery_codes_remaining', 2);
        $auth = $verify->json('data.auth.auth_data');
        app('auth')->forgetGuards();
        $this->withHeader('Authorization', $auth)->getJson($this->adminPath('/config/fetch'))->assertOk();

        // 同一个码在新的挑战里重放：拒绝
        $challenge2 = $this->challengeFor($admin);
        $this->postJson($this->adminPath('/auth/two-factor/verify'), ['challenge' => $challenge2['challenge'], 'otp' => $code])
            ->assertStatus(400);
    }

    public function test_recovery_code_logs_in_once_and_is_consumed(): void
    {
        [$admin] = $this->enrolledAdmin();
        $challenge = $this->challengeFor($admin);
        $verify = $this->postJson($this->adminPath('/auth/two-factor/verify'), ['challenge' => $challenge['challenge'], 'otp' => 'ABCDE FGHJK']);
        $verify->assertOk()->assertJsonPath('data.used_recovery_code', true)->assertJsonPath('data.recovery_codes_remaining', 1);

        $challenge2 = $this->challengeFor($admin);
        $this->postJson($this->adminPath('/auth/two-factor/verify'), ['challenge' => $challenge2['challenge'], 'otp' => 'abcde-fghjk'])
            ->assertStatus(400);
        $this->assertSame(1, AdminAuditLog::where('action', AdminTwoFactorService::ACTION_RECOVERY_CODE_USED)->count());
    }

    public function test_challenge_is_void_after_five_wrong_codes(): void
    {
        [$admin, $secret] = $this->enrolledAdmin();
        $challenge = $this->challengeFor($admin);
        for ($i = 1; $i <= AdminTwoFactorService::CHALLENGE_MAX_ATTEMPTS; $i++) {
            $res = $this->postJson($this->adminPath('/auth/two-factor/verify'), ['challenge' => $challenge['challenge'], 'otp' => '000000']);
            $res->assertStatus(400);
        }
        $this->assertStringContainsString('重新登录', $res->json('message'));
        // 正确的码也救不回这个挑战
        $this->postJson($this->adminPath('/auth/two-factor/verify'), [
            'challenge' => $challenge['challenge'], 'otp' => Totp::codeAt($secret, Totp::currentStep()),
        ])->assertStatus(401);
        $this->assertSame(1, AdminAuditLog::where('action', AdminTwoFactorService::ACTION_TWO_FACTOR_LOCKED)->count());
    }

    public function test_password_lockout_applies_to_admin_login(): void
    {
        $admin = $this->admin();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson($this->adminPath('/auth/login'), ['email' => $admin->email, 'password' => 'wrong-password-1'])->assertStatus(400);
        }
        $this->postJson($this->adminPath('/auth/login'), ['email' => $admin->email, 'password' => self::PASSWORD])->assertStatus(429);
    }

    public function test_banned_or_demoted_admin_cannot_finish_a_pending_challenge(): void
    {
        [$admin, $secret] = $this->enrolledAdmin();
        $challenge = $this->challengeFor($admin);
        $admin->is_admin = 0;
        $admin->save();
        $this->postJson($this->adminPath('/auth/two-factor/verify'), [
            'challenge' => $challenge['challenge'], 'otp' => Totp::codeAt($secret, Totp::currentStep()),
        ])->assertStatus(401);
    }

    public function test_security_page_rebind_recovery_codes_and_sessions(): void
    {
        [$admin, $secret] = $this->enrolledAdmin();
        $auth = (new AuthService($admin))->generateAdminAuthData('1.2.3.4', 'Mozilla/5.0 Test')['auth_data'];
        $other = (new AuthService($admin))->generateAdminAuthData('5.6.7.8', 'Mozilla/5.0 Other')['auth_data'];
        $userPanel = (new AuthService($admin))->generateAuthData()['auth_data'];
        $h = ['Authorization' => $auth];

        // 会话列表：3 条，当前会话有标记，用户面板的会话不带 admin
        $sessions = $this->withHeaders($h)->getJson($this->adminPath('/security/sessions'));
        $sessions->assertOk();
        $rows = collect($sessions->json('data'));
        $this->assertCount(3, $rows);
        $this->assertSame(1, $rows->where('current', true)->count());
        $this->assertSame(2, $rows->where('admin', true)->count());
        $this->assertSame('1.2.3.4', $rows->firstWhere('current', true)['ip']);

        // 重新绑定要先验当前验证码。下面每一步之间都清掉防重放记录（同一秒内只有一个时间步的码可用），
        // 重放本身在上面的用例里单独验证。
        $service = app(AdminTwoFactorService::class);
        $unlock = fn() => AdminTwoFactor::where('user_id', $admin->id)->update(['last_used_step' => null]);
        $step = Totp::currentStep();
        $this->withHeaders($h)->postJson($this->adminPath('/security/two-factor/setup'), ['otp' => '000000'])->assertStatus(400);
        $setup = $this->withHeaders($h)->postJson($this->adminPath('/security/two-factor/setup'), ['otp' => Totp::codeAt($secret, $step)]);
        $setup->assertOk();
        $newSecret = $setup->json('data.secret');
        $this->assertNotSame($secret, $newSecret);
        // 确认之前旧密钥仍然有效，新密钥还不能用
        $unlock();
        $this->assertTrue($service->verifyCode($admin, Totp::codeAt($secret, $step))['ok']);
        $unlock();
        $this->assertFalse($service->verifyCode($admin, Totp::codeAt($newSecret, $step))['ok']);
        // 用旧密钥的码确认新绑定：拒绝
        $this->withHeaders($h)->postJson($this->adminPath('/security/two-factor/confirm'), ['otp' => Totp::codeAt($secret, $step)])->assertStatus(400);
        $confirm = $this->withHeaders($h)->postJson($this->adminPath('/security/two-factor/confirm'), ['otp' => Totp::codeAt($newSecret, $step)]);
        $confirm->assertOk();
        $this->assertCount(8, $confirm->json('data.recovery_codes'));
        $unlock();
        $this->assertFalse($service->verifyCode($admin, Totp::codeAt($secret, $step))['ok']);
        $unlock();
        $this->assertTrue($service->verifyCode($admin, Totp::codeAt($newSecret, $step))['ok']);

        // 重新生成恢复码
        $unlock();
        $regen = $this->withHeaders($h)->postJson($this->adminPath('/security/two-factor/recovery-codes'), ['otp' => Totp::codeAt($newSecret, $step)]);
        $regen->assertOk();
        $this->assertCount(8, $regen->json('data.recovery_codes'));
        $this->assertNotEquals($confirm->json('data.recovery_codes'), $regen->json('data.recovery_codes'));

        // 踢掉其他会话：另一个后台会话与用户面板会话都失效，当前会话保留
        $this->withHeaders($h)->postJson($this->adminPath('/security/sessions/revoke-others'))->assertOk()->assertJsonPath('data.revoked', 2);
        // 同一个测试里换 token 发请求：sanctum guard 会缓存上一次解析出的用户，先清掉
        app('auth')->forgetGuards();
        $this->withHeader('Authorization', $other)->getJson($this->adminPath('/config/fetch'))->assertStatus(403);
        app('auth')->forgetGuards();
        $this->withHeader('Authorization', $userPanel)->getJson('/api/v1/user/info')->assertStatus(403);
        app('auth')->forgetGuards();
        $this->withHeaders($h)->getJson($this->adminPath('/config/fetch'))->assertOk();

        // 登录记录接口只回 auth.* 事件
        $log = $this->withHeaders($h)->getJson($this->adminPath('/security/login-log'));
        $log->assertOk();
        foreach ($log->json('data') as $row) {
            $this->assertStringStartsWith('auth.', $row['action']);
        }
        $this->assertNotEmpty($log->json('data'));
        // 审计日志里不出现验证码
        $this->assertSame(0, AdminAuditLog::where('request_data', 'like', '%' . Totp::codeAt($newSecret, $step) . '%')->count());
        $this->assertSame(0, AdminAuditLog::where('request_data', 'like', '%' . Totp::codeAt($secret, $step) . '%')->count());
    }

    public function test_reset_command_clears_enrollment_and_sessions(): void
    {
        [$admin] = $this->enrolledAdmin();
        $auth = (new AuthService($admin))->generateAdminAuthData()['auth_data'];
        $this->artisan('admin:two-factor-reset', ['email' => $admin->email])->expectsConfirmation("将解除 {$admin->email} 的两步验证并注销其全部登录会话，继续？", 'yes')->assertExitCode(0);
        $this->assertNull(AdminTwoFactor::find($admin->id));
        app('auth')->forgetGuards();
        $this->withHeader('Authorization', $auth)->getJson($this->adminPath('/config/fetch'))->assertStatus(403);
        $this->assertSame(AdminTwoFactorService::STATE_SETUP_REQUIRED, $this->challengeFor($admin)['two_factor']);
    }

    public function test_session_days_setting_is_validated_and_applied(): void
    {
        $this->assertTrue(\Validator::make(['admin_session_days' => 30], \App\Http\Requests\Admin\ConfigSave::RULES)->passes());
        $this->assertFalse(\Validator::make(['admin_session_days' => 0], \App\Http\Requests\Admin\ConfigSave::RULES)->passes());
        $this->assertFalse(\Validator::make(['admin_session_days' => 400], \App\Http\Requests\Admin\ConfigSave::RULES)->passes());
        config(['v2board.admin_session_days' => 3]);
        $admin = $this->admin();
        $auth = (new AuthService($admin))->generateAdminAuthData()['auth_data'];
        $token = PersonalAccessToken::findToken(substr($auth, 7));
        $this->assertEqualsWithDelta(now()->addDays(3)->getTimestamp(), $token->expires_at->getTimestamp(), 5);
    }
}
