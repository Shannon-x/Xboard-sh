<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\Telegram\GateBindTicket;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class TelegramController extends Controller
{
    public function getBotInfo()
    {
        $telegramService = new TelegramService();
        $response = $telegramService->getMe();
        $data = [
            'username' => $response->result->username
        ];
        return $this->success($data);
    }

    public function unbind(Request $request)
    {
        $user = User::where('user_id', $request->user()->id)->first();
    }

    /**
     * 群管理机器人的「一键绑定」：把票据里的 Telegram 账号绑到当前登录的面板账号。
     *
     * body: { ticket, preview?: bool, replace?: bool }
     *   preview=true 只验票、报告绑定后会发生什么，不写库（确认页先调一次决定显示哪个按钮）。
     *   replace=true 允许把账号上原有的另一个 Telegram 换成票据里的这个（确认页的「改绑」按钮）。
     *
     * 回包 data.status：
     *   ready          可以绑定（仅 preview）
     *   bound          已绑定成功
     *   already        这个账号本来就绑着这个 Telegram，无需操作
     *   needs_replace  账号已绑另一个 Telegram，需要用户确认改绑（replace=true 再来一次）
     *   telegram_taken 这个 Telegram 已经绑在别的面板账号上
     *   invalid        票据无效、过期或已用过
     * 另带 data.telegram：票据里的显示名 / 用户名，供确认页展示；bot：机器人用户名，供「返回 Telegram」按钮。
     */
    public function gateBind(Request $request): JsonResponse
    {
        $params = $request->validate([
            'ticket' => 'required|string|max:' . GateBindTicket::MAX_LENGTH,
            'preview' => 'sometimes|boolean',
            'replace' => 'sometimes|boolean',
        ]);

        $secret = (string) config('services.telegram_group_gate.secret', '');
        $ticket = GateBindTicket::parse((string) $params['ticket'], $secret);
        $usedKey = 'tg_gate_bind_used:' . hash('sha256', (string) $params['ticket']);
        if (!$ticket || Cache::has($usedKey)) {
            return $this->success(['status' => 'invalid']);
        }

        /** @var User $user */
        $user = $request->user();
        $info = [
            'telegram' => ['name' => $ticket->displayName, 'username' => $ticket->username],
            'bot' => $ticket->botUsername,
        ];
        $reply = fn (string $status) => $this->success(['status' => $status] + $info);

        if ((int) $user->telegram_id === $ticket->telegramId) {
            return $reply('already');
        }
        $owner = User::where('telegram_id', $ticket->telegramId)->where('id', '!=', $user->id)->exists();
        if ($owner) {
            return $reply('telegram_taken');
        }
        if ($user->telegram_id && empty($params['replace'])) {
            return $reply('needs_replace');
        }
        if (!empty($params['preview'])) {
            return $reply('ready');
        }

        // 条件更新：账号上的 telegram_id 只能从「我们看到的那个值」改过去，并发的两次确认只成功一次
        $updated = User::whereKey($user->id)
            ->where(function ($q) use ($user) {
                $user->telegram_id
                    ? $q->where('telegram_id', $user->telegram_id)
                    : $q->whereNull('telegram_id');
            })
            ->update(['telegram_id' => $ticket->telegramId]);
        if (!$updated) {
            return $reply('invalid');
        }

        // 一张票据只用一次：留到它本来的过期时间为止
        Cache::put($usedKey, 1, max(60, $ticket->expiresAt - time()));
        $user->telegram_id = $ticket->telegramId;
        HookManager::call('user.telegram.bind.after', [$user]);
        return $reply('bound');
    }
}
