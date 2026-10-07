<?php

namespace App\Services\TicketAttachment\Storage;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * AWS Signature Version 4（S3 服务）的最小实现。
 *
 * 只覆盖工单附件用到的操作：PUT / GET / HEAD / DELETE 对象，以及 GET 预签名 URL。
 * 不引入 aws/aws-sdk-php 的原因：SDK 连带二十多个包、composer.lock 需要重新解析，
 * 而本项目的 Docker 构建严格按 lock 安装；SigV4 本身只是一条 HMAC 链，
 * 这里的实现以 AWS 官方文档的示例向量做了单元测试（tests/Unit/S3SignatureV4Test.php）。
 *
 * 与 S3 兼容服务（Cloudflare R2 / MinIO / Backblaze B2 / 阿里云 OSS S3 网关等）均可用。
 */
final class S3SignatureV4
{
    public const EMPTY_PAYLOAD_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
    public const UNSIGNED_PAYLOAD = 'UNSIGNED-PAYLOAD';
    private const ALGORITHM = 'AWS4-HMAC-SHA256';

    public function __construct(
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly string $region,
        private readonly string $service = 's3',
    ) {
    }

    /**
     * 对一次请求做 header 签名。
     *
     * @param array<string,string> $headers 将随请求发送的头，全部参与签名；
     *                                      host / x-amz-date / x-amz-content-sha256 由这里补齐
     * @return array<string,string> 小写键名的完整请求头（含 authorization），需原样随请求发送
     */
    public function signHeaders(
        string $method,
        string $url,
        array $headers,
        string $payloadHash,
        ?DateTimeInterface $now = null
    ): array {
        $now = $this->utc($now);
        $amzDate = $now->format('Ymd\THis\Z');
        $date = $now->format('Ymd');
        $parts = $this->parseUrl($url);

        $canonicalHeaders = [];
        foreach ($headers as $name => $value) {
            $canonicalHeaders[strtolower(trim((string) $name))] = trim((string) preg_replace('/\s+/', ' ', (string) $value));
        }
        $canonicalHeaders['host'] = $parts['host'];
        $canonicalHeaders['x-amz-date'] = $amzDate;
        $canonicalHeaders['x-amz-content-sha256'] = $payloadHash;
        ksort($canonicalHeaders, SORT_STRING);

        $signedHeaders = implode(';', array_keys($canonicalHeaders));
        $canonicalHeaderString = '';
        foreach ($canonicalHeaders as $name => $value) {
            $canonicalHeaderString .= $name . ':' . $value . "\n";
        }

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $parts['path'],
            $this->canonicalQuery($parts['query']),
            $canonicalHeaderString,
            $signedHeaders,
            $payloadHash,
        ]);

        $scope = "{$date}/{$this->region}/{$this->service}/aws4_request";
        $signature = $this->signature($canonicalRequest, $amzDate, $date, $scope);

        $canonicalHeaders['authorization'] = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            self::ALGORITHM,
            $this->accessKey,
            $scope,
            $signedHeaders,
            $signature
        );

        return $canonicalHeaders;
    }

    /**
     * 生成预签名 URL（query 签名，SignedHeaders 仅 host，payload 为 UNSIGNED-PAYLOAD）。
     *
     * @param array<string,string> $extraQuery 额外 query（如 response-content-disposition）
     */
    public function presign(
        string $method,
        string $url,
        int $expires,
        array $extraQuery = [],
        ?DateTimeInterface $now = null
    ): string {
        $now = $this->utc($now);
        $amzDate = $now->format('Ymd\THis\Z');
        $date = $now->format('Ymd');
        $scope = "{$date}/{$this->region}/{$this->service}/aws4_request";
        $parts = $this->parseUrl($url);

        $query = $parts['query'] + $extraQuery + [
            'X-Amz-Algorithm' => self::ALGORITHM,
            'X-Amz-Credential' => "{$this->accessKey}/{$scope}",
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string) max(1, min(604800, $expires)),
            'X-Amz-SignedHeaders' => 'host',
        ];
        $canonicalQuery = $this->canonicalQuery($query);

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $parts['path'],
            $canonicalQuery,
            'host:' . $parts['host'] . "\n",
            'host',
            self::UNSIGNED_PAYLOAD,
        ]);
        $signature = $this->signature($canonicalRequest, $amzDate, $date, $scope);

        return $parts['origin'] . $parts['path'] . '?' . $canonicalQuery . '&X-Amz-Signature=' . $signature;
    }

    /**
     * 每个路径段单独 rawurlencode（保留 "/"）。S3 要求路径只编码一次，
     * 因此先解码再编码，避免调用方传入已编码路径时被双重编码。
     */
    public static function encodePath(string $path): string
    {
        $segments = explode('/', $path);
        $encoded = implode('/', array_map(static fn(string $s) => rawurlencode(rawurldecode($s)), $segments));
        return $encoded === '' ? '/' : $encoded;
    }

    private function signature(string $canonicalRequest, string $amzDate, string $date, string $scope): string
    {
        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', $this->service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        return hash_hmac('sha256', $stringToSign, $kSigning);
    }

    /**
     * 规范化 query：按参数名（再按值）排序，键值均 RFC3986 编码。
     *
     * @param array<string,string> $query
     */
    private function canonicalQuery(array $query): string
    {
        $pairs = [];
        foreach ($query as $k => $v) {
            $pairs[] = [rawurlencode((string) $k), rawurlencode((string) $v)];
        }
        usort($pairs, static fn(array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return implode('&', array_map(static fn(array $p) => $p[0] . '=' . $p[1], $pairs));
    }

    /**
     * @return array{origin:string,host:string,path:string,query:array<string,string>}
     */
    private function parseUrl(string $url): array
    {
        $p = parse_url($url);
        if (!$p || empty($p['host']) || empty($p['scheme'])) {
            throw new InvalidArgumentException("Invalid S3 url: {$url}");
        }
        $scheme = strtolower($p['scheme']);
        $host = strtolower($p['host']);
        $port = isset($p['port']) ? (int) $p['port'] : null;
        $defaultPort = $scheme === 'https' ? 443 : 80;
        // Host 头与 Guzzle 的行为一致：非默认端口才带端口
        $hostHeader = ($port !== null && $port !== $defaultPort) ? "{$host}:{$port}" : $host;

        $query = [];
        if (!empty($p['query'])) {
            foreach (explode('&', $p['query']) as $pair) {
                if ($pair === '') {
                    continue;
                }
                [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
                $query[rawurldecode($k)] = rawurldecode($v);
            }
        }

        return [
            'origin' => $scheme . '://' . $hostHeader,
            'host' => $hostHeader,
            'path' => self::encodePath($p['path'] ?? '/'),
            'query' => $query,
        ];
    }

    private function utc(?DateTimeInterface $now): DateTimeImmutable
    {
        $dt = $now ? DateTimeImmutable::createFromInterface($now) : new DateTimeImmutable('now');
        return $dt->setTimezone(new DateTimeZone('UTC'));
    }
}
