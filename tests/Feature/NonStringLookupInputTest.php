<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\InviteCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 按「码」查库的接口只认字符串入参。
 *
 * JSON 请求体里的 token / code 可以是数字、布尔或数组。数字一旦原样进了
 * `WHERE varchar_col = ?`，MySQL 会把整列转成数字再比：`'abc…' = 0`、`'2f9…' = 2`
 * 都成立，于是一个 0 就能命中「任意以字母开头」的那一行。SQLite / PostgreSQL 不这样比，
 * 所以这里除了看接口结果，还直接检查发给数据库的绑定值，换哪个库跑都能拦住回归。
 */
class NonStringLookupInputTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{sql: string, bindings: array}> */
    private array $queries = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::listen(function ($query) {
            $this->queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        });
    }

    public static function nonStringValues(): array
    {
        return [
            'zero' => [0],
            'digit' => [2],
            'true' => [true],
            'float' => [2.0],
            'array' => [[2]],
        ];
    }

    private function makeUser(string $token): User
    {
        return User::create([
            'email' => 'lookup-' . Str::random(8) . '@example.com',
            'password' => 'x',
            'uuid' => Str::uuid()->toString(),
            'token' => $token,
            'balance' => 0,
            'transfer_enable' => 0,
            'expired_at' => 0,
        ]);
    }

    /** 断言请求期间针对 $table 的查询里，$column = ? 对应的绑定值都是字符串。 */
    private function assertColumnBoundAsString(string $table, string $column): void
    {
        $pattern = '/[`"]?' . preg_quote($column, '/') . '[`"]?\s*=\s*\?/';
        foreach ($this->queries as $q) {
            if (!str_contains($q['sql'], $table)) {
                continue;
            }
            if (!preg_match_all($pattern, $q['sql'], $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($matches[0] as [$text, $offset]) {
                $index = substr_count(substr($q['sql'], 0, $offset), '?');
                $binding = $q['bindings'][$index] ?? null;
                $this->assertIsString(
                    $binding,
                    "{$table}.{$column} 收到了非字符串绑定：" . $q['sql'] . ' ' . json_encode($q['bindings'])
                );
            }
        }
    }

    #[DataProvider('nonStringValues')]
    public function test_subscribe_rejects_non_string_token_in_json_body($value): void
    {
        $this->makeUser('a' . bin2hex(random_bytes(15)) . 'b');
        $this->makeUser('2' . bin2hex(random_bytes(15)) . 'c');

        $this->queries = [];
        foreach (['/api/v1/client/subscribe', '/api/v2/client/app/getConfig'] as $uri) {
            $this->json('GET', $uri, ['token' => $value, 'flag' => 'surge'])->assertStatus(403);
        }

        $this->assertColumnBoundAsString('v2_user', 'token');
    }

    private function makeCoupon(string $code): Coupon
    {
        return Coupon::create([
            'code' => $code,
            'name' => 'lookup coupon',
            'type' => 1,
            'value' => 100,
            'show' => 1,
            'started_at' => time() - 3600,
            'ended_at' => time() + 3600,
        ]);
    }

    #[DataProvider('nonStringValues')]
    public function test_coupon_check_does_not_match_non_string_code($value): void
    {
        $this->makeCoupon('ABCD' . Str::upper(Str::random(6)));
        $this->makeCoupon('2' . Str::upper(Str::random(7)));
        Sanctum::actingAs($this->makeUser(bin2hex(random_bytes(16))));

        $this->queries = [];
        $response = $this->postJson('/api/v1/user/coupon/check', ['code' => $value]);

        $this->assertNotSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertNull($response->json('data.code'));
        $this->assertColumnBoundAsString('v2_coupon', 'code');
    }

    public function test_coupon_check_still_finds_exact_code(): void
    {
        $coupon = $this->makeCoupon('ABCD' . Str::upper(Str::random(6)));
        Sanctum::actingAs($this->makeUser(bin2hex(random_bytes(16))));

        $this->postJson('/api/v1/user/coupon/check', ['code' => $coupon->code])
            ->assertOk()
            ->assertJsonPath('data.code', $coupon->code);
    }

    private function makeInviteCode(string $code): InviteCode
    {
        $owner = $this->makeUser(bin2hex(random_bytes(16)));
        $invite = new InviteCode();
        $invite->user_id = $owner->id;
        $invite->code = $code;
        $invite->save();

        return $invite;
    }

    #[DataProvider('nonStringValues')]
    public function test_invite_pv_ignores_non_string_code($value): void
    {
        $letter = $this->makeInviteCode('abcd' . Str::lower(Str::random(4)));
        $digit = $this->makeInviteCode('2' . Str::lower(Str::random(7)));

        $this->queries = [];
        $this->postJson('/api/v1/passport/comm/pv', ['invite_code' => $value])->assertOk();

        $this->assertSame(0, (int) $letter->fresh()->pv);
        $this->assertSame(0, (int) $digit->fresh()->pv);
        $this->assertColumnBoundAsString('v2_invite_code', 'code');
    }

    public function test_invite_pv_counts_exact_code(): void
    {
        $invite = $this->makeInviteCode('abcd' . Str::lower(Str::random(4)));

        $this->postJson('/api/v1/passport/comm/pv', ['invite_code' => $invite->code])->assertOk();

        $this->assertSame(1, (int) $invite->fresh()->pv);
    }
}
