<?php

namespace App\Jobs;

use App\Models\TicketAttachment;
use App\Services\TelegramService;
use App\Services\TicketAttachmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 把一个工单附件推送到某个 Telegram 会话。
 *
 * 文件内容在任务里才从存储读出（不随消息序列化），local / S3 都从后端拉，不依赖 Telegram 能否访问站点 URL
 * （stealth 部署下附件下载地址对外是混淆路径，Telegram 的服务器根本拿不到）。
 */
class SendTelegramAttachmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** sendPhoto 只接受这几种；其它图片（gif/webp）与非图片一律 sendDocument 保原样 */
    private const PHOTO_MIMES = ['image/jpeg', 'image/png'];

    public $tries = 3;
    public $timeout = 120;

    public function __construct(
        protected int $telegramId,
        protected int $attachmentId,
        protected string $caption = ''
    ) {
        $this->onQueue('send_telegram');
    }

    public function handle(): void
    {
        $attachment = TicketAttachment::find($this->attachmentId);
        if (!$attachment) {
            // 通知排队期间被清理 / 删除了，不算失败
            return;
        }

        $service = new TicketAttachmentService();
        try {
            $contents = $service->storage($attachment->driver)->get($attachment->path);
        } catch (\Throwable $e) {
            Log::warning('[telegram] 读取工单附件失败，跳过推送', [
                'attachment_id' => $attachment->id,
                'driver' => $attachment->driver,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $telegram = new TelegramService();
        $asPhoto = $attachment->is_image
            && in_array($attachment->mime, self::PHOTO_MIMES, true)
            && strlen($contents) <= TelegramService::MAX_PHOTO_BYTES;

        if (!$asPhoto) {
            $telegram->sendDocument($this->telegramId, $contents, $attachment->original_name, $this->caption);
            return;
        }

        try {
            $telegram->sendPhoto($this->telegramId, $contents, $attachment->original_name, $this->caption);
        } catch (\Throwable $e) {
            // Telegram 会拒收一部分合法图片（尺寸过小 / 长宽比过于极端 / CMYK JPEG 等，报
            // IMAGE_PROCESS_FAILED 或 PHOTO_INVALID_DIMENSIONS）。此时按文件重发，管理员照样拿得到，
            // 而不是重试三次后进死信队列、通知里那张截图无声消失。
            Log::warning('[telegram] sendPhoto 被拒，改用 sendDocument 重发', [
                'attachment_id' => $attachment->id,
                'mime' => $attachment->mime,
                'size' => strlen($contents),
                'error' => $e->getMessage(),
            ]);
            $telegram->sendDocument($this->telegramId, $contents, $attachment->original_name, $this->caption);
        }
    }
}
