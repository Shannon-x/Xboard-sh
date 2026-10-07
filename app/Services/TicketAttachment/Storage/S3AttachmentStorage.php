<?php

namespace App\Services\TicketAttachment\Storage;

use App\Services\ObjectStorage\S3ObjectClient;
use App\Services\ObjectStorage\S3SignatureV4;
use GuzzleHttp\ClientInterface;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * S3 / S3 兼容对象存储驱动（Cloudflare R2、MinIO、Backblaze B2、阿里云 OSS S3 网关等）。
 *
 * 请求细节在 App\Services\ObjectStorage\S3ObjectClient（与收据 / 账单归档共用）。
 * 下载默认走预签名 URL 重定向，对象无需公开读；若配置了 public_url（公开桶 / CDN），
 * 则直接重定向到公开地址。
 */
final class S3AttachmentStorage implements AttachmentStorage
{
    private readonly S3ObjectClient $client;
    private readonly string $prefix;
    private readonly string $publicUrl;

    /**
     * @param array{endpoint:string,region:string,bucket:string,access_key:string,secret_key:string,path_style:bool,prefix:string,public_url:string} $cfg
     */
    public function __construct(array $cfg, ?ClientInterface $http = null)
    {
        $this->client = new S3ObjectClient($cfg, $http);
        $this->prefix = trim((string) ($cfg['prefix'] ?? ''), " /\t\n\r");
        $this->publicUrl = rtrim(trim((string) ($cfg['public_url'] ?? '')), '/');
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
        $this->client->putFile($key, $localPath, $mime);
    }

    public function delete(string $key): void
    {
        $this->client->delete($key);
    }

    public function get(string $key): string
    {
        return $this->client->get($key);
    }

    public function temporaryUrl(string $key, int $ttl, string $downloadName, bool $inline, string $mime): ?string
    {
        if ($this->publicUrl !== '') {
            return $this->publicUrl . '/' . ltrim(S3SignatureV4::encodePath($key), '/');
        }
        $disposition = ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($downloadName);
        return $this->client->presign($key, $ttl, [
            'response-content-disposition' => $disposition,
            'response-content-type' => $mime,
        ]);
    }

    public function response(string $key, string $mime, string $downloadName, bool $inline): SymfonyResponse
    {
        $upstream = $this->client->stream($key);
        if ($upstream->getStatusCode() === 404) {
            abort(404);
        }
        $this->client->assertStatus($upstream, [200], 'GET');

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
        $this->client->probe($key, 'xboard ticket attachment storage probe ' . time());
    }

    private static function asciiFallback(string $name): string
    {
        $fallback = preg_replace('/[^\x20-\x7E]/', '_', $name) ?? '';
        $fallback = str_replace(['%', '/', '\\', '"'], '_', $fallback);
        return trim($fallback) !== '' ? $fallback : 'attachment';
    }
}
