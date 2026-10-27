<?php

namespace App\Services\Mcp;

use App\Exceptions\ApiException;
use App\Models\McpCallLog;
use App\Models\McpOauthGrant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * 客户 AI 助手的一键授权（OAuth 2.1 授权码 + PKCE，按 MCP 授权规范）。
 *
 * ChatGPT、Claude、Grok 网页版不让客户填请求头，只认这一套：客户在 AI 里填接入地址 → AI 跳到
 * /oauth/authorize → 我们转到网站的授权页（/ai/authorize/<请求号>，客户用平常的账号登录）→ 客户点「允许」→
 * 带着一次性授权码回到 AI → AI 用码换令牌。换到的就是面板手动生成的那种令牌（McpTokenService::issue），
 * 只认 /mcp，「允许 AI 替我操作」由客户在授权页自己勾。
 *
 * 待确认的请求（15 分钟）和授权码（5 分钟、一次性）放缓存；点过「允许」的授权记在 v2_mcp_oauth_grant，
 * refresh token 只存哈希，每次换新令牌都一起换（旧的立刻作废）。
 */
class McpOAuthServer
{
    public const SCOPE_READ = 'mcp:read';
    public const SCOPE_ORDER = 'mcp:order';

    private const PENDING_TTL = 900;
    private const CODE_TTL = 300;
    /** 告诉 AI 软件的令牌有效期：到期它会用 refresh token 换新；令牌本身照旧按「多少天没用就失效」滑动 */
    private const ACCESS_TTL = 7 * 86400;

    public function __construct(
        private readonly McpConfig $config,
        private readonly McpOAuthClients $clients,
        private readonly McpTokenService $tokens,
    ) {
    }

    public static function make(): self
    {
        $config = McpConfig::fromSettings();
        return new self($config, McpOAuthClients::make(), new McpTokenService($config));
    }

    public function config(): McpConfig
    {
        return $this->config;
    }

    public function issuer(): string
    {
        return (string) $this->config->issuer();
    }

    /** RFC 9728：/mcp 这个资源由谁授权 */
    public function protectedResourceMetadata(): array
    {
        return [
            'resource' => $this->config->endpointUrl,
            'authorization_servers' => [$this->issuer()],
            'scopes_supported' => [self::SCOPE_READ, self::SCOPE_ORDER],
            'bearer_methods_supported' => ['header'],
            'resource_name' => (string) admin_setting('app_name', 'XBoard'),
        ];
    }

    public function resourceMetadataUrl(): string
    {
        return $this->issuer() . '/.well-known/oauth-protected-resource' . (parse_url($this->config->endpointUrl, PHP_URL_PATH) ?: '');
    }

    /** RFC 8414：授权服务器的各个地址与能力 */
    public function authorizationServerMetadata(): array
    {
        $issuer = $this->issuer();
        return [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer . '/oauth/authorize',
            'token_endpoint' => $issuer . '/oauth/token',
            'registration_endpoint' => $issuer . '/oauth/register',
            'scopes_supported' => [self::SCOPE_READ, self::SCOPE_ORDER],
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => McpOAuthClients::AUTH_METHODS,
            'client_id_metadata_document_supported' => true,
            'authorization_response_iss_parameter_supported' => true,
        ];
    }

    /**
     * /oauth/authorize：校验请求，记下待确认的请求，返回要跳去的地址 ——
     * 正常是网站的授权页；软件或回跳地址不对时是授权页的报错（不能跳回一个没登记过的地址）；
     * 其余参数不对时按规范带着 error 跳回软件。
     */
    public function authorize(array $query): string
    {
        $client = $this->clients->find((string) ($query['client_id'] ?? ''));
        if (!$client) {
            return $this->consentUrl(['error' => 'invalid_client']);
        }
        $redirect = (string) ($query['redirect_uri'] ?? '');
        if ($redirect === '' && count($client->redirectUris) === 1) {
            $redirect = $client->redirectUris[0];
        }
        if ($redirect === '' || !McpOAuthClients::redirectAllowed($client, $redirect)) {
            return $this->consentUrl(['error' => 'invalid_redirect']);
        }
        $state = isset($query['state']) && is_string($query['state']) ? $query['state'] : null;
        $fail = fn (string $error, string $description) => $this->redirectWith($redirect, [
            'error' => $error, 'error_description' => $description, 'state' => $state,
        ]);

        if (($query['response_type'] ?? '') !== 'code') {
            return $fail('unsupported_response_type', 'Only the authorization code flow is supported.');
        }
        $challenge = (string) ($query['code_challenge'] ?? '');
        if (($query['code_challenge_method'] ?? '') !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43,128}$/', $challenge)) {
            return $fail('invalid_request', 'PKCE with code_challenge_method=S256 is required.');
        }
        if (!$this->resourceMatches($query['resource'] ?? null)) {
            return $fail('invalid_target', 'Unknown resource; use ' . $this->config->endpointUrl);
        }

