<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\TicketAttachment\AttachmentConfig;
use App\Services\TicketAttachment\AttachmentStorageFactory;
use App\Services\TicketAttachment\Storage\AttachmentStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * 工单附件：上传校验、配额、与消息绑定、下载、删除。
 *
 * 上传是两段式的：先 upload 拿到附件 id（此时 ticket_message_id 为 NULL，属「待绑定」），
 * 再随 ticket/save 或 ticket/reply 的 attachment_ids 一起发出，在创建消息的同一事务里绑定。
 * 这样剪贴板粘贴即可立刻上传、显示缩略图，发送时不必再传文件；没发出去的由清理任务回收。
 */
class TicketAttachmentService
{
    /** 预签名 / 重定向下载链接有效期（秒） */
    public const DOWNLOAD_URL_TTL = 600;

    /**
     * 扩展名 → 允许的 MIME（finfo 按内容探测的结果必须命中，否则视为伪装类型）。
     * 以 "/" 结尾的条目按前缀匹配。不在表里的扩展名只要后台允许就按探测结果收下，
     * 反正非图片一律以 attachment 方式下发，不会被浏览器当页面渲染。
     */
    private const MIME_BY_EXTENSION = [
        'pdf' => ['application/pdf'],
        'txt' => ['text/', 'application/x-empty'],
        'log' => ['text/', 'application/octet-stream'],
        'conf' => ['text/'],
        'json' => ['application/json', 'text/'],
        'yaml' => ['text/', 'application/yaml', 'application/x-yaml'],
        'yml' => ['text/', 'application/yaml', 'application/x-yaml'],
        'csv' => ['text/'],
        'zip' => ['application/zip', 'application/x-zip-compressed', 'application/x-zip'],
        'rar' => ['application/vnd.rar', 'application/x-rar-compressed', 'application/x-rar'],
        '7z' => ['application/x-7z-compressed'],
        'gz' => ['application/gzip', 'application/x-gzip'],
        'mp4' => ['video/mp4'],
        'mov' => ['video/quicktime'],
    ];

    private const IMAGE_EXT_BY_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    private AttachmentConfig $config;

    public function __construct(?AttachmentConfig $config = null)
    {
        $this->config = $config ?? AttachmentConfig::fromSettings();
    }

    public function config(): AttachmentConfig
    {
        return $this->config;
    }

    public function storage(?string $driver = null): AttachmentStorage
    {
        return AttachmentStorageFactory::make($this->config, $driver);
    }

