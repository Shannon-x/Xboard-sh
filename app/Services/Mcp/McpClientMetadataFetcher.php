<?php

namespace App\Services\Mcp;

use Illuminate\Support\Facades\Http;

/**
 * 取 AI 软件的客户端元数据文档（client_id 本身就是这份 JSON 的 https 网址，ChatGPT 优先用这种方式）。
 *
 * 网址是请求方给的，等于让我们替别人发请求：只连公网 IP（先解析、再把连接钉在这个 IP 上，防 DNS 换绑），
 * 不跟跳转，5 秒超时，最多 10 KB（边下边数，超了就停，不先整段下完）。
 */
class McpClientMetadataFetcher
{
    public const MAX_BYTES = 10240;

    public function fetch(string $url, string $host): ?array
    {
        $ips = gethostbynamel($host) ?: [];
        if ($ips === []) {
            return null;
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return null;
            }
        }
        try {
            $response = Http::timeout(5)->connectTimeout(3)->withoutRedirecting()->accept('application/json')
                ->withOptions(['stream' => true, 'curl' => [CURLOPT_RESOLVE => ["{$host}:443:{$ips[0]}"]]])
                ->get($url);
            if (!$response->successful()) {
                return null;
            }
            $declared = $response->header('Content-Length');
            if ($declared !== '' && is_numeric($declared) && (int) $declared > self::MAX_BYTES) {
                return null;
            }
            $stream = $response->toPsrResponse()->getBody();
            $body = '';
            while (!$stream->eof() && strlen($body) <= self::MAX_BYTES) {
                $chunk = $stream->read(self::MAX_BYTES + 1 - strlen($body));
                if ($chunk === '') {
                    break;
                }
                $body .= $chunk;
            }
            $stream->close();
        } catch (\Throwable) {
            return null;
        }
        if (strlen($body) > self::MAX_BYTES) {
            return null;
        }
        $doc = json_decode($body, true);
        return is_array($doc) ? $doc : null;
    }

    /**
     * 只认全球可路由的地址：私网、保留段之外，100.64.0.0/10（运营商 NAT、Tailscale 等组网常用）、
     * 192.0.0.0/24、198.18.0.0/15 这些「不是私网但也不在公网上」的段同样拒绝。
     */
    public static function isPublicIp(string $ip): bool
    {
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (defined('FILTER_FLAG_GLOBAL_RANGE')) {
            $flags |= FILTER_FLAG_GLOBAL_RANGE;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, $flags)) {
            return false;
        }
        // 老版本 PHP 没有 GLOBAL_RANGE 时手动补上这几段
        foreach (['100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15'] as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return false;
            }
        }
        return true;
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $ipLong = ip2long($ip);
        $netLong = ip2long($net);
        if ($ipLong === false || $netLong === false) {
            return false;
        }
        $mask = -1 << (32 - (int) $bits);
        return ($ipLong & $mask) === ($netLong & $mask);
    }
}
