<?php

namespace App\Support;

use App\Jobs\SendTelegramJob;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 给管理员推一条 Telegram 文本告警。sendMessageWithAdmin 只查库并派发队列任务，
 * 这里整体 try/catch：Bot 没配置、队列不可用都不能打断调用方的业务流程。
 */
final class AdminAlert
{
    /** 紧急告警直发的单次超时（秒）：看门狗在网页进程 tick 里跑，不能被一条告警拖住 */
    private const DIRECT_TIMEOUT = 5;
    private const LAST_URGENT_KEY = 'monitor:alert:last_urgent';

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

    /**
     * 紧急告警：当场直发 Telegram、不经过队列。用于「队列 / 调度 / Redis 本身出问题」这类告警：
     * 这时候往 send_telegram 队列里塞告警，告警会和故障一起卡住，没人知道（2026-11-23 停摆近三天）。
     *
     * 每个收件人先直发（短超时、不重试），直发失败的再退回队列，队列好了还能补上。
     * 最近一次的结果记进缓存，仪表盘「告警通道」读它；缓存写不进去（Redis 挂了）也不影响发送。
     *
     * @return array{recipients: int, direct: int, queued: int, error: ?string}
     */
    public static function urgent(string $message, string $parseMode = 'markdown', bool $includeStaff = false): array
    {
        $result = ['recipients' => 0, 'direct' => 0, 'queued' => 0, 'error' => null];
        try {
            $telegram = new TelegramService();
            $chatIds = $telegram->getAdminChatIds($includeStaff);
            if ($includeStaff) {
                $chatIds = array_values(array_unique(array_merge($chatIds, self::extraChatIds())));
            }
            $result['recipients'] = count($chatIds);
            if (!$telegram->hasBotToken()) {
                $result['error'] = '没有配置 Telegram Bot Token';
            } elseif ($chatIds === []) {
                $result['error'] = '没有绑定 Telegram 的管理员';
            }
            foreach ($telegram->hasBotToken() ? $chatIds : [] as $chatId) {
                try {
                    $telegram->sendMessageNow($chatId, $message, $parseMode, self::DIRECT_TIMEOUT);
                    $result['direct']++;
                } catch (\Throwable $e) {
                    $result['error'] = self::redactUrls($e->getMessage());
                    try {
                        SendTelegramJob::dispatch($chatId, $message, $parseMode);
                        $result['queued']++;
                    } catch (\Throwable) {
                    }
                }
            }
        } catch (\Throwable $e) {
            // 读管理员列表要查库：库也挂了就只能写日志
            $result['error'] = self::redactUrls($e->getMessage());
        }

        if ($result['error'] !== null) {
            try {
                Log::warning('[admin-alert] 紧急告警直发失败', ['error' => $result['error'], 'direct' => $result['direct'], 'queued' => $result['queued']]);
            } catch (\Throwable) {
            }
        }
        try {
            Cache::forever(self::LAST_URGENT_KEY, $result + ['at' => time()]);
        } catch (\Throwable) {
        }
        return $result;
    }

    /**
     * 错误信息里的网址换成 [url]。Guzzle 连接失败的报错会附上完整请求地址，Telegram 的是 /bot<TOKEN>/sendMessage，
     * Uptime Kuma 的是 /api/push/<token>：原样存进缓存会经仪表盘接口显示在后台、留在日志里。
     */
    public static function redactUrls(string $message): string
    {
        return (string) preg_replace('~[a-z][a-z0-9+.-]*://[^\s"\'<>]+~i', '[url]', $message);
    }

    /** 最近一次紧急告警的发送结果（仪表盘「告警通道」） */
    public static function lastUrgent(): ?array
    {
        try {
            $last = Cache::get(self::LAST_URGENT_KEY);
            return is_array($last) ? $last : null;
        } catch (\Throwable) {
            return null;
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
