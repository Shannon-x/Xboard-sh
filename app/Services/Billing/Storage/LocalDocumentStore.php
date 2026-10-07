<?php

namespace App\Services\Billing\Storage;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * 本地存储：config/filesystems.php 的 `local` 盘（storage/app）下的 billing/documents/<user_id>/，
 * 不在 public/ 也不走 storage:link，只能经由带随机 access_key 的下载接口读取。
 */
final class LocalDocumentStore implements DocumentStore
{
    public const DIR = 'billing/documents';

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

    public function keyFor(int $userId, string $docNo): string
    {
        return self::DIR . '/' . $userId . '/' . $docNo . '.pdf';
    }

    public function put(string $key, string $pdf): void
    {
        if (!$this->disk->put($key, $pdf)) {
            throw new RuntimeException("无法写入本地磁盘：{$key}");
        }
    }

    public function get(string $key): ?string
    {
        return $this->disk->exists($key) ? $this->disk->get($key) : null;
    }

    public function delete(string $key): void
    {
        if (!$this->disk->exists($key)) {
            return;
        }
        if (!$this->disk->delete($key)) {
            throw new RuntimeException("无法删除本地文件：{$key}");
        }
    }

    public function probe(): void
    {
        $key = self::DIR . '/.probe-' . bin2hex(random_bytes(8)) . '.txt';
        $payload = 'xboard billing storage probe ' . time();
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
