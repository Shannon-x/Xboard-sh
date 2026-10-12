<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EmailVerification;
use Illuminate\Http\Request;

/**
 * 面板里的邮箱验证：重发验证邮件、申请换邮箱、输入邮件里的 6 位验证码。
 * 链接式的「验证通过」在免登录的 Guest\EmailVerifyController::confirm；验证码式的在这里的 confirmCode（必须登录，
 * 验证码只对自己这个账号有效，猜别人的验证码无从下手）。
 */
class EmailVerifyController extends Controller
{
    public function status(Request $request)
    {
        $user = User::find($request->user()->id);
        return $this->success(EmailVerification::view($user));
    }

    /** 重发到当前邮箱（60 秒冷却、每天 5 封）。老用户还没纳入流程的，点这里也会纳入并开始计时 */
    public function send(Request $request)
    {
        $user = User::find($request->user()->id);
        if (EmailVerification::isVerified($user)) {
            return $this->fail([400, __('Your email address is already verified')]);
        }
        if (!EmailVerification::enabled()) {
            return $this->fail([400, __('Email verification is not enabled')]);
        }
        if ($user->email_verify_started_at === null) {
            EmailVerification::start($user, EmailVerification::SOURCE_REGISTER, false);
        }
        $result = EmailVerification::send($user);
        return $this->success(['sent' => $result['sent'], 'resend_after' => $result['resend_after']] + ['status' => EmailVerification::view($user)]);
    }

    /** body: { email, password }：核对密码后往新邮箱发链接；点开链接才换 */
    public function change(Request $request)
    {
        $request->validate([
            'email' => 'required|string|max:64',
            'password' => 'required|string|max:255',
        ]);
        $user = User::find($request->user()->id);
        if (!EmailVerification::enabled()) {
            return $this->fail([400, __('Email verification is not enabled')]);
        }
        if ($user->email_verify_started_at === null && !EmailVerification::isVerified($user)) {
            EmailVerification::start($user, EmailVerification::SOURCE_CHANGE, false);
        }
        $result = EmailVerification::requestChange($user, (string) $request->input('email'), (string) $request->input('password'));
        return $this->success(['sent' => $result['sent'], 'resend_after' => $result['resend_after']] + ['status' => EmailVerification::view($user)]);
    }

    /** body: { code }：邮件里的 6 位验证码。对上就算验证通过（换邮箱的同时把邮箱换过去），输错 5 次作废 */
    public function confirmCode(Request $request)
    {
        $request->validate(['code' => 'required|string|max:16']);
        $user = User::find($request->user()->id);
        if (!EmailVerification::enabled()) {
            return $this->fail([400, __('Email verification is not enabled')]);
        }
        $result = EmailVerification::confirmCode($user, (string) $request->input('code'));
        return $this->success([
            'changed' => $result['changed'],
            'email' => (string) $result['user']->email,
            'status' => EmailVerification::view($result['user']->refresh()),
        ]);
    }
}
