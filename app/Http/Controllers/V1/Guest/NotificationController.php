<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserNotificationPref;
use App\Services\Notification\NotificationPreference;
use Illuminate\Http\Request;

/**
 * 邮件里那行「管理通知偏好」打开的免登录偏好页（/notify/<凭据>）的接口，以及 List-Unsubscribe 头的一键退订。
 *
 * 凭据是用户的 notify_key（128 位随机）。拿着链接的人能看到打了码的邮箱、能开关这位用户的通知类别，
 * 除此之外什么都做不了；凭据不对一律 404。偏好页接口把凭据放在请求体里（不进访问日志）；
 * 一键退订的凭据只能在 URL 里（邮件客户端只会原样 POST 那个地址）。
 */
class NotificationController extends Controller
{
    public function fetch(Request $request)
    {
        $user = $this->user($request);
        return $this->success($this->payload($user));
    }

    /** body: { token, prefs: { category: bool } } */
    public function update(Request $request)
    {
        $user = $this->user($request);
        $request->validate([
            'prefs' => 'required|array|min:1',
            'prefs.*' => 'boolean',
        ]);
        $prefs = [];
        foreach ((array) $request->input('prefs') as $category => $enabled) {
            if (!NotificationPreference::isCategory((string) $category)) {
                return $this->fail([422, __('Invalid notification category')]);
            }
            $prefs[(string) $category] = (bool) $enabled;
        }
        NotificationPreference::setMany($user, $prefs, UserNotificationPref::SOURCE_EMAIL_LINK, $request->ip());
        return $this->success($this->payload($user));
    }

    /**
     * List-Unsubscribe 的落点：
     *   POST（RFC 8058 一键退订，邮件客户端带 List-Unsubscribe=One-Click 发来）→ 关掉这一类，回 200；
     *   GET（不支持一键退订的客户端会直接打开这个地址）→ 跳到偏好页，由用户自己选。
     */
    public function unsubscribe(Request $request, string $key, string $category)
    {
        $user = NotificationPreference::resolve($key);
        if (!$user || !NotificationPreference::isCategory($category)) {
            abort(404);
        }
        if ($request->isMethod('post')) {
            NotificationPreference::set($user, $category, false, UserNotificationPref::SOURCE_LIST_UNSUBSCRIBE, $request->ip());
            return response('OK', 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
        }
        $target = NotificationPreference::manageUrl($user) . '?from=' . rawurlencode($category);
        return redirect()->away($target, 302, ['Cache-Control' => 'no-store']);
    }

    private function user(Request $request): User
    {
        $request->validate(['token' => 'required|string|max:64']);
        $user = NotificationPreference::resolve((string) $request->input('token'));
        if (!$user) {
            abort(404);
        }
        return $user;
    }

    private function payload(User $user): array
    {
        return ['email' => NotificationPreference::maskEmail((string) $user->email)] + NotificationPreference::view($user);
    }
}
