<?php

namespace Tests\Feature;

use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 找回密码的邮箱匹配以字节完全一致为准，不依赖数据库排序规则。
 *
 * SQLite 默认按字节比较；这里给 v2_user.email 挂一个不区分重音和大小写的排序规则，
 * 让测试覆盖 MySQL utf8mb4_unicode_ci 这类排序规则下的行为。
 */
class PasswordRecoveryEmailMatchTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = 'owner@gmail.com';
    private const VARIANT = 'owner@gmáil.com';

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        app()->forgetScopedInstances();

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

    private function makeUser(string $email): User
    {
        return User::create([
            'email' => $email,
            'password' => password_hash('old-password', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
        ]);
    }

    public function test_collation_matches_variant_row(): void
    {
        // 前提：测试库的排序规则确实把两种写法视为相同
        $this->makeUser(self::OWNER);
        $this->assertSame(1, User::where('email', self::VARIANT)->count());
    }

    public function test_variant_address_gets_no_verification_code(): void
    {
        Queue::fake();
        $this->makeUser(self::OWNER);

        $this->postJson('/api/v1/passport/comm/sendEmailVerify', ['email' => self::VARIANT])->assertStatus(400);

        Queue::assertNotPushed(SendEmailJob::class);
        $this->assertNull(Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', self::VARIANT)));
    }

    public function test_variant_address_code_does_not_reset_owner_password(): void
    {
        $owner = $this->makeUser(self::OWNER);
        // 缓存里已有该地址的验证码
        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', self::VARIANT), '123456', 300);

        $this->postJson('/api/v1/passport/auth/forget', [
            'email' => self::VARIANT, 'email_code' => '123456', 'password' => 'another-password',
        ])->assertStatus(400);

        $this->assertTrue(password_verify('old-password', $owner->fresh()->password));
    }

    public function test_owner_can_recover_including_ascii_case_variant(): void
    {
        Queue::fake();
        $owner = $this->makeUser(self::OWNER);

        $this->postJson('/api/v1/passport/comm/sendEmailVerify', ['email' => 'Owner@Gmail.com'])->assertOk();
        Queue::assertPushed(SendEmailJob::class, fn (SendEmailJob $job) => $this->recipient($job) === self::OWNER);
        $code = (string) Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', self::OWNER));
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        $this->postJson('/api/v1/passport/auth/forget', [
            'email' => 'Owner@Gmail.com', 'email_code' => $code, 'password' => 'new-password-1',
        ])->assertOk();

        $this->assertTrue(password_verify('new-password-1', $owner->fresh()->password));
    }

    public function test_uppercase_variant_address_gets_no_code(): void
    {
        Queue::fake();
        $this->makeUser(self::OWNER);
        $this->postJson('/api/v1/passport/comm/sendEmailVerify', ['email' => 'OWNER@GMÁIL.COM'])->assertStatus(400);
        Queue::assertNotPushed(SendEmailJob::class);
    }

    private function recipient(SendEmailJob $job): ?string
    {
        $ref = new \ReflectionObject($job);
        foreach ($ref->getProperties() as $prop) {
            $prop->setAccessible(true);
            $value = $prop->getValue($job);
            if (is_array($value) && isset($value['email'])) {
                return $value['email'];
            }
        }
        return null;
    }
}
