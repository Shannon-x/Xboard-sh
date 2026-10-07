<?php

namespace App\Services\ObjectStorage;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * S3 / S3 兼容对象存储的最小客户端（Cloudflare R2、MinIO、Backblaze B2、阿里云 OSS S3 网关等）。
 *
 * 直接用 Guzzle + SigV4 调 REST API，不依赖 aws/aws-sdk-php（原因见 S3SignatureV4）。
 * 只覆盖单对象操作：PUT / GET / HEAD / DELETE 与 GET 预签名 URL；工单附件与收据 / 账单归档共用。
 */
final class S3ObjectClient
{
    /** 测试里把带 MockHandler 的 Guzzle 客户端绑到容器这个键上，所有 S3 请求就都走它 */
    public const HTTP_BINDING = 'object-storage.s3.http';

    private const REQUEST_TIMEOUT = 60;

    private readonly string $endpoint;
    private readonly string $bucket;
    private readonly bool $pathStyle;
    private readonly S3SignatureV4 $signer;
    private readonly ClientInterface $http;

    /**
     * @param array{endpoint?:string,region?:string,bucket?:string,access_key?:string,secret_key?:string,path_style?:bool} $cfg
     */
    public function __construct(array $cfg, ?ClientInterface $http = null)
    {
        foreach (['bucket', 'access_key', 'secret_key'] as $required) {
            if (trim((string) ($cfg[$required] ?? '')) === '') {
                throw new RuntimeException("S3 存储未配置完整：缺少 {$required}");
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
        $this->pathStyle = (bool) ($cfg['path_style'] ?? true);
        $this->signer = new S3SignatureV4(trim((string) $cfg['access_key']), trim((string) $cfg['secret_key']), $region);
        $this->http = $http ?? (app()->bound(self::HTTP_BINDING) ? app(self::HTTP_BINDING) : new Client([
            'timeout' => self::REQUEST_TIMEOUT,
            'connect_timeout' => 10,
            'http_errors' => false,
            'allow_redirects' => false,
        ]));
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    public function bucket(): string
    {
        return $this->bucket;
    }

    /** 从内存写入对象。失败抛异常。 */
    public function putContents(string $key, string $contents, string $contentType): void
    {
        $response = $this->send('PUT', $key, ['content-type' => $contentType], hash('sha256', $contents), $contents);
        $this->assertStatus($response, [200, 201], 'PUT');
    }

    /** 从本地文件写入对象（大文件不进内存）。失败抛异常。 */
    public function putFile(string $key, string $localPath, string $contentType): void
    {
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('无法读取待上传的本地文件');
        }
        try {
            $response = $this->send('PUT', $key, ['content-type' => $contentType], hash_file('sha256', $localPath), $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $this->assertStatus($response, [200, 201], 'PUT');
    }

    /** 读取整个对象；不存在 / 失败抛异常。 */
    public function get(string $key): string
    {
        $response = $this->send('GET', $key);
        $this->assertStatus($response, [200], 'GET');
        return (string) $response->getBody();
    }

    /** 读取整个对象；不存在返回 null，其余失败抛异常。 */
    public function find(string $key): ?string
    {
        $response = $this->send('GET', $key);
        if ($response->getStatusCode() === 404) {
            return null;
        }
        $this->assertStatus($response, [200], 'GET');
        return (string) $response->getBody();
    }

    public function exists(string $key): bool
    {
        $response = $this->send('HEAD', $key);
        if ($response->getStatusCode() === 404) {
            return false;
        }
        $this->assertStatus($response, [200], 'HEAD');
        return true;
    }

    /** 删除对象；对象不存在视为成功。失败抛异常。 */
    public function delete(string $key): void
    {
        $response = $this->send('DELETE', $key);
        $this->assertStatus($response, [200, 202, 204, 404], 'DELETE');
    }

    /** 流式读取（响应体不进内存）。状态码由调用方判断，404 原样返回。 */
    public function stream(string $key): ResponseInterface
    {
        return $this->send('GET', $key, [], S3SignatureV4::EMPTY_PAYLOAD_HASH, null, true);
    }

    /**
     * GET 预签名 URL。
     *
     * @param array<string,string> $extraQuery 额外 query（如 response-content-disposition）
     */
    public function presign(string $key, int $ttl, array $extraQuery = []): string
    {
        return $this->signer->presign('GET', $this->objectUrl($key), $ttl, $extraQuery);
    }

    /** 写入 → HEAD → 删除一个探针对象，用于后台「测试存储连接」。失败抛异常。 */
    public function probe(string $key, string $payload = ''): void
    {
        $this->putContents($key, $payload !== '' ? $payload : 'xboard storage probe ' . time(), 'text/plain');
        try {
            if (!$this->exists($key)) {
                throw new RuntimeException('写入后读不到探针对象，请检查桶名与权限');
            }
        } finally {
            try {
                $this->delete($key);
            } catch (\Throwable) {
                // 探针对象删不掉不影响结论，留给桶生命周期规则或人工清理
            }
        }
    }

    /**
     * 对象的完整 URL：path-style 为 {endpoint}/{bucket}/{key}，
     * virtual-hosted 为 {scheme}://{bucket}.{host}/{key}。
     */
    public function objectUrl(string $key): string
    {
        $encodedKey = ltrim(S3SignatureV4::encodePath($key), '/');
        if ($this->pathStyle) {
            return $this->endpoint . '/' . rawurlencode($this->bucket) . '/' . $encodedKey;
        }
        $parts = parse_url($this->endpoint);
        $origin = $parts['scheme'] . '://' . $this->bucket . '.' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        return $origin . '/' . $encodedKey;
    }

    /**
     * 非预期状态码抛异常，带上 S3 返回体里的 <Code> / <Message>，方便对照 R2 / MinIO 的报错。
     *
     * @param int[] $expected
     */
    public function assertStatus(ResponseInterface $response, array $expected, string $operation): void
    {
        $status = $response->getStatusCode();
        if (in_array($status, $expected, true)) {
            return;
        }
        $detail = '';
        $raw = (string) $response->getBody();
        if (preg_match('#<Code>([^<]+)</Code>#', $raw, $m)) {
            $detail = $m[1];
            if (preg_match('#<Message>([^<]+)</Message>#', $raw, $mm)) {
                $detail .= ': ' . $mm[1];
            }
        } elseif ($raw !== '') {
            $detail = Str::limit(trim(strip_tags($raw)), 200);
        }
        throw new RuntimeException(trim("S3 {$operation} 失败（HTTP {$status}）" . ($detail !== '' ? " {$detail}" : '')));
    }

    /**
     * @param array<string,string> $headers
     * @param string|resource|null $body
     */
    private function send(
        string $method,
        string $key,
        array $headers = [],
        string $payloadHash = S3SignatureV4::EMPTY_PAYLOAD_HASH,
        $body = null,
        bool $stream = false
    ): ResponseInterface {
        $url = $this->objectUrl($key);
        $signed = $this->signer->signHeaders($method, $url, $headers, $payloadHash);
        $options = ['headers' => $signed, 'stream' => $stream];
        if ($body !== null) {
            $options['body'] = $body;
        }
        return $this->http->request($method, $url, $options);
    }
}
