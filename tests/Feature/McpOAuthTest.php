<?php

namespace Tests\Feature;

use App\Models\McpOauthClient;
use App\Models\McpOauthGrant;
use App\Models\Order;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\AuthService;
use App\Services\Mcp\McpClientMetadataFetcher;
use App\Services\Mcp\McpOAuthServer;
use App\Services\Mcp\McpTokenService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * 客户 AI 助手的一键授权（OAuth）：ChatGPT、Claude、Grok 网页版填接入地址 → 跳网站授权页 → 客户点「允许」→ 换到令牌。
 *
 * 守住：授权码一次性、必须 PKCE、只回跳登记过的地址、「允许 AI 替我操作」以客户勾的为准、
 * refresh token 换新后旧的立刻作废、客户在网站上断开后 AI 再也换不到令牌。
 */
class McpOAuthTest extends TestCase
{
    use RefreshDatabase;

    private const APP_URL = 'https://panel.example.test';
    private const ENDPOINT = 'https://ai.example.test/mcp';
    private const ISSUER = 'https://ai.example.test';
    private const CHATGPT_REDIRECT = 'https://chatgpt.com/connector_platform_oauth_redirect';

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        app()->forgetScopedInstances();
        config(['v2board.app_name' => '苏菲家宽', 'v2board.app_url' => self::APP_URL . '/']);