    /**
     * 保存一个 multipart 上传的文件。
     *
     * @param bool $bypassQuota 管理员回复附件不受用户每日额度 / 待绑定数量限制（体积与类型仍受限）
     * @throws ApiException
     */
    public function storeUploadedFile(UploadedFile $file, User $uploader, bool $bypassQuota = false): TicketAttachment
    {
        if (!$this->config->enable) {
            throw new ApiException(__('Ticket attachments are disabled'));
        }
        if (!$file->isValid()) {
            throw new ApiException(__('Attachment file is invalid'));
        }

        $size = (int) $file->getSize();
        if ($size <= 0) {
            throw new ApiException(__('Attachment file is invalid'));
        }
        if ($size > $this->config->maxSizeBytes()) {
            throw new ApiException(__('Attachment exceeds the size limit of :size MB', ['size' => $this->config->maxSizeMb]));
        }

        $path = $file->getRealPath() ?: $file->getPathname();
        [$ext, $mime, $isImage, $width, $height] = $this->classify($file, $path);
        if (!in_array($ext, $this->config->allowedExtensions, true)) {
            throw new ApiException(__('Attachment type is not allowed'));
        }

        if (!$bypassQuota) {
            $this->assertQuota($uploader->id, $size);
        }

        $storage = $this->storage();
        $key = $storage->newKey($ext);
        try {
            $storage->put($key, $path, $mime);
        } catch (\Throwable $e) {
            Log::error('[ticket-attachment] store failed', [
                'driver' => $storage->driver(),
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            throw new ApiException(__('Attachment storage failed'));
        }

        try {
            return TicketAttachment::create([
                'user_id' => $uploader->id,
                'driver' => $storage->driver(),
                'path' => $key,
                'original_name' => $this->sanitizeName($file->getClientOriginalName(), $ext),
                'mime' => $mime,
                'size' => $size,
                'is_image' => $isImage,
                'width' => $width,
                'height' => $height,
                'access_key' => bin2hex(random_bytes(16)),
            ]);
        } catch (\Throwable $e) {
            try {
                $storage->delete($key);
            } catch (\Throwable) {
                // 已尽力回滚；对象会在无人引用的情况下被清理任务忽略，最坏只是残留一个孤儿文件
            }
            throw $e;
        }
    }

    /**
     * 保存 JSON 里的 base64 文件（stealth 加密通道只能承载 JSON，前端主题走这条路）。
     *
     * @throws ApiException
     */
    public function storeBase64(string $name, string $base64, User $uploader, bool $bypassQuota = false): TicketAttachment
    {
        if (!$this->config->enable) {
            throw new ApiException(__('Ticket attachments are disabled'));
        }
        // 兼容 data URL 形态
        if (preg_match('/^data:[^;,]*;base64,/i', $base64, $m)) {
            $base64 = substr($base64, strlen($m[0]));
        }
        // 解码前先按长度粗估，超限的直接拒绝，不浪费内存去解码
        if ((int) (strlen($base64) * 3 / 4) > $this->config->maxSizeBytes() + 4096) {
            throw new ApiException(__('Attachment exceeds the size limit of :size MB', ['size' => $this->config->maxSizeMb]));
        }
        $binary = base64_decode($base64, true);
        if ($binary === false || $binary === '') {
            throw new ApiException(__('Attachment file is invalid'));
        }
        unset($base64);

        return $this->storeFromContents($name, $binary, $uploader, $bypassQuota);
    }

    /**
     * 保存一段已在内存里的文件内容（Telegram 回图等非 HTTP 上传来源）。
     *
     * @throws ApiException
     */
    public function storeFromContents(string $name, string $binary, User $uploader, bool $bypassQuota = false): TicketAttachment
    {
        if (!$this->config->enable) {
            throw new ApiException(__('Ticket attachments are disabled'));
        }
        if ($binary === '') {
            throw new ApiException(__('Attachment file is invalid'));
        }
        if (strlen($binary) > $this->config->maxSizeBytes()) {
            throw new ApiException(__('Attachment exceeds the size limit of :size MB', ['size' => $this->config->maxSizeMb]));
        }

        $tmpDir = storage_path('tmp');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }
        $tmp = tempnam($tmpDir, 'ticket-att-');
        if ($tmp === false) {
            throw new ApiException(__('Attachment storage failed'));
        }
        try {
            file_put_contents($tmp, $binary);
            unset($binary);
            // test=true 跳过 is_uploaded_file 检查（不是 PHP 原生上传）
            $file = new UploadedFile($tmp, $name !== '' ? $name : 'attachment', null, null, true);
            return $this->storeUploadedFile($file, $uploader, $bypassQuota);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * 复制一个已有附件为新的待绑定附件（提现「沿用上次二维码」：旧图绑在旧工单上，新申请需要自己的一份）。
     *
     * @throws ApiException
     */
    public function duplicate(TicketAttachment $source, User $owner): TicketAttachment
    {
        try {
            $contents = $this->storage($source->driver)->get($source->path);
        } catch (\Throwable $e) {
            Log::warning('[ticket-attachment] duplicate read failed', ['id' => $source->id, 'error' => $e->getMessage()]);
            throw new ApiException(__('Attachment does not exist'));
        }
        return $this->storeFromContents($source->original_name, $contents, $owner, true);
    }

    /**
     * 归一化并校验 attachment_ids：必须是本人上传、尚未绑定、数量不超上限。
     *
     * @return int[]
     * @throws ApiException
     */
    public function validatePendingIds(mixed $raw, int $userId): array
    {
        $ids = [];
        foreach (is_array($raw) ? $raw : [] as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        $ids = array_keys($ids);
        if (!$ids) {
            return [];
        }
        if (!$this->config->enable) {
            throw new ApiException(__('Ticket attachments are disabled'));
        }
        if (count($ids) > $this->config->maxCount) {
            throw new ApiException(__('Too many attachments, at most :count per message', ['count' => $this->config->maxCount]));
        }
        $found = TicketAttachment::whereIn('id', $ids)
            ->where('user_id', $userId)
            ->whereNull('ticket_message_id')
            ->count();
        if ($found !== count($ids)) {
            throw new ApiException(__('Attachment does not exist'));
        }
        return $ids;
    }

    /**
     * 把待绑定附件挂到消息上。必须在创建消息的事务里调用；带行锁防止并发把同一附件绑到两条消息。
     *
     * @param int[] $ids
     * @throws ApiException
     */
    public function attachToMessage(array $ids, TicketMessage $message, int $uploaderId): void
    {
        if (!$ids) {
            return;
        }
        $rows = TicketAttachment::whereIn('id', $ids)
            ->where('user_id', $uploaderId)
            ->whereNull('ticket_message_id')
            ->lockForUpdate()
            ->get();
        if ($rows->count() !== count($ids)) {
            throw new ApiException(__('Attachment does not exist'));
        }
        TicketAttachment::whereIn('id', $rows->pluck('id')->all())->update([
            'ticket_id' => $message->ticket_id,
            'ticket_message_id' => $message->id,
            'updated_at' => time(),
        ]);
    }

    /**
     * 用户撤回一个尚未发出的附件。
     *
     * @throws ApiException
     */
    public function deletePending(int $id, int $userId): void
    {
        $attachment = TicketAttachment::where('id', $id)->where('user_id', $userId)->first();
        if (!$attachment) {
            throw new ApiException(__('Attachment does not exist'));
        }
        if (!$attachment->isPending()) {
            throw new ApiException(__('Attachment cannot be removed after it has been sent'));
        }
        $this->forceDelete($attachment);
    }

    /**
     * 删除文件（尽力而为）并删除记录。
     */
    public function forceDelete(TicketAttachment $attachment): void
    {
        $this->deleteFile($attachment);
        $attachment->delete();
    }

    /**
     * 按记录里的驱动删除存储对象。失败只记日志并返回 false，让调用方决定是否保留记录以便重试。
     */
    public function deleteFile(TicketAttachment $attachment): bool
    {
        try {
            $this->storage($attachment->driver)->delete($attachment->path);
            return true;
        } catch (\Throwable $e) {
            Log::warning('[ticket-attachment] delete file failed', [
                'id' => $attachment->id,
                'driver' => $attachment->driver,
                'key' => $attachment->path,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * 下载响应：能给临时直链的（S3 预签名 / 公共 URL）就 302，否则由后端流式输出。
     * 非图片一律 attachment + nosniff，避免 HTML/SVG 之类被当页面渲染。
     */
    public function downloadResponse(TicketAttachment $attachment): SymfonyResponse
    {
        $storage = $this->storage($attachment->driver);
        $inline = $attachment->is_image && in_array($attachment->mime, AttachmentConfig::INLINE_IMAGE_MIMES, true);
        $mime = $inline ? $attachment->mime : ($attachment->mime ?: 'application/octet-stream');

        $url = $storage->temporaryUrl($attachment->path, self::DOWNLOAD_URL_TTL, $attachment->original_name, $inline, $mime);
        if ($url !== null) {
            return new RedirectResponse($url, 302, ['Cache-Control' => 'private, no-store']);
        }

        $response = $storage->response($attachment->path, $mime, $attachment->original_name, $inline);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, max-age=86400');
        return $response;
    }

    public function usedBytesLast24h(int $userId): int
    {
        return (int) TicketAttachment::where('user_id', $userId)
            ->where('created_at', '>', time() - 86400)
            ->sum('size');
    }

    public function pendingCount(int $userId): int
    {
        return TicketAttachment::where('user_id', $userId)->whereNull('ticket_message_id')->count();
    }

    /**
     * @throws ApiException
     */
    private function assertQuota(int $userId, int $size): void
    {
        // 待绑定的附件不能囤积：一条消息最多带 maxCount 个，没发出去的会在 24h 后被回收
        if ($this->pendingCount($userId) >= $this->config->maxCount) {
            throw new ApiException(__('Too many attachments, at most :count per message', ['count' => $this->config->maxCount]));
        }
        $quota = $this->config->dailyQuotaBytes();
        if ($quota > 0 && $this->usedBytesLast24h($userId) + $size > $quota) {
            throw new ApiException(__('Attachment daily quota exceeded'));
        }
    }

    /**
     * 按文件内容判定类型。
     *
     * 图片：以 finfo 探测到的 MIME 为准（剪贴板粘贴常常没有可靠的文件名），并用 getimagesize
     * 复核，扩展名统一改写成探测结果对应的扩展名；非图片：扩展名取自文件名，内容 MIME 必须
     * 与扩展名相容。
     *
     * @return array{0:string,1:string,2:bool,3:?int,4:?int} [ext, mime, isImage, width, height]
     * @throws ApiException
     */
    private function classify(UploadedFile $file, string $path): array
    {
        $detected = strtolower((string) ($file->getMimeType() ?: 'application/octet-stream'));

        if (isset(self::IMAGE_EXT_BY_MIME[$detected])) {
            $info = @getimagesize($path);
            if ($info === false || strtolower((string) ($info['mime'] ?? '')) !== $detected) {
                throw new ApiException(__('Attachment content does not match its type'));
            }
            return [self::IMAGE_EXT_BY_MIME[$detected], $detected, true, (int) $info[0], (int) $info[1]];
        }

        $ext = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?? '';
        if ($ext === '') {
            throw new ApiException(__('Attachment type is not allowed'));
        }
        if (in_array($ext, self::IMAGE_EXT_BY_MIME, true) || $ext === 'jpeg') {
            // 顶着图片扩展名却不是可识别的图片内容
            throw new ApiException(__('Attachment content does not match its type'));
        }
        $expected = self::MIME_BY_EXTENSION[$ext] ?? null;
        if ($expected !== null && !$this->mimeMatches($detected, $expected)) {
            throw new ApiException(__('Attachment content does not match its type'));
        }
        return [$ext, $detected, false, null, null];
    }

    /**
     * @param string[] $expected
     */
    private function mimeMatches(string $mime, array $expected): bool
    {
        foreach ($expected as $candidate) {
            if (str_ends_with($candidate, '/') ? str_starts_with($mime, $candidate) : $mime === $candidate) {
                return true;
            }
        }
        return false;
    }

    /**
     * 文件名只用于下载时展示：去掉路径与控制字符、限制长度，并保证扩展名与实际内容一致。
     */
    private function sanitizeName(string $name, string $ext): string
    {
        $name = basename(str_replace('\\', '/', trim($name)));
        $name = preg_replace('/[\x00-\x1F\x7F"]+/u', '', $name) ?? '';
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $base = pathinfo($name, PATHINFO_FILENAME);
        if ($base === '' || $base === '.') {
            $base = 'attachment';
        }
        $base = Str::limit($base, 150, '');
        return $base . '.' . $ext;
    }
}
