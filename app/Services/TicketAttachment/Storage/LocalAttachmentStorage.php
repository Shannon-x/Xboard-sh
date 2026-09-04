<?php

namespace App\Services\TicketAttachment\Storage;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * 本地存储：落在 config/filesystems.php 的 `local` 盘（storage/app）下，
 * 不在 public/ 也不走 storage:link，只能经由带随机 access_key 的下载接口读取。
 */
final class LocalAttachmentStorage implements AttachmentStorage
{
    public const BASE_DIR = 'ticket-attachments';

    public function __construct(private readonly FilesystemAdapter $disk)
    {
    }

    public static function make(): self
    {
        return new self(Storage::disk('local'));
    }

    public function driver(): string
    {
        return 'local';
    }

    public function newKey(string $extension): string
    {
        return self::BASE_DIR . '/' . date('Y/m') . '/' . Str::uuid()->toString() . '.' . $extension;
    }

    public function put(string $key, string $localPath, string $mime): void
    {
        $stored = $this->disk->putFileAs(dirname($key), new File($localPath), basename($key));
        if ($stored === false) {
            throw new RuntimeException("Unable to write attachment to local disk: {$key}");
        }
    }

    public function delete(string $key): void
    {
        if (!$this->disk->exists($key)) {
            return;
        }
        if (!$this->disk->delete($key)) {
            throw new RuntimeException("Unable to delete attachment from local disk: {$key}");
        }
    }

    public function temporaryUrl(string $key, int $ttl, string $downloadName, bool $inline, string $mime): ?string
    {
        return null;
    }

    public function response(string $key, string $mime, string $downloadName, bool $inline): SymfonyResponse
    {
        if (!$this->disk->exists($key)) {
            abort(404);
        }
        return $this->disk->response(
            $key,
            $downloadName,
            ['Content-Type' => $mime],
            $inline ? 'inline' : 'attachment'
        );
    }

    public function probe(): void
    {
        $key = self::BASE_DIR . '/.probe-' . bin2hex(random_bytes(8)) . '.txt';
        $payload = 'xboard ticket attachment storage probe ' . time();
        if (!$this->disk->put($key, $payload)) {
            throw new RuntimeException('storage/app 不可写，请检查目录权限');
        }
        try {
            if ($this->disk->get($key) !== $payload) {
                throw new RuntimeException('写入后读回的内容不一致');
            }
        } finally {
            $this->disk->delete($key);
        }
    }
}
