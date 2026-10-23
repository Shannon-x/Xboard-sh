<?php

namespace App\Support;

use App\Services\TelegramService;
use Illuminate\Support\Facades\Log;

/**
 * 给管理员推一条 Telegram 文本告警。sendMessageWithAdmin 只查库并派发队列任务，
 * 这里整体 try/catch：Bot 没配置、队列不可用都不能打断调用方的业务流程。
 */
final class AdminAlert
{
    public static function send(string $message): void
    {
        try {
            (new TelegramService())->sendMessageWithAdmin($message);
        } catch (\Throwable $e) {
            try {
                Log::warning('[admin-alert] Telegram 通知发送失败', ['message' => $e->getMessage()]);
            } catch (\Throwable $ignored) {
            }
        }
    }
}
