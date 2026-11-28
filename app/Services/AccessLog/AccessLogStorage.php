<?php

namespace App\Services\AccessLog;

use App\Services\TicketAttachment\Storage\S3SignatureV4;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * 访问记录的对象存储（S3 / R2 / MinIO）。和工单附件共用 SigV4 实现，只多了按字节范围读、
 * 批量删除和并发读这几样访问记录要用的操作。
 *
 * 对象布局：
 *   {prefix}/v1/dt=YYYY-MM-DD/node={type}-{id}/hour=HH/{ledger}-{seq}.tsv.gz  节点上传的批次（32 个 gzip 成员首尾相接）
 *   {prefix}/exports/{query}-{random}.csv                                      后台导出的明细
 */
final class AccessLogStorage
{
    private const REQUEST_TIMEOUT = 60;

    private readonly string $endpoint;
    private readonly string $bucket;
    private readonly string $prefix;
    private readonly bool $pathStyle;
    private readonly S3SignatureV4 $signer;
    private readonly ClientInterface $http;

    /**
     * @param array{endpoint:string,region:string,bucket:string,access_key:string,secret_key:string,path_style:bool,prefix:string} $cfg
     */
    public function __construct(array $cfg, ?ClientInterface $http = null)
    {
        foreach (['bucket', 'access_key', 'secret_key'] as $required) {
            if (trim((string) ($cfg[$required] ?? '')) === '') {
                throw new RuntimeException("访问记录存储未配置完整：缺少 {$required}");
            }
        }
        $region = trim((string) ($cfg['region'] ?? '')) ?: 'auto';
        $endpoint = rtrim(trim((string) ($cfg['endpoint'] ?? '')), '/');
        if ($endpoint === '') {
            $endpoint = "https://s3.{$region}.amazonaws.com";
        }
        if (!preg_match('#^https?://#i', $endpoint)) {
            throw new RuntimeException('S3 Endpoint 必须以 http:// 或 https:// 开头');
        }
        $this->endpoint = $endpoint;
        $this->bucket = trim((string) $cfg['bucket']);
        $this->prefix = trim((string) ($cfg['prefix'] ?? ''), " /\t\n\r") ?: 'access-logs';
        $this->pathStyle = (bool) ($cfg['path_style'] ?? true);
        $this->signer = new S3SignatureV4(trim((string) $cfg['access_key']), trim((string) $cfg['secret_key']), $region);
        $this->http = $http ?? new Client([
            'timeout' => self::REQUEST_TIMEOUT,
            'connect_timeout' => 10,
            'http_errors' => false,
            'allow_redirects' => false,
        ]);
    }

    public static function fromConfig(AccessLogConfig $config): self
    {
        return new self($config->s3);
    }

    public function batchKey(string $dt, int $hour, string $nodeType, int $nodeId, string $ledger, int $seq): string
    {
        return sprintf(
            '%s/v1/dt=%s/node=%s-%d/hour=%02d/%s-%08d.tsv.gz',
            $this->prefix,
            $dt,
            $nodeType,
            $nodeId,
            $hour,
            $ledger,
            $seq
        );
    }

    public function exportKey(int $queryId): string
    {
        return sprintf('%s/exports/%d-%s.csv', $this->prefix, $queryId, Str::lower(Str::random(16)));
    }

    public function put(string $key, string $body, string $contentType = 'application/gzip'): void
    {
        $response = $this->send('PUT', $this->objectUrl($key), ['content-type' => $contentType], hash('sha256', $body), $body);
        $this->assertStatus($response, [200, 201], 'PUT');
    }

    public function getRange(string $key, int $offset, int $length): string
    {
        return $this->getRanges([[$key, $offset, $length]], 1)[0] ?? throw new RuntimeException('S3 GET 失败');
    }

