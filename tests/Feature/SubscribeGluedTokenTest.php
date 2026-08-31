<?php

namespace Tests\Feature;

use App\Http\Middleware\Client as ClientMiddleware;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 回归测试：订阅 token 后面被粘连 query 时的还原。
 *
 * 背景（2026-08-31 生产 / 工单 #2539）：订阅地址是路径式的 /{subscribe_path}/{token}，
 * 本身不带 `?`。用户照旧版 V2board 教程在末尾加 `&flag=sing-box`，这段文字不会成为查询
 * 参数，而是整串落进路由的 {token}：
 *
 *   GET /q/51bea5bf…6cc4&flag=sing-box  → 403 {"message":"token is null"}
 *   GET /q/51bea5bf…6cc4                → 200
 *
 * sing-box 端显示 "Failed to create profile HTTP 403 Forbidden"。access.log 全量统计：
 * 该形态 331 次、103 个不同账号，全部来自 sing-box 系客户端（SFI / SFA / SFM /
 * HiddifyNext），其中一个账号从 6 月起连撞两个半月、8844 次没有一次成功。
 *
 * 本测试锁定两侧边界：
 *   - 还原：能明确判定为「token + 分隔符 + query」的，拆回 token 并让粘连参数生效；
 *   - 拒收：token 本身写错（多字符 / 少字符 / 库中不存在）一律照旧 403，不做猜测；
 *     粘连串也不得改写 token —— 鉴权强度必须与修复前一致。
 */
class SubscribeGluedTokenTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTE_PREFIX = '/__test/subscribe';

    protected function setUp(): void
    {
        parent::setUp();

        // 与生产同形态的路径式订阅路由：token 落在 path 段上。
        Route::get(self::ROUTE_PREFIX . '/{token}', function (Request $request) {
            return response()->json([
                'user_id' => auth()->id(),
                'token' => $request->route('token'),
                'flag' => $request->input('flag'),
                'types' => $request->input('types'),
                'filter' => $request->input('filter'),
            ]);
        })->middleware(ClientMiddleware::class);
    }

    private function makeUser(?string $token = null): User
    {
        $token ??= self::hexToken();

        return User::create([
            'email' => "glued-token-{$token}@example.com",
            'password' => 'x',
            'uuid' => Str::uuid()->toString(),
            'token' => $token,
            'balance' => 0,
            'transfer_enable' => 0,
            'expired_at' => 0,
            'plan_id' => null,
            'group_id' => null,
        ]);
    }

    private static function hexToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function subscribe(string $suffix)
    {
        return $this->getJson(self::ROUTE_PREFIX . '/' . $suffix);
    }

    // ---- 还原：可明确判定的粘连形态 ----

    public function test_clean_token_still_authenticates(): void
    {
        $user = $this->makeUser();

        $this->subscribe($user->token)
            ->assertOk()
            ->assertJson(['user_id' => $user->id, 'token' => $user->token, 'flag' => null]);
    }

    public function test_glued_flag_is_recovered_and_applied(): void
    {
        $user = $this->makeUser();

        // 工单 #2539 里客户实际发出的那条请求。
        $this->subscribe($user->token . '&flag=sing-box')
            ->assertOk()
            ->assertJson([
                'user_id' => $user->id,
                'token' => $user->token,   // 下游读到的必须是干净 token
                'flag' => 'sing-box',      // 用户本来想要的参数照常生效
            ]);
    }

    public function test_glued_question_mark_and_multiple_params_are_recovered(): void
    {
        $user = $this->makeUser();

        $this->subscribe($user->token . '?flag=clash&types=vmess,trojan')
            ->assertOk()
            ->assertJson([
                'user_id' => $user->id,
                'token' => $user->token,
                'flag' => 'clash',
                'types' => 'vmess,trojan',
            ]);
    }

    public function test_uppercase_and_trailing_space_are_tolerated(): void
    {
        $user = $this->makeUser();

        // 生产上真实出现过的形态：复制订阅链接时带上了尾随空格（%20），修复前 403。
        // 中间件只负责剥离粘连串，token 的大小写按原样透传给下游。
        $this->subscribe(strtoupper($user->token) . '%20')
            ->assertOk()
            ->assertJson(['user_id' => $user->id, 'token' => strtoupper($user->token)]);
    }

    public function test_explicit_query_wins_over_glued_value(): void
    {
        $user = $this->makeUser();

        // 路径里粘连了 flag=clash，真正的 query 里写着 flag=surge：显式的那个优先。
        $this->subscribe($user->token . '&flag=clash&types=vless?flag=surge')
            ->assertOk()
            ->assertJson([
                'user_id' => $user->id,
                'flag' => 'surge',
                'types' => 'vless',
            ]);
    }

    // ---- 拒收：鉴权强度不得被放宽 ----

    public function test_glued_token_param_cannot_override_identity(): void
    {
        $victim = $this->makeUser();
        $attacker = $this->makeUser();

        $this->subscribe($attacker->token . '&token=' . $victim->token)
            ->assertOk()
            ->assertJson(['user_id' => $attacker->id, 'token' => $attacker->token]);
    }

    public function test_unknown_but_well_formed_token_is_rejected(): void
    {
        $this->makeUser();

        $this->subscribe(self::hexToken() . '&flag=sing-box')
            ->assertStatus(403)
            ->assertJsonPath('message', 'token is error');
    }

    public function test_mistyped_token_without_separator_is_rejected(): void
    {
        $user = $this->makeUser();

        // 生产上真实存在的形态：token 末尾多打了一个字符（不是粘连 query），不做猜测。
        $this->subscribe($user->token . 'c&flag=clash')
            ->assertStatus(403)
            ->assertJsonPath('message', 'token is null');
    }

    public function test_truncated_token_is_rejected(): void
    {
        $user = $this->makeUser();

        $this->subscribe(substr($user->token, 0, 31) . '&flag=sing-box')
            ->assertStatus(403)
            ->assertJsonPath('message', 'token is null');
    }

    public function test_non_hex_token_is_rejected(): void
    {
        $this->subscribe('not-a-token&flag=sing-box')
            ->assertStatus(403)
            ->assertJsonPath('message', 'token is null');
    }
}
