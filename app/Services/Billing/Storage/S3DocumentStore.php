<?php

namespace App\Services\Billing\Storage;

use App\Services\ObjectStorage\S3ObjectClient;

/**
 * S3 / S3 兼容对象存储：对象 key 为 <prefix>/<user_id>/<doc_no>.pdf。
 *
 * 桶不需要公开读，也不用预签名直链：下载始终由后端凭 access_key 校验后流式输出
 * （用户只看到站点自己的域名，经中间件一样可达；PDF 只有几十 KB）。
 */
final class S3DocumentStore implements DocumentStore
{
    public function __construct(private readonly S3ObjectClient $client, private readonly string $prefix)
    {
    }

    public function driver(): string
    {
        return 's3';
    }

    public function keyFor(int $userId, string $docNo): string
    {
        return $this->prefixed($userId . '/' . $docNo . '.pdf');
    }

    public function put(string $key, string $pdf): void
    {
        $this->client->putContents($key, $pdf, 'application/pdf');
    }

    public function get(string $key): ?string
    {
        return $this->client->find($key);
    }

    public function delete(string $key): void
    {
        $this->client->delete($key);
    }

    public function probe(): void
    {
        $this->client->probe($this->prefixed('.probe-' . bin2hex(random_bytes(8)) . '.txt'), 'xboard billing storage probe ' . time());
    }

    private function prefixed(string $key): string
    {
        return $this->prefix !== '' ? $this->prefix . '/' . $key : $key;
    }
}
