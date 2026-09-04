<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Jobs\SendTelegramAttachmentJob;
use App\Jobs\SendTelegramJob;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Services\Plugin\HookManager;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    /** Telegram 单条消息正文上限 */
    public const MAX_TEXT_LENGTH = 4096;
    /** sendPhoto / sendDocument 的 caption 上限 */
    public const MAX_CAPTION_LENGTH = 1024;
    /** sendPhoto 的体积上限（Bot API 限制 10MB），超过改走 sendDocument */
    public const MAX_PHOTO_BYTES = 10 * 1024 * 1024;
    /** Bot 能下载的文件上限（getFile 限制 20MB） */
    public const MAX_DOWNLOAD_BYTES = 20 * 1024 * 1024;

    protected string $botToken;
    protected string $apiUrl;
    protected string $fileUrl;

    public function __construct(?string $token = null)
    {
        $this->botToken = (string) admin_setting('telegram_bot_token', $token);
        $this->apiUrl = "https://api.telegram.org/bot{$this->botToken}/";
        $this->fileUrl = "https://api.telegram.org/file/bot{$this->botToken}/";
    }

    /**
     * 每次请求新建 PendingRequest —— 它是有状态的（attach() 会一直挂在实例上），复用会把上一次的文件带进下一次请求。
     */
    protected function newRequest(int $timeout = 30): PendingRequest
    {
        return Http::timeout($timeout)
            ->retry(3, 1000)
            ->withHeaders([
                'Accept' => 'application/json',
            ]);
    }

    /**
     * @param string $parseMode ''（纯文本）| 'markdown'（旧版 Markdown，整段自动转义，只适合不含格式的纯文本）| 'html'（调用方自行用 escapeHtml 转义动态值）
     */
    public function sendMessage(int $chatId, string $text, string $parseMode = ''): void
    {
        if ($parseMode === 'markdown') {
            $text = $this->escapeMarkdown($text);
        }
        $text = self::truncate($text, self::MAX_TEXT_LENGTH);

        $this->request('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $this->normalizeParseMode($parseMode),
            // 工单正文里的链接不需要预览卡片，反而会把通知撑得很长
            'link_preview_options' => json_encode(['is_disabled' => true]),
        ]);
    }

    /**
     * 发送图片（<= 10MB 的 jpeg/png）。caption 为纯文本。
     */
    public function sendPhoto(int $chatId, string $contents, string $filename, string $caption = ''): void
    {
        $this->requestWithFile('sendPhoto', 'photo', $contents, $filename, [
            'chat_id' => $chatId,
            'caption' => self::truncate($caption, self::MAX_CAPTION_LENGTH) ?: null,
        ]);
    }

    /**
     * 发送任意文件（<= 50MB）。caption 为纯文本。
     */
    public function sendDocument(int $chatId, string $contents, string $filename, string $caption = ''): void
    {
        $this->requestWithFile('sendDocument', 'document', $contents, $filename, [
            'chat_id' => $chatId,
            'caption' => self::truncate($caption, self::MAX_CAPTION_LENGTH) ?: null,
        ]);
    }

    /**
     * 取用户发给 Bot 的文件信息（file_path 用于 downloadFile）。
     */
    public function getFile(string $fileId): object
    {
        return $this->request('getFile', ['file_id' => $fileId]);
    }

    /**
     * 下载 getFile 返回的 file_path 对应的内容。
     */
    public function downloadFile(string $filePath): string
    {
        $filePath = ltrim($filePath, '/');
        try {
            $response = $this->newRequest(60)->get($this->fileUrl . $filePath);
        } catch (\Throwable $e) {
            throw new ApiException("Telegram 文件下载失败: {$e->getMessage()}");
        }
        if (!$response->successful()) {
            throw new ApiException("Telegram 文件下载失败: HTTP {$response->status()}");
        }
        $body = $response->body();
        if (strlen($body) > self::MAX_DOWNLOAD_BYTES) {
            throw new ApiException('Telegram 文件超过 20MB 上限');
        }
        return $body;
    }

    /**
     * HTML parse_mode 下动态值必须转义，否则用户在工单里写个 <b> 就能改写通知排版。
     */
    public static function escapeHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
    }

    /**
     * 按字符截断，尾部留提示。
     */
    public static function truncate(string $text, int $limit, string $suffix = "\n…（内容过长已截断）"): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        $keep = max(0, $limit - mb_strlen($suffix));
        return mb_substr($text, 0, $keep) . $suffix;
    }

    /**
     * 转义 Telegram 旧版 Markdown 特殊字符
     */
    protected function escapeMarkdown(string $text): string
    {
        $escapeChars = ['_', '*', '`', '['];
        $escapedText = '';

        for ($i = 0; $i < strlen($text); $i++) {
            $char = $text[$i];
            if (in_array($char, $escapeChars, true)) {
                $escapedText .= '\\' . $char;
            } else {
                $escapedText .= $char;
            }
        }

        return $escapedText;
    }

    protected function normalizeParseMode(string $parseMode): ?string
    {
        return match (strtolower($parseMode)) {
            'markdown' => 'Markdown',
            'markdownv2' => 'MarkdownV2',
            'html' => 'HTML',
            default => null,
        };
    }

    public function approveChatJoinRequest(int $chatId, int $userId): void
    {
        $this->request('approveChatJoinRequest', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }

    public function declineChatJoinRequest(int $chatId, int $userId): void
    {
        $this->request('declineChatJoinRequest', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }

    public function getMe(): object
    {
        return $this->request('getMe');
    }

    public function setWebhook(string $url): object
    {
        $result = $this->request('setWebhook', ['url' => $url]);
        return $result;
    }

    /**
     * 注册 Bot 命令列表
     */
    public function registerBotCommands(): void
    {
        try {
            $commands = HookManager::filter('telegram.bot.commands', []);

            if (empty($commands)) {
                Log::warning('没有找到任何 Telegram Bot 命令');
                return;
            }

            $this->request('setMyCommands', [
                'commands' => json_encode($commands),
                'scope' => json_encode(['type' => 'default'])
            ]);

            Log::info('Telegram Bot 命令注册成功', [
                'commands_count' => count($commands),
                'commands' => $commands
            ]);

        } catch (\Exception $e) {
            Log::error('Telegram Bot 命令注册失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * 获取当前注册的命令列表
     */
    public function getMyCommands(): object
    {
        return $this->request('getMyCommands');
    }

    /**
     * 删除所有命令
     */
    public function deleteMyCommands(): object
    {
        return $this->request('deleteMyCommands');
    }

    /**
     * 已绑定 Telegram 的管理员（可选含客服）的 chat_id 列表。
     *
     * @return int[]
     */
    public function getAdminChatIds(bool $includeStaff = false): array
    {
        return User::whereNotNull('telegram_id')
            ->where(
                fn($q) => $q->where('is_admin', 1)
                    ->when($includeStaff, fn($q) => $q->orWhere('is_staff', 1))
            )
            ->pluck('telegram_id')
            ->map(fn($id) => (int) $id)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param string $parseMode 见 sendMessage()；插件的通知模板用 'html'
     */
    public function sendMessageWithAdmin(string $message, bool $isStaff = false, string $parseMode = 'markdown'): void
    {
        foreach ($this->getAdminChatIds($isStaff) as $chatId) {
            SendTelegramJob::dispatch($chatId, $message, $parseMode);
        }
    }

    /**
     * 把一个工单附件作为图片 / 文件推给管理员（每人一个队列任务，文件在任务里再读）。
     */
    public function sendAttachmentWithAdmin(TicketAttachment $attachment, string $caption = '', bool $isStaff = false): void
    {
        foreach ($this->getAdminChatIds($isStaff) as $chatId) {
            SendTelegramAttachmentJob::dispatch($chatId, $attachment->id, $caption);
        }
    }

    /**
     * 统一走 POST 表单：之前用 GET 把 text 塞进 query，工单正文（最长 1 万字）一超过 URL 长度就整条失败。
     */
    protected function request(string $method, array $params = []): object
    {
        try {
            $response = $this->newRequest()->asForm()->post($this->apiUrl . $method, self::withoutNulls($params));
            return $this->parseResponse($response);
        } catch (\Exception $e) {
            $this->logFailure($method, $params, $e);
            throw new ApiException("Telegram 服务错误: {$e->getMessage()}");
        }
    }

    protected function requestWithFile(string $method, string $field, string $contents, string $filename, array $params = []): object
    {
        try {
            $response = $this->newRequest(120)
                ->attach($field, $contents, $filename)
                ->post($this->apiUrl . $method, self::withoutNulls($params));
            return $this->parseResponse($response);
        } catch (\Exception $e) {
            $this->logFailure($method, $params + ['file_bytes' => strlen($contents)], $e);
            throw new ApiException("Telegram 服务错误: {$e->getMessage()}");
        }
    }

    protected function parseResponse(\Illuminate\Http\Client\Response $response): object
    {
        $data = $response->object();

        if (!$response->successful()) {
            // Telegram 出错时也会带 description（如 "message is too long"），比裸状态码有用得多
            $description = $data->description ?? null;
            throw new ApiException($description ? "Telegram API 错误: {$description}" : "HTTP 请求失败: {$response->status()}");
        }

        if (!isset($data->ok)) {
            throw new ApiException('无效的 Telegram API 响应');
        }

        if (!$data->ok) {
            $description = $data->description ?? '未知错误';
            throw new ApiException("Telegram API 错误: {$description}");
        }

        return $data;
    }

    protected function logFailure(string $method, array $params, \Throwable $e): void
    {
        // setWebhook 的 url 参数里嵌了 access_token；sendMessage 的 text 可能含用户隐私。
        // 错误日志只写脱敏后的元数据，详细 stack 由全局 ExceptionHandler 落 storage/logs。
        Log::error('Telegram API 请求失败', [
            'method' => $method,
            'params_summary' => self::summarizeParams($method, $params),
            'error' => $e->getMessage(),
        ]);
    }

    private static function withoutNulls(array $params): array
    {
        return array_filter($params, static fn($v) => $v !== null);
    }

    /**
     * 把 params 折成不含敏感凭据的元数据，避免 access_token / 用户 chat 文本进日志。
     */
    private static function summarizeParams(string $method, array $params): array
    {
        $summary = ['param_keys' => array_keys($params)];
        // 仅对几个高风险方法主动脱敏；其它方法只记 key 不记 value
        if ($method === 'setWebhook' && isset($params['url'])) {
            $parsed = parse_url((string) $params['url']);
            $summary['url_host'] = $parsed['host'] ?? null;
            $summary['url_path'] = $parsed['path'] ?? null;
            // 故意丢弃 query（access_token 在那里）
        }
        if (isset($params['chat_id'])) {
            // 数字 chat_id 不算高敏，但保留首尾用于排查
            $cid = (string) $params['chat_id'];
            $summary['chat_id_short'] = strlen($cid) > 4 ? substr($cid, 0, 2) . '***' . substr($cid, -2) : '***';
        }
        if (isset($params['text'])) {
            $summary['text_len'] = mb_strlen((string) $params['text']);
        }
        if (isset($params['file_bytes'])) {
            $summary['file_bytes'] = $params['file_bytes'];
        }
        return $summary;
    }
}
