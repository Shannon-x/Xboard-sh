<?php

namespace Tests\Feature;

use App\Jobs\SendEmailJob;
use App\Models\Plan;
use App\Models\PlanRestockNotify;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\Mail\DeliveryMonitor;
use App\Services\RestockNotifier;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 登录、邮件链接登录、退信记录、到货提醒按邮箱找账号时，以字节完全一致为准；
 * 注册与新地址的验证码只收 ASCII 邮箱。
 *
 * 与 PasswordRecoveryEmailMatchTest 一样，给 v2_user.email 挂一个不区分重音和大小写的排序规则，
 * 模拟 MySQL utf8mb4_unicode_ci。
 */
class AccountEmailExactMatchTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';
    private const OWNER = 'owner@gmail.com';
    private const VARIANT = 'owner@gmáil.com';

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        app()->forgetScopedInstances();
        config(['v2board.telegram_bot_enable' => 0]);

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateCollation('XB_ACCENT_CI', function (string $a, string $b): int {
                return strcmp(self::fold($a), self::fold($b));
            });
            Schema::table('v2_user', function ($table) {
                $table->string('email', 64)->collation('XB_ACCENT_CI')->change();
            });
        }
    }

    private static function fold(string $value): string
    {
        $decomposed = \Normalizer::normalize($value, \Normalizer::FORM_D);
        return mb_strtolower(preg_replace('/\p{Mn}+/u', '', $decomposed === false ? $value : $decomposed));
    }

    private function makeUser(string $email, array $attrs = []): User
    {
        return User::create(array_merge([
            'email' => $email,
            'password' => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
        ], $attrs));
    }

    private function adminPath(string $path): string
    {
        return '/api/v2/' . admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))) . $path;
    }

    public function test_collation_matches_variant_row(): void
    {
        $this->makeUser(self::OWNER);
        $this->assertSame(1, User::where('email', self::VARIANT)->count());
    }

    public function test_login_requires_exact_address(): void
    {
        $this->makeUser(self::OWNER);

        $this->postJson('/api/v1/passport/auth/login', ['email' => self::VARIANT, 'password' => self::PASSWORD])
            ->assertStatus(400);
        $this->postJson('/api/v1/passport/auth/login', ['email' => 'Owner@Gmail.com', 'password' => self::PASSWORD])
            ->assertOk();
    }

    public function test_password_lock_applies_to_the_account_address(): void
    {
        $this->makeUser(self::OWNER);
        $limit = (int) admin_setting('password_limit_count', 5);
        for ($i = 0; $i < $limit; $i++) {
            $this->postJson('/api/v1/passport/auth/login', ['email' => self::OWNER, 'password' => 'wrong-password-' . $i]);
        }
        $this->postJson('/api/v1/passport/auth/login', ['email' => self::OWNER, 'password' => self::PASSWORD])
            ->assertStatus(429);

        // 换一种写法不能再对同一个账号继续试
        $this->postJson('/api/v1/passport/auth/login', ['email' => self::VARIANT, 'password' => self::PASSWORD])
            ->assertStatus(400);
        $this->assertSame(0, (int) Cache::get(CacheKey::get('PASSWORD_ERROR_LIMIT', self::VARIANT), 0));
    }

    public function test_admin_login_requires_exact_address(): void
    {
        $this->makeUser(self::OWNER, ['is_admin' => 1]);

        $this->postJson($this->adminPath('/auth/login'), ['email' => self::VARIANT, 'password' => self::PASSWORD])
            ->assertStatus(400);
        $this->postJson($this->adminPath('/auth/login'), ['email' => self::OWNER, 'password' => self::PASSWORD])
            ->assertOk();
    }

    public function test_mail_link_only_for_exact_address(): void
    {
        Queue::fake();
        admin_setting(['login_with_mail_link_enable' => 1]);
        $this->makeUser(self::OWNER);

        $this->postJson('/api/v1/passport/auth/loginWithMailLink', ['email' => self::VARIANT])->assertOk();
        Queue::assertNotPushed(SendEmailJob::class);

        $this->postJson('/api/v1/passport/auth/loginWithMailLink', ['email' => self::OWNER])->assertOk();
        Queue::assertPushed(SendEmailJob::class, 1);
    }

    public function test_register_rejects_non_ascii_address(): void
    {
        admin_setting(['email_verify_mx_check' => 0]);   // 不查 DNS，只看地址本身
        $this->postJson('/api/v1/passport/auth/register', ['email' => 'someone@gmáil.com', 'password' => self::PASSWORD])
            ->assertStatus(400);
        $this->assertSame(0, User::count());

        $this->postJson('/api/v1/passport/auth/register', ['email' => 'someone@gmail.com', 'password' => self::PASSWORD])
            ->assertOk();
        $this->assertSame(1, User::count());
    }

    public function test_verification_code_for_new_address_needs_ascii(): void
    {
        Queue::fake();
        $this->postJson('/api/v1/passport/comm/sendEmailVerify', ['email' => 'someone@gmáil.com'])->assertStatus(400);
        Queue::assertNotPushed(SendEmailJob::class);
    }

    public function test_existing_non_ascii_account_can_still_get_code(): void
    {
        Queue::fake();
        $this->makeUser(self::VARIANT);
        $this->postJson('/api/v1/passport/comm/sendEmailVerify', ['email' => self::VARIANT])->assertOk();
        Queue::assertPushed(SendEmailJob::class, 1);
    }

    public function test_bounce_for_variant_address_is_not_recorded_on_owner(): void
    {
        $owner = $this->makeUser(self::OWNER);

        DeliveryMonitor::record(self::VARIANT, 'subject', 'mail.default.verify', '550 5.1.1 user unknown');

        $owner->refresh();
        $this->assertSame(0, (int) $owner->mail_failed_count);
        $this->assertNull($owner->mail_suppressed_at);
    }

    public function test_guest_restock_for_variant_address_is_not_linked_to_owner(): void
    {
        $this->makeUser(self::OWNER);
        $group = new ServerGroup();
        $group->name = 'g';
        $group->save();
        $plan = Plan::create([
            'name' => 'full', 'group_id' => $group->id, 'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => null, 'reset_traffic_method' => 1,
            'prices' => ['monthly' => 10], 'capacity_limit' => 0,
        ]);

        app(RestockNotifier::class)->subscribe($plan, self::VARIANT);

        $row = PlanRestockNotify::where('plan_id', $plan->id)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->user_id);
    }
}