        $group = new ServerGroup();
        $group->name = '基础';
        $group->save();
        $this->plan = Plan::create([
            'name' => '静态家宽拼车', 'group_id' => $group->id, 'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => null, 'reset_traffic_method' => 1, 'capacity_limit' => null,
            'prices' => ['monthly' => 20],
        ]);
    }

    private function enable(array $extra = []): void
    {
        admin_setting(['mcp_enable' => 1, 'mcp_endpoint_url' => self::ENDPOINT] + $extra);
    }

    private function user(bool $paid = true): User
    {
        $user = User::create([
            'email' => 'oauth' . Str::lower(Str::random(6)) . '@example.test', 'password' => 'x',
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'plan_id' => $this->plan->id, 'group_id' => $this->plan->group_id, 'balance' => 0,
            'transfer_enable' => 100 * 1073741824, 'u' => 0, 'd' => 0, 'expired_at' => time() + 12 * 86400,
        ]);
        if ($paid) {
            Order::create([
                'user_id' => $user->id, 'plan_id' => $this->plan->id, 'period' => 'monthly', 'type' => Order::TYPE_NEW_PURCHASE,
                'trade_no' => Helper::generateOrderNo(), 'total_amount' => 2000, 'status' => Order::STATUS_COMPLETED,
            ]);
        }
        return $user;
    }

    private function panel(User $user): self
    {
        $this->app['auth']->forgetGuards();
        $token = substr((new AuthService($user))->generateAuthData()['auth_data'], strlen('Bearer '));
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token]);
    }

    private function rpc(string $token, string $method, array $params = [])
    {
        $this->app['auth']->forgetGuards();
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-06-18',
        ])->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]);
    }

    private function toolNames(string $token): array
    {
        return collect($this->rpc($token, 'tools/list')->assertOk()->json('result.tools'))->pluck('name')->all();
    }

    private function registerClient(array $extra = []): array
    {
        $this->app['auth']->forgetGuards();
        return $this->withHeaders(['Authorization' => ''])->postJson('/oauth/register', $extra + [
            'client_name' => 'ChatGPT',
            'redirect_uris' => [self::CHATGPT_REDIRECT],
            'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
        ])->assertStatus(201)->json();
    }

    /** 走到授权页：返回 req（网站授权页参数） */
    private function startAuthorization(string $clientId, string $verifier, array $extra = []): string
    {
        $location = $this->get('/oauth/authorize?' . http_build_query($extra + [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::CHATGPT_REDIRECT,
            'code_challenge' => McpOAuthServer::challengeFor($verifier),
            'code_challenge_method' => 'S256',
            'state' => 'st-123',
            'scope' => 'mcp:read mcp:order',
            'resource' => self::ENDPOINT,
        ]))->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith(self::APP_URL . '/ai/authorize?req=', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        return $query['req'];
    }

    private function approve(User $user, string $req, bool $canOrder): array
    {
        $redirect = $this->panel($user)->postJson('/api/v1/user/mcp/authorize/approve', ['req' => $req, 'can_order' => $canOrder])
            ->assertOk()->json('data.redirect');
        $this->assertStringStartsWith(self::CHATGPT_REDIRECT . '?', $redirect);
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
        return $query;
    }

    private function exchange(array $params)
    {
        $this->app['auth']->forgetGuards();
        return $this->withHeaders(['Authorization' => ''])->post('/oauth/token', $params, ['Accept' => 'application/json']);
    }

    /** 一整套：注册 → 授权页 → 允许 → 换令牌 */
    private function connect(User $user, bool $canOrder = false): array
    {
        $client = $this->registerClient();
        $verifier = Str::random(64);
        $req = $this->startAuthorization($client['client_id'], $verifier);
        $callback = $this->approve($user, $req, $canOrder);
        $tokens = $this->exchange([
            'grant_type' => 'authorization_code', 'code' => $callback['code'], 'code_verifier' => $verifier,
            'client_id' => $client['client_id'], 'redirect_uri' => self::CHATGPT_REDIRECT, 'resource' => self::ENDPOINT,
        ])->assertOk()->json();
        return ['client' => $client, 'tokens' => $tokens];
    }

    // ───────────────────────── 发现 ─────────────────────────

    public function test_discovery_is_hidden_until_enabled_then_points_ai_apps_to_the_issuer(): void
    {
        $this->get('/.well-known/oauth-protected-resource/mcp')->assertNotFound();
        $this->get('/.well-known/oauth-authorization-server')->assertNotFound();
        $this->postJson('/oauth/register', ['redirect_uris' => [self::CHATGPT_REDIRECT]])->assertNotFound();

        $this->enable();
        $this->get('/.well-known/oauth-protected-resource/mcp')->assertOk()
            ->assertJsonPath('resource', self::ENDPOINT)
            ->assertJsonPath('authorization_servers', [self::ISSUER]);
        $this->get('/.well-known/oauth-protected-resource')->assertOk()->assertJsonPath('resource', self::ENDPOINT);
        $meta = $this->get('/.well-known/oauth-authorization-server')->assertOk()->json();
        $this->assertSame(self::ISSUER, $meta['issuer']);
        $this->assertSame(self::ISSUER . '/oauth/token', $meta['token_endpoint']);
        $this->assertSame(['S256'], $meta['code_challenge_methods_supported']);
        $this->assertTrue($meta['client_id_metadata_document_supported']);
        $this->assertTrue($meta['authorization_response_iss_parameter_supported']);
        $this->assertContains('none', $meta['token_endpoint_auth_methods_supported']);

        // 没带令牌连 /mcp：401 里指明去哪儿授权，ChatGPT / Claude 据此弹出授权
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="' . self::ISSUER . '/.well-known/oauth-protected-resource/mcp"');

        // 后台关掉一键授权：发现地址消失，手动令牌照旧
        admin_setting(['mcp_oauth_enable' => 0]);
        $this->get('/.well-known/oauth-authorization-server')->assertNotFound();
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp"');
        $manual = McpTokenService::make()->create($this->user(), 'Hermes', false)['token'];
        $this->assertContains('get_account_overview', $this->toolNames($manual));
    }

    // ───────────────────────── 完整流程 ─────────────────────────

    public function test_customer_connects_chatgpt_with_one_click_and_the_token_refreshes(): void
    {
        $this->enable();
        $user = $this->user();
        $client = $this->registerClient();
        $this->assertStringStartsWith('mcpc_', $client['client_id']);
        $this->assertArrayNotHasKey('client_secret', $client, '公开客户端（PKCE）不发密钥');

        $verifier = Str::random(64);
        $req = $this->startAuthorization($client['client_id'], $verifier);

        // 授权页：以回跳域名认出是 ChatGPT，默认想要「替我操作」
        $this->panel($user)->getJson('/api/v1/user/mcp/authorize/fetch?req=' . $req)->assertOk()
            ->assertJsonPath('data.app.name', 'ChatGPT')
            ->assertJsonPath('data.app.host', 'chatgpt.com')
            ->assertJsonPath('data.app.known', true)
            ->assertJsonPath('data.wants_order', true)
            ->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.replaces', false)
            ->assertJsonPath('data.full', false);

        // 客户只给查询：回跳带码、原样的 state 和 iss
        $callback = $this->approve($user, $req, false);
        $this->assertSame('st-123', $callback['state']);
        $this->assertSame(self::ISSUER, $callback['iss']);
        $this->panel($user)->postJson('/api/v1/user/mcp/authorize/approve', ['req' => $req])->assertStatus(400, '同一个请求只能点一次「允许」');

        // 换令牌：错的 verifier 不行
        $base = ['grant_type' => 'authorization_code', 'code' => $callback['code'], 'client_id' => $client['client_id'], 'redirect_uri' => self::CHATGPT_REDIRECT];
        $this->exchange($base + ['code_verifier' => Str::random(64)])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

        // 上面那次已经把码用掉了：授权码一次性，猜错一次也作废，只能重来
        $this->exchange($base + ['code_verifier' => $verifier])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $req = $this->startAuthorization($client['client_id'], $verifier);
        $callback = $this->approve($user, $req, false);
        $base['code'] = $callback['code'];
        $tokens = $this->exchange($base + ['code_verifier' => $verifier, 'resource' => self::ENDPOINT])->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('scope', 'mcp:read')
            ->json();
        $this->assertGreaterThan(0, $tokens['expires_in']);
        $this->assertStringContainsString('no-store', (string) $this->exchange(['grant_type' => 'password'])->headers->get('Cache-Control'));
        $this->exchange($base + ['code_verifier' => $verifier])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

        // 拿到的就是 AI 助手令牌：能查，不能下单；面板接口不认
        $names = $this->toolNames($tokens['access_token']);
        $this->assertContains('get_account_overview', $names);
        $this->assertNotContains('create_order', $names);
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer ' . $tokens['access_token']])->getJson('/api/v1/user/info')->assertStatus(403);

        // AI 助手页：显示为 ChatGPT，标着一键授权
        $list = $this->panel($user)->getJson('/api/v1/user/mcp/token/fetch')->assertOk()->json('data.tokens');
        $this->assertCount(1, $list);
        $this->assertSame('ChatGPT', $list[0]['name']);
        $this->assertSame('oauth', $list[0]['via']);
        $this->assertSame('chatgpt.com', $list[0]['app_host']);

        // 换新：新令牌能用，旧令牌和旧 refresh token 立刻作废
        $refreshed = $this->exchange(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $client['client_id']])
            ->assertOk()->json();
        $this->assertNotSame($tokens['access_token'], $refreshed['access_token']);
        $this->assertContains('get_account_overview', $this->toolNames($refreshed['access_token']));
        $this->rpc($tokens['access_token'], 'tools/list')->assertStatus(401);
        $this->exchange(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $client['client_id']])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->assertSame(1, McpTokenService::make()->tokens($user)->count());

        // 别的软件拿不走这个 refresh token
        $other = $this->registerClient();
        $this->exchange(['grant_type' => 'refresh_token', 'refresh_token' => $refreshed['refresh_token'], 'client_id' => $other['client_id']])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

        // 客户在网站上断开：令牌失效，AI 也换不到新的
        $id = $this->panel($user)->getJson('/api/v1/user/mcp/token/fetch')->json('data.tokens.0.id');
        $this->panel($user)->postJson('/api/v1/user/mcp/token/revoke', ['id' => $id])->assertOk();
        $this->rpc($refreshed['access_token'], 'tools/list')->assertStatus(401);
        $this->exchange(['grant_type' => 'refresh_token', 'refresh_token' => $refreshed['refresh_token'], 'client_id' => $client['client_id']])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->assertSame(0, McpOauthGrant::count());
    }

    public function test_order_permission_follows_the_checkbox_and_the_admin_switch(): void
    {
        $this->enable();
        $user = $this->user();
        $connected = $this->connect($user, true);
        $this->assertSame('mcp:read mcp:order', $connected['tokens']['scope']);
        $this->assertContains('create_order', $this->toolNames($connected['tokens']['access_token']));

        // 同一个软件再连一次：换掉之前那份，不会越积越多
        $verifier = Str::random(64);
        $req = $this->startAuthorization($connected['client']['client_id'], $verifier);
        $this->panel($user)->getJson('/api/v1/user/mcp/authorize/fetch?req=' . $req)->assertJsonPath('data.replaces', true);
        $callback = $this->approve($user, $req, true);
        $again = $this->exchange([
            'grant_type' => 'authorization_code', 'code' => $callback['code'], 'code_verifier' => $verifier,
            'client_id' => $connected['client']['client_id'], 'redirect_uri' => self::CHATGPT_REDIRECT,
        ])->assertOk()->json();
        $this->assertSame(1, McpTokenService::make()->tokens($user)->count());
        $this->rpc($connected['tokens']['access_token'], 'tools/list')->assertStatus(401);

        // 后台关了「允许 AI 替客户操作」总闸：勾了也只给查询，换新时也不会长回来
        admin_setting(['mcp_order_enable' => 0]);
        $this->assertSame('mcp:read', $this->connect($user, true)['tokens']['scope']);
        $refreshed = $this->exchange(['grant_type' => 'refresh_token', 'refresh_token' => $again['refresh_token'], 'client_id' => $connected['client']['client_id']])
            ->assertOk()->json();
        $this->assertSame('mcp:read', $refreshed['scope']);
    }

    // ───────────────────────── 拦截 ─────────────────────────

    public function test_bad_requests_never_bounce_to_unregistered_addresses(): void
    {
        $this->enable();
        $client = $this->registerClient();
        $challenge = McpOAuthServer::challengeFor(Str::random(64));
        $query = fn (array $over) => '/oauth/authorize?' . http_build_query($over + [
            'response_type' => 'code', 'client_id' => $client['client_id'], 'redirect_uri' => self::CHATGPT_REDIRECT,
            'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'state' => 's',
        ]);

        // 软件不认识 / 回跳地址没登记：停在网站上报错，绝不跳过去
        $this->get($query(['client_id' => 'mcpc_nope']))->assertRedirect(self::APP_URL . '/ai/authorize?error=invalid_client');
        $this->get($query(['redirect_uri' => 'https://evil.example/cb']))->assertRedirect(self::APP_URL . '/ai/authorize?error=invalid_redirect');

        // 其余参数不对：按规范带着 error 跳回软件（带 iss）
        $location = $this->get($query(['code_challenge_method' => 'plain']))->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith(self::CHATGPT_REDIRECT . '?error=invalid_request', $location);
        $this->assertStringContainsString('iss=' . rawurlencode(self::ISSUER), $location);
        $location = $this->get($query(['resource' => 'https://other.example/mcp']))->headers->get('Location');
        $this->assertStringContainsString('error=invalid_target', $location);
        $location = $this->get($query(['response_type' => 'token']))->headers->get('Location');
        $this->assertStringContainsString('error=unsupported_response_type', $location);

        // 注册时就挡掉危险的回跳地址
        foreach (['http://evil.example/cb', 'javascript:alert(1)', 'https://chatgpt.com/cb#frag', 'data:text/html,x'] as $bad) {
            $this->postJson('/oauth/register', ['redirect_uris' => [$bad], 'token_endpoint_auth_method' => 'none'])
                ->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
        }
        // 本机软件（Claude Code、OpenClaw 等）和桌面软件协议可以
        $this->postJson('/oauth/register', ['redirect_uris' => ['http://localhost:33418/callback', 'cursor://anysphere.cursor-mcp/oauth/callback'], 'token_endpoint_auth_method' => 'none'])
            ->assertStatus(201);
    }

    public function test_customer_can_decline_and_strangers_or_unpaid_accounts_cannot_approve(): void
    {
        $this->enable();
        $client = $this->registerClient();
        $verifier = Str::random(64);

        $req = $this->startAuthorization($client['client_id'], $verifier);
        $redirect = $this->panel($this->user())->postJson('/api/v1/user/mcp/authorize/deny', ['req' => $req])->assertOk()->json('data.redirect');
        $this->assertStringStartsWith(self::CHATGPT_REDIRECT . '?error=access_denied', $redirect);
        $this->assertStringContainsString('state=st-123', $redirect);

        // 没付过款的账号：授权页显示不能用，点「允许」也不行
        $unpaid = $this->user(false);
        $req = $this->startAuthorization($client['client_id'], $verifier);
        $this->panel($unpaid)->getJson('/api/v1/user/mcp/authorize/fetch?req=' . $req)->assertOk()
            ->assertJsonPath('data.eligible', false)->assertJsonPath('data.reason', 'not_paid');
        $this->panel($unpaid)->postJson('/api/v1/user/mcp/authorize/approve', ['req' => $req])->assertStatus(403);

        // 已经连满 5 个：先断开一个
        $full = $this->user();
        for ($i = 0; $i < 5; $i++) {
            McpTokenService::make()->create($full, "t{$i}", false);
        }
        $this->panel($full)->getJson('/api/v1/user/mcp/authorize/fetch?req=' . $req)->assertJsonPath('data.full', true);
        $this->panel($full)->postJson('/api/v1/user/mcp/authorize/approve', ['req' => $req])->assertStatus(400);

        // 过期或编造的请求
        $this->panel($full)->getJson('/api/v1/user/mcp/authorize/fetch?req=nope')->assertStatus(400);
        // AI 助手令牌不能替客户点「允许」
        $mcp = McpTokenService::make()->create($this->user(), 'x', true)['token'];
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer ' . $mcp])->postJson('/api/v1/user/mcp/authorize/approve', ['req' => $req])->assertStatus(403);
    }

    public function test_confidential_clients_must_authenticate(): void
    {
        $this->enable();
        $user = $this->user();
        $client = $this->registerClient(['token_endpoint_auth_method' => 'client_secret_basic']);
        $this->assertNotEmpty($client['client_secret']);
        $this->assertSame(64, strlen((string) McpOauthClient::where('client_id', $client['client_id'])->value('secret_hash')), '只存哈希');

        $verifier = Str::random(64);
        $callback = $this->approve($user, $this->startAuthorization($client['client_id'], $verifier), false);
        $params = ['grant_type' => 'authorization_code', 'code' => $callback['code'], 'code_verifier' => $verifier, 'redirect_uri' => self::CHATGPT_REDIRECT];

        $this->exchange($params + ['client_id' => $client['client_id']])->assertStatus(401)->assertJsonPath('error', 'invalid_client');
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Basic ' . base64_encode($client['client_id'] . ':wrong')])
            ->post('/oauth/token', $params, ['Accept' => 'application/json'])->assertStatus(401);
        // 认证失败不碰授权码：带上正确的密钥还能换
        $this->withHeaders(['Authorization' => 'Basic ' . base64_encode($client['client_id'] . ':' . $client['client_secret'])])
            ->post('/oauth/token', $params, ['Accept' => 'application/json'])->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);
    }

    public function test_client_metadata_documents_work_like_chatgpt_uses_them(): void
    {
        $this->enable();
        $user = $this->user();
        $docUrl = 'https://chatgpt.com/oauth/client.json';
        $this->app->instance(McpClientMetadataFetcher::class, new class ($docUrl) extends McpClientMetadataFetcher {
            public int $calls = 0;

            public function __construct(private readonly string $docUrl)
            {
            }

            public function fetch(string $url, string $host): ?array
            {
                $this->calls++;
                return match ($url) {
                    $this->docUrl => [
                        'client_id' => $this->docUrl,
                        'client_name' => 'ChatGPT',
                        'redirect_uris' => ['https://chatgpt.com/connector_platform_oauth_redirect'],
                        'token_endpoint_auth_methods_supported' => ['none', 'private_key_jwt'],
                        'token_endpoint_auth_method' => 'private_key_jwt',
                    ],
                    // 文档里的 client_id 跟网址对不上：不认
                    'https://evil.example/client.json' => ['client_id' => 'https://chatgpt.com/oauth/client.json', 'redirect_uris' => ['https://evil.example/cb']],
                    default => null,
                };
            }
        });

        $verifier = Str::random(64);
        $req = $this->startAuthorization($docUrl, $verifier);
        $this->panel($user)->getJson('/api/v1/user/mcp/authorize/fetch?req=' . $req)->assertJsonPath('data.app.name', 'ChatGPT');
        $callback = $this->approve($user, $req, false);
        $tokens = $this->exchange([
            'grant_type' => 'authorization_code', 'code' => $callback['code'], 'code_verifier' => $verifier,
            'client_id' => $docUrl, 'redirect_uri' => self::CHATGPT_REDIRECT,
        ])->assertOk()->json();
        $this->assertContains('get_account_overview', $this->toolNames($tokens['access_token']));
        $this->assertSame(0, McpOauthClient::count(), '元数据文档的客户端不进注册表');
        $this->assertSame(1, app(McpClientMetadataFetcher::class)->calls, '文档缓存一小时，不会每次都去取');

        $this->get('/oauth/authorize?' . http_build_query([
            'response_type' => 'code', 'client_id' => 'https://evil.example/client.json', 'redirect_uri' => 'https://evil.example/cb',
            'code_challenge' => McpOAuthServer::challengeFor($verifier), 'code_challenge_method' => 'S256',
        ]))->assertRedirect(self::APP_URL . '/ai/authorize?error=invalid_client');
        $this->get('/oauth/authorize?' . http_build_query([
            'response_type' => 'code', 'client_id' => 'https://127.0.0.1:8443/client.json',
            'code_challenge' => McpOAuthServer::challengeFor($verifier), 'code_challenge_method' => 'S256',
        ]))->assertRedirect(self::APP_URL . '/ai/authorize?error=invalid_client');
    }

    public function test_metadata_fetcher_refuses_private_addresses(): void
    {
        $fetcher = new McpClientMetadataFetcher();
        $this->assertNull($fetcher->fetch('https://localhost/client.json', 'localhost'));
        $this->assertNull($fetcher->fetch('https://127.0.0.1/client.json', '127.0.0.1'));
        $this->assertNull($fetcher->fetch('https://10.0.0.8/client.json', '10.0.0.8'));
    }

    public function test_refresh_is_refused_after_the_idle_window(): void
    {
        $this->enable();
        $user = $this->user();
        $connected = $this->connect($user);
        PersonalAccessToken::query()->update(['expires_at' => now()->subMinute()]);
        $this->exchange(['grant_type' => 'refresh_token', 'refresh_token' => $connected['tokens']['refresh_token'], 'client_id' => $connected['client']['client_id']])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->assertSame(0, McpOauthGrant::count());
        $this->exchange(['grant_type' => 'password', 'client_id' => $connected['client']['client_id']])
            ->assertStatus(400)->assertJsonPath('error', 'unsupported_grant_type');
    }
}