        $id = Str::random(40);
        Cache::put(self::pendingKey($id), [
            'client_id' => $client->clientId,
            'redirect_uri' => $redirect,
            'state' => $state,
            'code_challenge' => $challenge,
            'wants_order' => self::wantsOrder($query['scope'] ?? null),
            'app' => McpOAuthClients::describe($client, $redirect),
        ], self::PENDING_TTL);
        // 请求号放在路径里：客户要先登录时，登录页只把路径带回来（查询串会被丢掉）
        return rtrim((string) admin_setting('app_url', ''), '/') . '/ai/authorize/' . $id;
    }

    /**
     * 授权页要显示的内容。请求过期或不存在抛 ApiException（授权页提示回 AI 里重新点连接）。
     */
    public function pending(User $user, string $id): array
    {
        $pending = $this->pendingOrFail($id);
        $grant = $this->grantFor($user, $pending['client_id']);
        $reason = $this->tokens->ineligibleReason($user);
        return [
            'app' => $pending['app'],
            'wants_order' => $pending['wants_order'],
            'order_enabled' => $this->config->orderEnabled,
            'eligible' => $reason === null,
            'reason' => $reason,
            'replaces' => $grant !== null,
            'full' => $this->tokens->isFull($user, $grant?->token_id),
            'max' => McpConfig::MAX_TOKENS_PER_USER,
            'idle_days' => $this->config->tokenIdleDays,
        ];
    }

    /** 客户点「允许」：发一次性授权码，返回带着码跳回 AI 软件的地址 */
    public function approve(User $user, string $id, bool $canOrder): string
    {
        $pending = $this->pendingOrFail($id);
        if ($this->tokens->ineligibleReason($user) !== null) {
            throw new ApiException(__('The AI assistant is not available for this account'), 403);
        }
        $grant = $this->grantFor($user, $pending['client_id']);
        if ($this->tokens->isFull($user, $grant?->token_id)) {
            throw new ApiException(__('You can keep at most :count AI assistant tokens; revoke one first', ['count' => McpConfig::MAX_TOKENS_PER_USER]));
        }
        // 同一个请求只能用一次：两个标签页同时点「允许」只有一个拿到码
        if (!Cache::pull(self::pendingKey($id))) {
            throw new ApiException(__('This authorization request has expired; go back to your AI app and connect again'));
        }
        $code = Str::random(48);
        Cache::put(self::codeKey($code), [
            'user_id' => (int) $user->id,
            'client_id' => $pending['client_id'],
            'redirect_uri' => $pending['redirect_uri'],
            'code_challenge' => $pending['code_challenge'],
            'can_order' => $canOrder && $this->config->orderEnabled,
            'app' => $pending['app'],
        ], self::CODE_TTL);
        return $this->redirectWith($pending['redirect_uri'], ['code' => $code, 'state' => $pending['state']]);
    }

    /** 客户点「拒绝」：按规范带着 access_denied 跳回 AI 软件 */
    public function deny(string $id): string
    {
        $pending = $this->pendingOrFail($id);
        Cache::forget(self::pendingKey($id));
        return $this->redirectWith($pending['redirect_uri'], [
            'error' => 'access_denied', 'error_description' => 'The user declined the request.', 'state' => $pending['state'],
        ]);
    }

    /**
     * /oauth/token：授权码换令牌，或 refresh token 换新令牌。
     * @param array{0: string|null, 1: string|null} $basic HTTP Basic 里的 client_id / client_secret
     * @throws McpOAuthError
     */
    public function token(array $input, array $basic): array
    {
        $grantType = (string) ($input['grant_type'] ?? '');
        if (!in_array($grantType, ['authorization_code', 'refresh_token'], true)) {
            throw new McpOAuthError('unsupported_grant_type', 'Supported grant types: authorization_code, refresh_token.');
        }
        $client = $this->authenticateClient($input, $basic);
        return $grantType === 'authorization_code'
            ? $this->exchangeCode($client, $input)
            : $this->refresh($client, $input);
    }

    private function exchangeCode(McpOAuthClientInfo $client, array $input): array
    {
        $code = (string) ($input['code'] ?? '');
        $verifier = (string) ($input['code_verifier'] ?? '');
        if ($code === '' || !preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier)) {
            throw new McpOAuthError('invalid_request', 'code and a valid code_verifier are required.');
        }
        // 授权码一次性：add 是原子的，第二次拿同一个码换不到
        $data = Cache::get(self::codeKey($code));
        if (!$data || !Cache::add(self::codeKey($code) . ':used', 1, self::CODE_TTL)) {
            throw new McpOAuthError('invalid_grant', 'The authorization code is invalid, expired or already used.');
        }
        Cache::forget(self::codeKey($code));
        if ($data['client_id'] !== $client->clientId) {
            throw new McpOAuthError('invalid_grant', 'The authorization code was issued to another client.');
        }
        if (isset($input['redirect_uri']) && (string) $input['redirect_uri'] !== $data['redirect_uri']) {
            throw new McpOAuthError('invalid_grant', 'redirect_uri does not match the authorization request.');
        }
        if (!hash_equals($data['code_challenge'], self::challengeFor($verifier))) {
            throw new McpOAuthError('invalid_grant', 'PKCE verification failed.');
        }
        if (!$this->resourceMatches($input['resource'] ?? null)) {
            throw new McpOAuthError('invalid_target', 'Unknown resource; use ' . $this->config->endpointUrl);
        }
        $user = User::find($data['user_id']);
        if (!$user || $this->tokens->ineligibleReason($user) !== null) {
            throw new McpOAuthError('invalid_grant', 'This account cannot use the AI assistant right now.');
        }

        return DB::transaction(function () use ($user, $client, $data) {
            // 同一个软件重新授权：换掉之前那一份（令牌和授权一起删）
            $old = McpOauthGrant::where('user_id', $user->id)->where('client_key', self::clientKey($client->clientId))->lockForUpdate()->get();
            if ($this->tokens->isFull($user, $old->first()?->token_id)) {
                throw new McpOAuthError('invalid_grant', 'Too many AI assistant connections; remove one on the website first.');
            }
            foreach ($old as $grant) {
                if ($grant->token_id) {
                    PersonalAccessToken::whereKey($grant->token_id)->delete();
                }
                $grant->delete();
            }
            $issued = $this->tokens->issue($user, McpOAuthClients::displayName($data['app']), (bool) $data['can_order']);
            $refresh = Str::random(64);
            McpOauthGrant::create([
                'user_id' => $user->id,
                'client_key' => self::clientKey($client->clientId),
                'client_id' => $client->clientId,
                'app_name' => McpOAuthClients::displayName($data['app']),
                'redirect_host' => (string) $data['app']['host'],
                'token_id' => $issued['id'],
                'refresh_hash' => hash('sha256', $refresh),
            ]);
            return $this->tokenResponse($issued['token'], $refresh, (bool) $data['can_order']);
        });
    }

    private function refresh(McpOAuthClientInfo $client, array $input): array
    {
        $refresh = (string) ($input['refresh_token'] ?? '');
        $grant = $refresh === '' ? null : McpOauthGrant::where('refresh_hash', hash('sha256', $refresh))->first();
        if (!$grant || !hash_equals($grant->client_key, self::clientKey($client->clientId))) {
            throw new McpOAuthError('invalid_grant', 'The refresh token is invalid or was revoked.');
        }
        $old = $grant->token_id ? PersonalAccessToken::find($grant->token_id) : null;
        // 客户在网站上断开过（令牌没了），或闲置太久已失效：只能回 AI 里重新连接
        if (!$old || ($old->expires_at && $old->expires_at->isPast())) {
            $old?->delete();
            $grant->delete();
            throw new McpOAuthError('invalid_grant', 'The connection was removed or expired; connect again.');
        }
        $user = User::find($grant->user_id);
        if (!$user || $this->tokens->ineligibleReason($user) !== null) {
            throw new McpOAuthError('invalid_grant', 'This account cannot use the AI assistant right now.');
        }
        $canOrder = McpTokenService::canOrder($old) && $this->config->orderEnabled;
        $next = Str::random(64);

        return DB::transaction(function () use ($grant, $refresh, $next, $old, $user, $canOrder) {
            // 先抢占旧 refresh token：同一个 refresh token 并发换新，只有一个成功
            $taken = McpOauthGrant::whereKey($grant->id)->where('refresh_hash', hash('sha256', $refresh))
                ->update(['refresh_hash' => hash('sha256', $next), 'updated_at' => time()]);
            if ($taken !== 1) {
                throw new McpOAuthError('invalid_grant', 'The refresh token was already used.');
            }
            $issued = $this->tokens->issue($user, $grant->app_name, $canOrder);
            PersonalAccessToken::whereKey($issued['id'])->update(['last_used_at' => $old->last_used_at]);
            // 当天的调用记录跟到新令牌上：每日下单上限、最后一次 IP 不因为换新令牌清零
            McpCallLog::where('token_id', $old->id)->where('created_at', '>=', time() - 86400)->update(['token_id' => $issued['id']]);
            $old->delete();
            McpOauthGrant::whereKey($grant->id)->update(['token_id' => $issued['id']]);
            return $this->tokenResponse($issued['token'], $next, $canOrder);
        });
    }

    private function authenticateClient(array $input, array $basic): McpOAuthClientInfo
    {
        [$basicId, $basicSecret] = $basic;
        $clientId = (string) ($basicId ?? $input['client_id'] ?? '');
        $client = $this->clients->find($clientId);
        if (!$client) {
            throw new McpOAuthError('invalid_client', 'Unknown client_id.', 401);
        }
        if ($client->isPublic()) {
            return $client;
        }
        $secret = $client->authMethod === 'client_secret_basic' ? $basicSecret : ($input['client_secret'] ?? $basicSecret);
        if (!is_string($secret) || $secret === '' || !$client->secretHash || !hash_equals($client->secretHash, hash('sha256', $secret))) {
            throw new McpOAuthError('invalid_client', 'Client authentication failed.', 401);
        }
        return $client;
    }

    private function tokenResponse(string $access, string $refresh, bool $canOrder): array
    {
        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => min(self::ACCESS_TTL, $this->config->tokenIdleDays * 86400),
            'refresh_token' => $refresh,
            'scope' => $canOrder ? self::SCOPE_READ . ' ' . self::SCOPE_ORDER : self::SCOPE_READ,
        ];
    }

    private function grantFor(User $user, string $clientId): ?McpOauthGrant
    {
        return McpOauthGrant::where('user_id', $user->id)->where('client_key', self::clientKey($clientId))->orderByDesc('id')->first();
    }

    private function pendingOrFail(string $id): array
    {
        $pending = $id === '' ? null : Cache::get(self::pendingKey($id));
        if (!is_array($pending)) {
            throw new ApiException(__('This authorization request has expired; go back to your AI app and connect again'));
        }
        return $pending;
    }

    /** resource 参数（RFC 8707）可以不带；带了就得是我们的接入地址（末尾斜杠不计） */
    private function resourceMatches(mixed $resource): bool
    {
        if ($resource === null || $resource === '') {
            return true;
        }
        return is_string($resource) && rtrim($resource, '/') === rtrim($this->config->endpointUrl, '/');
    }

    /** 没写 scope 或写了 mcp:order：授权页默认勾上「允许 AI 替我操作」，最后以客户勾的为准 */
    private static function wantsOrder(mixed $scope): bool
    {
        if (!is_string($scope) || trim($scope) === '') {
            return true;
        }
        return in_array(self::SCOPE_ORDER, preg_split('/\s+/', trim($scope)) ?: [], true);
    }

    /** 跳回 AI 软件：带上 iss（RFC 9207），ChatGPT 据此用固定的回跳地址 */
    private function redirectWith(string $uri, array $params): string
    {
        $params = array_filter($params + ['iss' => $this->issuer()], fn ($v) => $v !== null);
        return $uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private function consentUrl(array $params): string
    {
        return rtrim((string) admin_setting('app_url', ''), '/') . '/ai/authorize?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public static function challengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private static function clientKey(string $clientId): string
    {
        return hash('sha256', $clientId);
    }

    private static function pendingKey(string $id): string
    {
        return 'mcp-oauth:req:' . hash('sha256', $id);
    }

    private static function codeKey(string $code): string
    {
        return 'mcp-oauth:code:' . hash('sha256', $code);
    }
}
