<?php

namespace App\Services\TicketAttachment\Storage;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * S3 / S3 兼容对象存储驱动（Cloudflare R2、MinIO、Backblaze B2、阿里云 OSS S3 网关等）。
 *
 * 直接用 Guzzle + SigV4 调 REST API，不依赖 aws/aws-sdk-php（原因见 S3SignatureV4）。
 * 下载默认走预签名 URL 重定向，对象无需公开读；若配置了 public_url（公开桶 / CDN），
 * 则直接重定向到公开地址。
 */
final class S3AttachmentStorage implements AttachmentStorage
{
    private const REQUEST_TIMEOUT = 60;

    private readonly string $endpoint;
    private readonly string $bucket;
    private readonly string $prefix;
    private readonly bool $pathStyle;
    private readonly string $publicUrl;
    private readonly S3SignatureV4 $signer;
    private readonly ClientInterface $http;

    /**
     * @param array{endpoint:string,region:string,bucket:string,access_key:string,secret_key:string,path_style:bool,prefix:string,public_url:string} $cfg
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
        $this->prefix = trim((string) ($cfg['prefix'] ?? ''), " /\t\n\r");
        $this->pathStyle = (bool) ($cfg['path_style'] ?? true);
        $this->publicUrl = rtrim(trim((string) ($cfg['public_url'] ?? '')), '/');
        $this->signer = new S3SignatureV4(trim((string) $cfg['access_key']), trim((string) $cfg['secret_key']), $region);
        $this->http = $http ?? new Client([
            'timeout' => self::REQUEST_TIMEOUT,
            'connect_timeout' => 10,
            'http_errors' => false,
            'allow_redirects' => false,
        ]);
    }

    public function driver(): string
    {
        return 's3';
    }

    public function newKey(string $extension): string
    {
        $key = date('Y/m') . '/' . Str::uuid()->toString() . '.' . $extension;
        return $this->prefix !== '' ? $this->prefix . '/' . $key : $key;
    }

    public function put(string $key, string $localPath, string $mime): void
    {
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('无法读取上传的临时文件');
        }
        try {
            $response = $this->send('PUT', $key, ['content-type' => $mime], hash_file('sha256', $localPath), $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $this->assertStatus($response, [200, 201], 'PUT');
    }

    public function delete(string $key): void
    {
        $response = $this->send('DELETE', $key);
        $this->assertStatus($response, [200, 202, 204, 404], 'DELETE');
    }

    public function get(string $key): string
    {
        $response = $this->send('GET', $key);
        $this->assertStatus($response, [200], 'GET');
        return (string) $response->getBody();
    }

    public function temporaryUrl(string $key, int $ttl, string $downloadName, bool $inline, string $mime): ?string
    {
        if ($this->publicUrl !== '') {
            return $this->publicUrl . '/' . ltrim(S3SignatureV4::encodePath($key), '/');
        }
        $disposition = ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($downloadName);
        return $this->signer->presign('GET', $this->objectUrl($key), $ttl, [
            'response-content-disposition' => $disposition,
            'response-content-type' => $mime,
        ]);
    }

    public function response(string $key, string $mime, string $downloadName, bool $inline): SymfonyResponse
    {
        $upstream = $this->send('GET', $key, [], S3SignatureV4::EMPTY_PAYLOAD_HASH, null, true);
        if ($upstream->getStatusCode() === 404) {
            abort(404);
        }
        $this->assertStatus($upstream, [200], 'GET');

        $body = $upstream->getBody();
        $response = new StreamedResponse(function () use ($body) {
            while (!$body->eof()) {
                echo $body->read(65536);
                flush();
            }
            $body->close();
        });
        $response->headers->set('Content-Type', $mime);
        if ($upstream->hasHeader('Content-Length')) {
            $response->headers->set('Content-Length', $upstream->getHeaderLine('Content-Length'));
        }
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(
                $inline ? 'inline' : 'attachment',
                $downloadName,
                self::asciiFallback($downloadName)
            )
        );
        return $response;
    }

    public function probe(): void
    {
        $key = $this->newKey('txt');
        $key = preg_replace('#/([^/]+)$#', '/.probe-$1', $key) ?: $key;
        $tmp = tempnam(sys_get_temp_dir(), 'xb-s3-probe');
        if ($tmp === false) {
            throw new RuntimeException('无法创建临时文件');
        }
        try {
            file_put_contents($tmp, 'xboard ticket attachment storage probe ' . time());
            $this->put($key, $tmp, 'text/plain');
            $head = $this->send('HEAD', $key);
            $this->assertStatus($head, [200], 'HEAD');
        } finally {
            @unlink($tmp);
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
    private function objectUrl(string $key): string
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
     * @param array<string,string> $headers
     * @param resource|null $body
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

    /**
     * @param int[] $expected
     */
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
            if (preg_match('#<Message>([^<]+)</Message>#', $raw, $mm)) {
                $detail .= ': ' . $mm[1];
            }
        } elseif ($raw !== '') {
            $detail = Str::limit(trim(strip_tags($raw)), 200);
        }
        throw new RuntimeException(trim("S3 {$operation} 失败（HTTP {$status}）" . ($detail !== '' ? " {$detail}" : '')));
    }

    private static function asciiFallback(string $name): string
    {
        $fallback = preg_replace('/[^\x20-\x7E]/', '_', $name) ?? '';
        $fallback = str_replace(['%', '/', '\\', '"'], '_', $fallback);
        return trim($fallback) !== '' ? $fallback : 'attachment';
    }
}
