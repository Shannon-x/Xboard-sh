<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\EmailVerification;
use App\Services\Notification\NotificationPreference;
use Illuminate\Http\Request;

/**
 * 邮件里那条一次性链接（/verify-email/<凭据>）的落点。凭据放在请求体里（不进访问日志），
 * 页面本身不需要登录：凭据对上就算验证通过，换邮箱的链接同时把邮箱换过去。
 * 凭据不认识 / 过期 / 已用过一律 404，页面据此显示「链接已失效，登录后重新发送」。
 */
class EmailVerifyController extends Controller
{
    public function confirm(Request $request)
    {
        $request->validate(['token' => 'required|string|max:64']);
        $result = EmailVerification::confirm((string) $request->input('token'));
        if (!$result) {
            abort(404);
        }
        return $this->success([
            'email' => NotificationPreference::maskEmail((string) $result['user']->email),
            'changed' => $result['changed'],
        ]);
    }
}
