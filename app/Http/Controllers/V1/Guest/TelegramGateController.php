<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Plugin\HookManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 群管理机器人（sufegroup_bot）的入群门槛接口：只让面板注册用户进群。
 *
 * 机器人收到入群申请时问一句「这个 Telegram 账号是不是面板用户、有没有被封」；
 * 没绑定的，机器人私聊让用户发订阅链接，再调 bind 把 telegram_id 写进账号。
 *
 * 鉴权：请求头 X-Gate-Secret 与 .env 的 TELEGRAM_GROUP_GATE_SECRET 比对（hash_equals）。
 * 没配置这个变量时两条路由都 404，等于功能关闭。
 *
 * 回包只有一个 status 字段，不带邮箱、套餐、到期时间等任何账号信息：
 * 机器人只需要「能不能进」，多给的每个字段都是密钥泄露时的额外损失。
 *
 * status 取值：
 *   member         已绑定、未封禁，可以入群
 *   banned         已绑定，但账号被封禁
 *   unbound        这个 Telegram 账号没有绑定任何面板账号
 *   bound          （仅 bind）刚刚绑定成功，可以入群
 *   invalid_link   （仅 bind）订阅链接无效或找不到对应账号
 *   account_taken  （仅 bind）该面板账号已经绑定了另一个 Telegram 账号
 */
class TelegramGateController extends Controller
{
    /** body: { telegram_id } */
    public function check(Request $request): JsonResponse
    {
        $this->authorizeGate($request);
        $params = $request->validate([
            'telegram_id' => 'required|integer|min:1',
        ]);

        $user = User::where('telegram_id', (int) $params['telegram_id'])->first(['id', 'banned']);
        return $this->success(['status' => $this->statusOf($user)]);
    }

    /** body: { telegram_id, subscribe_url } */
    public function bind(Request $request): JsonResponse
    {
        $this->authorizeGate($request);
        $params = $request->validate([
            'telegram_id' => 'required|integer|min:1',
            'subscribe_url' => 'required|string|max:2048',
        ]);
        $telegramId = (int) $params['telegram_id'];

        // 这个 Telegram 已经绑过某个账号：按那个账号的状态回答，不再改动任何绑定
        $existing = User::where('telegram_id', $telegramId)->first(['id', 'banned']);
        if ($existing) {
            return $this->success(['status' => $this->statusOf($existing)]);
        }

        $token = self::extractSubscribeToken((string) $params['subscribe_url']);
        $user = $token ? User::where('token', $token)->first() : null;
        if (!$user) {
            return $this->success(['status' => 'invalid_link']);
        }
        if ($user->telegram_id && (int) $user->telegram_id !== $telegramId) {
            return $this->success(['status' => 'account_taken']);
        }
        if ($user->banned) {
            return $this->success(['status' => 'banned']);
        }

        // 条件更新：并发的两次绑定只会有一次落库
        $updated = User::whereKey($user->id)->whereNull('telegram_id')->update(['telegram_id' => $telegramId]);
        if (!$updated) {
            $user->refresh();
            return $this->success(['status' => (int) $user->telegram_id === $telegramId ? 'bound' : 'account_taken']);
        }

        $user->telegram_id = $telegramId;
        HookManager::call('user.telegram.bind.after', [$user]);
        return $this->success(['status' => 'bound']);
    }

    private function statusOf(?User $user): string
    {
        if (!$user) {
            return 'unbound';
        }
        return $user->banned ? 'banned' : 'member';
    }

    private function authorizeGate(Request $request): void
    {
        $expected = (string) config('services.telegram_group_gate.secret', '');
        if ($expected === '') {
            abort(404);
        }
        $provided = (string) $request->header('X-Gate-Secret', '');
        if ($provided === '' || !hash_equals($expected, $provided)) {
            abort(401);
        }
    }

    /**
     * 从订阅链接里取出 32 位 hex 的用户 token：既认 ?token=xxx，也认路径末段（/s/xxx 这类自定义订阅路径）。
     * 与 Telegram 插件 /bind 的解析规则一致。
     */
    public static function extractSubscribeToken(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048) {
            return null;
        }
        $parsed = parse_url($url);
        if ($parsed === false) {
            return null;
        }
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $query);
            if (isset($query['token']) && is_string($query['token']) && preg_match('/^[a-f0-9]{32}$/i', $query['token'])) {
                return strtolower($query['token']);
            }
        }
        if (isset($parsed['path'])) {
            $parts = explode('/', trim($parsed['path'], '/'));
            $last = end($parts);
            if (is_string($last) && preg_match('/^[a-f0-9]{32}$/i', $last)) {
                return strtolower($last);
            }
        }
        return null;
    }
}
