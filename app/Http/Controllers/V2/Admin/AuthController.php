<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\AuthLogin;
use App\Models\User;
use App\Services\Auth\AdminTwoFactorService;
use App\Services\Auth\LoginService;
use App\Services\AuthService;
use Illuminate\Http\Request;

/**
 * 后台登录（挂在 secure_path 之下，不经过 Admin 中间件）：
 *   POST auth/login               邮箱 + 密码 → 两步验证挑战（不发 token）
 *   POST auth/two-factor/setup    首次绑定：用挑战换密钥与二维码
 *   POST auth/two-factor/confirm  首次绑定：输入验证码确认 → 恢复码 + 后台 token
 *   POST auth/two-factor/verify   已绑定：输入验证码 / 恢复码 → 后台 token
 *
 * 用户面板的 /api/v1/passport/auth/login 不动：管理员账号在那边登录只拿普通 token，进不了后台。
 */
class AuthController extends Controller
{
    /** 非管理员邮箱也跑一次 bcrypt，让「不是管理员」与「密码错误」耗时一致，不能靠响应时间枚举管理员邮箱 */
    private const DUMMY_HASH = '$2y$10$Tt.JKDKF6EkaCIepRBgDbOU09g5qbN6FUBDXhDSem9.7zZXeiLIxy';

    public function __construct(
        private LoginService $loginService,
        private AdminTwoFactorService $twoFactor
    ) {
    }

    public function login(AuthLogin $request)
    {
        $email = strtolower(trim((string) $request->input('email')));
        $user = User::byEmail($email)->first();

        if (!$user || !$user->is_admin) {
            password_verify((string) $request->input('password'), self::DUMMY_HASH);
            return $this->fail([400, __('Incorrect email or password')]);
        }

        $wasLocked = LoginService::isPasswordLocked($email);
        [$success, $result] = $this->loginService->authenticate($email, (string) $request->input('password'));
        if (!$success) {
            $this->twoFactor->audit($user, AdminTwoFactorService::ACTION_LOGIN_FAILED, $request, [
                'reason' => $result[1] ?? '',
            ]);
            if (!$wasLocked && LoginService::isPasswordLocked($email)) {
                $this->twoFactor->notifyAdmins(
                    "⚠️ 管理员账号 {$user->email} 因密码连续错误已被临时锁定\nIP：{$request->ip()}\n时间：" . date('Y-m-d H:i:s')
                );
            }
            return $this->fail($result);
        }

        return $this->success($this->twoFactor->issueChallenge($user, $request->ip()));
    }

    public function setup(Request $request)
    {
        $params = $request->validate(['challenge' => 'required|string|max:128']);
        $resolved = $this->twoFactor->resolveChallenge($params['challenge']);
        if (!$resolved) {
            return $this->fail([401, '登录状态已过期，请重新输入密码']);
        }
        if ($resolved['data']['state'] !== AdminTwoFactorService::STATE_SETUP_REQUIRED) {
            return $this->fail([400, '该账号已绑定验证器，请直接输入验证码']);
        }
        return $this->success($this->twoFactor->beginEnrollment($resolved['user']));
    }

    public function confirm(Request $request)
    {
        $params = $request->validate([
            'challenge' => 'required|string|max:128',
            'otp' => 'required|string|max:32',
        ]);
        $resolved = $this->twoFactor->resolveChallenge($params['challenge']);
        if (!$resolved) {
            return $this->fail([401, '登录状态已过期，请重新输入密码']);
        }
        if ($resolved['data']['state'] !== AdminTwoFactorService::STATE_SETUP_REQUIRED) {
            return $this->fail([400, '该账号已绑定验证器，请直接输入验证码']);
        }
        /** @var User $user */
        $user = $resolved['user'];
        $codes = $this->twoFactor->confirmEnrollment($user, $params['otp']);
        if ($codes === null) {
            $locked = $this->twoFactor->failChallenge($resolved['key'], $resolved['data']);
            return $this->fail([400, $locked ? '验证码错误次数过多，请重新登录' : '验证码不正确，请确认验证器里的时间与当前一致后重试']);
        }
        $this->twoFactor->consumeChallenge($resolved['key']);
        $this->loginService->finalize($user);
        $this->twoFactor->audit($user, AdminTwoFactorService::ACTION_TWO_FACTOR_ENROLLED, $request);
        $this->twoFactor->audit($user, AdminTwoFactorService::ACTION_LOGIN, $request, ['first_enrollment' => true]);
        $this->twoFactor->notifyAdmins(
            "🔐 管理员 {$user->email} 已绑定两步验证并登录后台\nIP：{$request->ip()}\n时间：" . date('Y-m-d H:i:s')
        );

        return $this->success([
            'recovery_codes' => $codes,
            'auth' => (new AuthService($user))->generateAdminAuthData($request->ip(), $request->userAgent()),
        ]);
    }

    public function verify(Request $request)
    {
        $params = $request->validate([
            'challenge' => 'required|string|max:128',
            'otp' => 'required|string|max:32',
        ]);
        $resolved = $this->twoFactor->resolveChallenge($params['challenge']);
        if (!$resolved) {
            return $this->fail([401, '登录状态已过期，请重新输入密码']);
        }
        /** @var User $user */
        $user = $resolved['user'];
        if ($resolved['data']['state'] !== AdminTwoFactorService::STATE_REQUIRED) {
            return $this->fail([400, '该账号尚未绑定验证器，请先完成绑定']);
        }

        $check = $this->twoFactor->verifyCode($user, $params['otp']);
        if (!$check['ok']) {
            $locked = $this->twoFactor->failChallenge($resolved['key'], $resolved['data']);
            $this->twoFactor->audit(
                $user,
                $locked ? AdminTwoFactorService::ACTION_TWO_FACTOR_LOCKED : AdminTwoFactorService::ACTION_TWO_FACTOR_FAILED,
                $request
            );
            if ($locked) {
                $this->twoFactor->notifyAdmins(
                    "⚠️ 管理员账号 {$user->email} 密码正确但两步验证连续错误 " . AdminTwoFactorService::CHALLENGE_MAX_ATTEMPTS
                    . " 次，请确认是否本人操作，必要时立即改密码\nIP：{$request->ip()}\n时间：" . date('Y-m-d H:i:s')
                );
            }
            return $this->fail([400, $locked ? '验证码错误次数过多，请重新登录' : '验证码不正确']);
        }

        $this->twoFactor->consumeChallenge($resolved['key']);
        $this->loginService->finalize($user);
        if ($check['recovery']) {
            $this->twoFactor->audit($user, AdminTwoFactorService::ACTION_RECOVERY_CODE_USED, $request, [
                'remaining' => $check['remaining'],
            ]);
        }
        $this->twoFactor->audit($user, AdminTwoFactorService::ACTION_LOGIN, $request, [
            'recovery_code' => $check['recovery'],
        ]);
        $this->twoFactor->notifyAdmins(
            "🔐 管理员 {$user->email} 登录后台" . ($check['recovery'] ? "（使用了恢复码，剩余 {$check['remaining']} 个）" : '')
            . "\nIP：{$request->ip()}\n时间：" . date('Y-m-d H:i:s')
        );

        return $this->success([
            'auth' => (new AuthService($user))->generateAdminAuthData($request->ip(), $request->userAgent()),
            'used_recovery_code' => $check['recovery'],
            'recovery_codes_remaining' => $check['remaining'],
        ]);
    }
}
