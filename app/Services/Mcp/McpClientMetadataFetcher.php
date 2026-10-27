<?php

namespace App\Services\Mcp;

use Illuminate\Support\Facades\Http;

/**
 * 取 AI 软件的客户端元数据文档（client_id 本身就是这份 JSON 的 https 网址，ChatGPT 优先用这种方式）。
 *
 * 网址是请求方给的，等于让我们替别人发请求：只连公网 IP（先解析、再把连接钉在这个 IP 上，防 DNS 换绑），
 * 不跟跳转，5 秒超时，最多 10 KB。
 */
class McpClientMetadataFetcher
{
    public function fetch(string $url, string $host): ?array
    {
        $ips = gethostbynamel($host) ?: [];
        if ($ips === []) {
            return null;
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return null;
            }
        }
        try {
            $response = Http::timeout(5)->connectTimeout(3)->withoutRedirecting()->accept('application/json')
                ->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$host}:443:{$ips[0]}"]]])
                ->get($url);
        } catch (\Throwable) {
            return null;
        }
        if (!$response->successful() || strlen($response->body()) > 10240) {
            return null;
        }
        $doc = $response->json();
        return is_array($doc) ? $doc : null;
    }
}
