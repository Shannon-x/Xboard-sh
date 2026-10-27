<?php

namespace App\Services\Mcp;

use App\Models\McpOauthClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * 一键授权里「是哪个 AI 软件」：动态注册（RFC 7591）、客户端元数据文档（client_id 是 https 网址），
 * 以及回跳地址的校验和授权页上显示的软件名。
 *
 * 软件自报的名字谁都能填，授权页以回跳地址的域名为准：chatgpt.com 显示 ChatGPT，认不出的域名原样显示并提醒客户。
 */
class McpOAuthClients
{
    public const AUTH_METHODS = ['none', 'client_secret_post', 'client_secret_basic'];

    /** 回跳域名 → 软件名。只放确认过的官方域名，子域名也算 */
    private const KNOWN_HOSTS = [
        'chatgpt.com' => 'ChatGPT',
        'chat.openai.com' => 'ChatGPT',
        'openai.com' => 'ChatGPT',
        'claude.ai' => 'Claude',
        'claude.com' => 'Claude',
        'anthropic.com' => 'Claude',
        'grok.com' => 'Grok',
        'x.ai' => 'Grok',
    ];

    /** 自定义协议里不能放行的：能在浏览器里执行脚本或读本地文件的 */
    private const BLOCKED_SCHEMES = ['javascript', 'data', 'file', 'vbscript', 'blob', 'about', 'ftp', 'ws', 'wss', 'http', 'https'];

    public function __construct(private readonly McpClientMetadataFetcher $fetcher)
    {
    }

    public static function make(): self
    {
        return app(self::class);
    }

    /**
     * 动态注册。成功返回 RFC 7591 的响应体；参数不对抛 McpOAuthError（invalid_client_metadata / invalid_redirect_uri）。
     */
    public function register(array $input, ?string $ip): array
    {
        $uris = $input['redirect_uris'] ?? null;
        if (!is_array($uris) || $uris === [] || count($uris) > 10) {
            throw new McpOAuthError('invalid_redirect_uri', 'redirect_uris must list 1-10 redirect URIs.');
        }
        foreach ($uris as $uri) {
            if (!is_string($uri) || !self::validRedirectUri($uri)) {
                throw new McpOAuthError('invalid_redirect_uri', 'Redirect URIs must be https, a loopback http address or an app scheme.');
            }
        }
        $method = (string) ($input['token_endpoint_auth_method'] ?? 'client_secret_basic');
        if (!in_array($method, self::AUTH_METHODS, true)) {
            throw new McpOAuthError('invalid_client_metadata', 'Supported token_endpoint_auth_method values: ' . implode(', ', self::AUTH_METHODS) . '.');
        }
        $grants = $input['grant_types'] ?? ['authorization_code'];
        if (!is_array($grants) || array_diff($grants, ['authorization_code', 'refresh_token']) !== []) {
            throw new McpOAuthError('invalid_client_metadata', 'Supported grant_types: authorization_code, refresh_token.');
        }
        $responseTypes = $input['response_types'] ?? ['code'];
        if (!is_array($responseTypes) || array_diff($responseTypes, ['code']) !== []) {
            throw new McpOAuthError('invalid_client_metadata', 'Only the "code" response type is supported.');
        }

        $name = self::cleanName($input['client_name'] ?? null);
        $clientId = 'mcpc_' . Str::random(32);
        $secret = $method === 'none' ? null : Str::random(48);
        $client = McpOauthClient::create([
            'client_id' => $clientId,
            'secret_hash' => $secret === null ? null : hash('sha256', $secret),
            'name' => $name,
            'redirect_uris' => array_values($uris),
            'auth_method' => $method,
            'ip' => $ip,
        ]);

        return array_filter([
            'client_id' => $clientId,
            'client_secret' => $secret,
            'client_id_issued_at' => (int) $client->getRawOriginal('created_at'),
            'client_secret_expires_at' => $secret === null ? null : 0,
            'client_name' => $name,
            'redirect_uris' => array_values($uris),
            'token_endpoint_auth_method' => $method,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
        ], fn ($v) => $v !== null);
    }

    /** 按 client_id 找客户端：https 网址走元数据文档，否则查动态注册表；找不到返回 null */
    public function find(string $clientId): ?McpOAuthClientInfo
    {
        if ($clientId === '' || strlen($clientId) > 512) {
            return null;
        }
        if (str_starts_with($clientId, 'https://')) {
            return $this->fromMetadataDocument($clientId);
        }
        $row = McpOauthClient::where('client_id', $clientId)->first();
        if (!$row) {
            return null;
        }
        return new McpOAuthClientInfo(
            clientId: $row->client_id,
            name: $row->name,
            redirectUris: (array) $row->redirect_uris,
            authMethod: $row->auth_method,
            secretHash: $row->secret_hash,
        );
    }

