<?php

namespace App\Support;

use App\Jobs\SendTelegramJob;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Log;

/**
 * 给管理员推一条 Telegram 文本告警。sendMessageWithAdmin 只查库并派发队列任务，
 * 这里整体 try/catch：Bot 没配置、队列不可用都不能打断调用方的业务流程。
 */
final class AdminAlert
{
    /**
     * @param string $parseMode    默认 markdown（整段转义，只能纯文本）；要加粗 / 等宽排版时传 html，
     *                             动态值自行用 TelegramService::escapeHtml 转义
     * @param bool   $includeStaff 同时推给绑了 Telegram 的客服（is_staff），以及后台设置 admin_alert_chat_ids
     *                             里的会话（如客服群，逗号分隔的 chat id，群组是负数）。付款异常类告警用：
     *                             管理员不常看 Telegram，得有人能第一时间看到
     */
    public static function send(string $message, string $parseMode = 'markdown', bool $includeStaff = false): void
    {
        try {
            (new TelegramService())->sendMessageWithAdmin($message, $includeStaff, $parseMode);
            if ($includeStaff) {
                foreach (self::extraChatIds() as $chatId) {
                    SendTelegramJob::dispatch($chatId, $message, $parseMode);
                }
            }
        } catch (\Throwable $e) {
            try {
                Log::warning('[admin-alert] Telegram 通知发送失败', ['message' => $e->getMessage()]);
            } catch (\Throwable $ignored) {
            }
        }
    }

    /** @return int[] */
    private static function extraChatIds(): array
    {
        $ids = [];
        foreach (preg_split('/[\s,，;]+/u', (string) admin_setting('admin_alert_chat_ids', '')) ?: [] as $id) {
            if (preg_match('/^-?\d+$/', $id)) {
                $ids[(int) $id] = true;
            }
        }
        return array_keys($ids);
    }
}
