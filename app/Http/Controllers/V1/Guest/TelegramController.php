<?php

namespace App\Http\Controllers\V1\Guest;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\TelegramService;
use App\Services\UserService;
use Illuminate\Http\Request;

class TelegramController extends Controller
{
    protected ?object $msg = null;
    protected TelegramService $telegramService;
    protected UserService $userService;

    public function __construct(TelegramService $telegramService, UserService $userService)
    {
        $this->telegramService = $telegramService;
        $this->userService = $userService;
    }

    public function webhook(Request $request): void
    {
        // access_token 历史是 md5(bot_token)（32 hex），新 secret 走 random_bytes(20)→40 hex，
        // 因此长度限制放宽到 32–64 hex。两套都接受，便于灰度切换。
        $params = $request->validate([
            'access_token' => 'required|string|min:32|max:64|regex:/^[a-f0-9]+$/i',
        ]);

        $botToken = (string) admin_setting('telegram_bot_token', '');
        if ($botToken === '') {
            throw new ApiException('access_token is error', 401);
        }

        $provided = (string) $params['access_token'];
        $legacyExpected = md5($botToken);                                              // 旧凭据 = md5(bot_token)
        $secretExpected = (string) admin_setting('telegram_webhook_secret', '');       // 新凭据，独立可轮换

        $ok = hash_equals($legacyExpected, $provided)
            || ($secretExpected !== '' && hash_equals($secretExpected, $provided));

        if (!$ok) {
            throw new ApiException('access_token is error', 401);
        }

        $data = $request->json()->all();

        $this->formatMessage($data);
        $this->formatChatJoinRequest($data);
        $this->handle();
    }

    private function handle(): void
    {
        if (!$this->msg)
            return;
        $msg = $this->msg;
        $this->processBotName($msg);
        try {
            HookManager::call('telegram.message.before', [$msg]);
            $handled = HookManager::filter('telegram.message.handle', false, [$msg]);
            if (!$handled) {
                HookManager::call('telegram.message.unhandled', [$msg]);
            }
            HookManager::call('telegram.message.after', [$msg]);
        } catch (\Exception $e) {
            HookManager::call('telegram.message.error', [$msg, $e]);
            $this->telegramService->sendMessage($msg->chat_id, $e->getMessage());
        }
    }

    private function processBotName(object $msg): void
    {
        $commandParts = explode('@', $msg->command);

        if (count($commandParts) === 2) {
            $botName = $this->getBotName();
            if ($commandParts[1] === $botName) {
                $msg->command = $commandParts[0];
            }
        }
    }

    private function getBotName(): string
    {
        $response = $this->telegramService->getMe();
        return $response->result->username;
    }

    /**
     * 把 Telegram update 归一成插件用的 msg 对象。
     *
     * 之前只认 message.text：管理员直接回一张截图（photo + caption，没有 text）会被整个丢掉；
     * 回复的是一条图片通知时 reply_to_message 只有 caption 没有 text，同样被丢掉。
     * 现在 text 取 text ?? caption，图片 / 文件收进 attachments，reply_text 取 text ?? caption。
     */
    private function formatMessage(array $data): void
    {
        $message = $data['message'] ?? null;
        if (!is_array($message) || !isset($message['chat']['id'], $message['message_id'])) {
            return;
        }

        $text = (string) ($message['text'] ?? $message['caption'] ?? '');
        $attachments = [];

        // photo 是同一张图的多个尺寸，按面积升序，取最后一个（最大）
        if (!empty($message['photo']) && is_array($message['photo'])) {
            $largest = end($message['photo']);
            if (is_array($largest) && !empty($largest['file_id'])) {
                $attachments[] = [
                    'kind' => 'photo',
                    'file_id' => (string) $largest['file_id'],
                    'file_name' => null,
                    'mime' => 'image/jpeg',
                    'size' => isset($largest['file_size']) ? (int) $largest['file_size'] : null,
                ];
            }
        }

        if (!empty($message['document']['file_id'])) {
            $document = $message['document'];
            $attachments[] = [
                'kind' => 'document',
                'file_id' => (string) $document['file_id'],
                'file_name' => isset($document['file_name']) ? (string) $document['file_name'] : null,
                'mime' => isset($document['mime_type']) ? (string) $document['mime_type'] : null,
                'size' => isset($document['file_size']) ? (int) $document['file_size'] : null,
            ];
        }

        if ($text === '' && !$attachments) {
            return;
        }

        $parts = $text === '' ? [''] : explode(' ', $text);

        $this->msg = (object) [
            'command' => $parts[0],
            'args' => array_slice($parts, 1),
            'chat_id' => $message['chat']['id'],
            'message_id' => $message['message_id'],
            'message_type' => 'message',
            'text' => $text,
            'is_private' => ($message['chat']['type'] ?? '') === 'private',
            'attachments' => $attachments,
        ];

        $reply = $message['reply_to_message'] ?? null;
        if (is_array($reply)) {
            $replyText = $reply['text'] ?? $reply['caption'] ?? null;
            if (is_string($replyText) && $replyText !== '') {
                $this->msg->message_type = 'reply_message';
                $this->msg->reply_text = $replyText;
            }
        }
    }

    private function formatChatJoinRequest(array $data): void
    {
        $joinRequest = $data['chat_join_request'] ?? null;
        if (!$joinRequest)
            return;

        $chatId = $joinRequest['chat']['id'] ?? null;
        $userId = $joinRequest['from']['id'] ?? null;

        if (!$chatId || !$userId)
            return;

        $user = User::where('telegram_id', $userId)->first();

        if (!$user) {
            $this->telegramService->declineChatJoinRequest($chatId, $userId);
            return;
        }

        if (!$this->userService->isAvailable($user)) {
            $this->telegramService->declineChatJoinRequest($chatId, $userId);
            return;
        }

        $this->telegramService->approveChatJoinRequest($chatId, $userId);
    }
}