    /**
     * 并发按字节范围读取。读不到的（对象已过期删除、网络错误）该位置为 null，由调用方计入缺失。
     *
     * @param array<int,array{0:string,1:int,2:int}> $ranges [key, offset, length]
     * @return array<int,?string>
     */
    public function getRanges(array $ranges, int $concurrency = 16): array
    {
        $results = array_fill_keys(array_keys($ranges), null);
        $requests = function () use ($ranges) {
            foreach ($ranges as $index => [$key, $offset, $length]) {
                if ($length <= 0) {
                    continue;
                }
                $url = $this->objectUrl($key);
                $headers = ['range' => sprintf('bytes=%d-%d', $offset, $offset + $length - 1)];
                $signed = $this->signer->signHeaders('GET', $url, $headers, S3SignatureV4::EMPTY_PAYLOAD_HASH);
                yield $index => new PsrRequest('GET', $url, $signed);
            }
        };
        $pool = new Pool($this->http, $requests(), [
            'concurrency' => max(1, $concurrency),
            'fulfilled' => function (ResponseInterface $response, $index) use (&$results, $ranges) {
                $status = $response->getStatusCode();
                $body = (string) $response->getBody();
                [, $offset, $length] = $ranges[$index];
                if ($status === 206 && strlen($body) === $length) {
                    $results[$index] = $body;
                } elseif ($status === 200 && strlen($body) >= $offset + $length) {
                    // 个别 S3 兼容实现不支持 Range，会回整段对象
                    $results[$index] = substr($body, $offset, $length);
                }
            },
            'rejected' => static function () {
            },
        ]);
        $pool->promise()->wait();
        return $results;
    }

    public function delete(string $key): void
    {
        $response = $this->send('DELETE', $this->objectUrl($key));
        $this->assertStatus($response, [200, 202, 204, 404], 'DELETE');
    }

    /**
     * 批量删除（DeleteObjects，每次至多 1000 个）。不存在的键算作已删除。
     *
     * @param string[] $keys
     */
    public function deleteMany(array $keys): void
    {
        foreach (array_chunk(array_values($keys), 1000) as $chunk) {
            $xml = '<?xml version="1.0" encoding="UTF-8"?><Delete><Quiet>true</Quiet>';
            foreach ($chunk as $key) {
                $xml .= '<Object><Key>' . htmlspecialchars($key, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</Key></Object>';
            }
            $xml .= '</Delete>';
            $response = $this->send('POST', $this->bucketUrl() . '?delete', [
                'content-type' => 'application/xml',
                'content-md5' => base64_encode(md5($xml, true)),
            ], hash('sha256', $xml), $xml);
            $this->assertStatus($response, [200], 'DeleteObjects');
            if (str_contains((string) $response->getBody(), '<Error>')) {
                throw new RuntimeException('S3 DeleteObjects 部分失败');
            }
        }
    }

    public function temporaryUrl(string $key, int $ttl, string $downloadName): string
    {
        return $this->signer->presign('GET', $this->objectUrl($key), $ttl, [
            'response-content-disposition' => "attachment; filename*=UTF-8''" . rawurlencode($downloadName),
            'response-content-type' => 'text/csv; charset=utf-8',
        ]);
    }

    /** 写入 → 按范围读回 → 删除一个探针对象，验证凭据、桶和 Range 支持 */
    public function probe(): void
    {
        $key = $this->prefix . '/.probe-' . Str::lower(Str::random(12));
        $body = 'xboard access log storage probe ' . time();
        try {
            $this->put($key, $body, 'text/plain');
            if ($this->getRange($key, 7, 6) !== substr($body, 7, 6)) {
                throw new RuntimeException('按字节范围读回的内容不一致');
            }
        } finally {
            try {
                $this->delete($key);
            } catch (\Throwable) {
                // 删不掉不影响结论
            }
        }
    }

    private function bucketUrl(): string
    {
        if ($this->pathStyle) {
            return $this->endpoint . '/' . rawurlencode($this->bucket);
        }
        $parts = parse_url($this->endpoint);
        return $parts['scheme'] . '://' . $this->bucket . '.' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private function objectUrl(string $key): string
    {
        return $this->bucketUrl() . '/' . ltrim(S3SignatureV4::encodePath($key), '/');
    }

    private function send(string $method, string $url, array $headers = [], string $payloadHash = S3SignatureV4::EMPTY_PAYLOAD_HASH, ?string $body = null): ResponseInterface
    {
        $signed = $this->signer->signHeaders($method, $url, $headers, $payloadHash);
        $options = ['headers' => $signed];
        if ($body !== null) {
            $options['body'] = $body;
        }
        return $this->http->request($method, $url, $options);
    }

    private function assertStatus(ResponseInterface $response, array $expected, string $operation): void
    {
        $status = $response->getStatusCode();
        if (in_array($status, $expected, true)) {
            return;
        }
        $detail = '';
        $raw = (string) $response->getBody();
        if (preg_match('#<Code>([^<]+)</Code>#', $raw, $m)) {
            $detail = $m[1];
        }
        throw new RuntimeException(trim("S3 {$operation} 失败（HTTP {$status}）{$detail}"));
    }
}
