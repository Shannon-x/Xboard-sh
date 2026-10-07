<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserNotificationPref;
use App\Services\Notification\NotificationPreference;
use Illuminate\Http\Request;

/**
 * 用户端「通知设置」：按类别开关邮件通知。交易类（收据、提现、验证码）不在列表里，永远发送。
 */
class NotificationController extends Controller
{
    public function prefs(Request $request)
    {
        $user = User::find($request->user()->id);
        return $this->success(NotificationPreference::view($user));
    }

    /** body: { prefs: { billing: true, marketing: false, ... } }，只改传了的类别 */
    public function save(Request $request)
    {
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
        $user = User::find($request->user()->id);
        NotificationPreference::setMany($user, $prefs, UserNotificationPref::SOURCE_PANEL, $request->ip());
        return $this->success(NotificationPreference::view($user));
    }
}