    private function fromMetadataDocument(string $url): ?McpOAuthClientInfo
    {
        $parts = parse_url($url);
        if (
            !$parts || empty($parts['host']) || empty($parts['path']) || $parts['path'] === '/'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
        ) {
            return null;
        }
        $key = 'mcp-oauth:cimd:' . hash('sha256', $url);
        $doc = Cache::get($key);
        if ($doc === null) {
            $doc = $this->fetcher->fetch($url, strtolower($parts['host']));
            // 取不到也记一分钟，免得同一个坏网址被反复拿来让我们发请求
            Cache::put($key, $doc ?? false, $doc ? 3600 : 60);
        }
        if (!is_array($doc) || ($doc['client_id'] ?? null) !== $url) {
            return null;
        }
        $uris = array_values(array_filter((array) ($doc['redirect_uris'] ?? []), 'is_string'));
        if ($uris === [] || count($uris) > 20) {
            return null;
        }
        foreach ($uris as $uri) {
            if (!self::validRedirectUri($uri)) {
                return null;
            }
        }
        // 元数据文档的客户端没有密钥可存，只支持 PKCE 公开客户端（none）
        $methods = $doc['token_endpoint_auth_methods_supported'] ?? [$doc['token_endpoint_auth_method'] ?? 'none'];
        if (!is_array($methods) || !in_array('none', $methods, true)) {
            return null;
        }
        return new McpOAuthClientInfo(
            clientId: $url,
            name: self::cleanName($doc['client_name'] ?? null),
            redirectUris: $uris,
            authMethod: 'none',
            secretHash: null,
        );
    }

    public static function validRedirectUri(string $uri): bool
    {
        if ($uri === '' || strlen($uri) > 2000 || str_contains($uri, '#')) {
            return false;
        }
        $parts = parse_url($uri);
        if (!$parts || empty($parts['scheme'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme === 'https') {
            return !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']);
        }
        if ($scheme === 'http') {
            return self::isLoopback((string) ($parts['host'] ?? ''));
        }
        // 桌面软件的自定义协议（cursor://…）
        return (bool) preg_match('/^[a-z][a-z0-9+.-]{1,40}$/', $scheme) && !in_array($scheme, self::BLOCKED_SCHEMES, true);
    }

    /** 授权请求里的回跳地址是否登记过：逐字相同；本机地址按 RFC 8252 不比端口 */
    public static function redirectAllowed(McpOAuthClientInfo $client, string $uri): bool
    {
        if (in_array($uri, $client->redirectUris, true)) {
            return true;
        }
        $asked = parse_url($uri);
        if (!$asked || strtolower($asked['scheme'] ?? '') !== 'http' || !self::isLoopback((string) ($asked['host'] ?? ''))) {
            return false;
        }
        foreach ($client->redirectUris as $registered) {
            $p = parse_url($registered);
            if (
                $p && strtolower($p['scheme'] ?? '') === 'http'
                && strtolower((string) ($p['host'] ?? '')) === strtolower((string) $asked['host'])
                && ($p['path'] ?? '') === ($asked['path'] ?? '')
                && ($p['query'] ?? '') === ($asked['query'] ?? '')
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * 授权页上的软件身份：以回跳地址为准。
     * @return array{name: string|null, client_name: string|null, host: string, kind: string, known: bool}
     *         kind: web（网页版）/ local（本机软件，回跳 localhost）/ app（桌面软件的自定义协议）
     */
    public static function describe(McpOAuthClientInfo $client, string $redirectUri): array
    {
        $parts = parse_url($redirectUri) ?: [];
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme === 'http' && self::isLoopback($host)) {
            return ['name' => null, 'client_name' => $client->name, 'host' => $host, 'kind' => 'local', 'known' => false];
        }
        if ($scheme !== 'https') {
            return ['name' => null, 'client_name' => $client->name, 'host' => $scheme . '://', 'kind' => 'app', 'known' => false];
        }
        foreach (self::KNOWN_HOSTS as $domain => $name) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return ['name' => $name, 'client_name' => $client->name, 'host' => $host, 'kind' => 'web', 'known' => true];
            }
        }
        return ['name' => null, 'client_name' => $client->name, 'host' => $host, 'kind' => 'web', 'known' => false];
    }

    /** 令牌列表里显示的名字：认得的软件名 → 软件自报名 → 回跳域名 */
    public static function displayName(array $app): string
    {
        $name = $app['name'] ?? $app['client_name'] ?? null;
        $name = $name ?: ($app['kind'] === 'local' ? 'AI' : $app['host']);
        return Str::limit((string) $name, 40, '');
    }

    private static function isLoopback(string $host): bool
    {
        return in_array(strtolower(trim($host, '[]')), ['localhost', '127.0.0.1', '::1'], true);
    }

    private static function cleanName(mixed $name): ?string
    {
        if (!is_string($name)) {
            return null;
        }
        $name = trim((string) preg_replace('/[\p{C}\s]+/u', ' ', $name));
        return $name === '' ? null : Str::limit($name, 60, '');
    }
}
