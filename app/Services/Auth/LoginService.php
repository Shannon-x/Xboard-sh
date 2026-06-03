<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\AuthService;
use App\Services\Plugin\HookManager;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;

class LoginService
{
    /**
     * 处理用户登录
     *
     * @param string $email 用户邮箱
     * @param string $password 用户密码
     * @return array [成功状态, 用户对象或错误信息]
     */
    public function login(string $email, string $password): array
    {
        $email = strtolower(trim((string) $email));

        // 检查密码错误限制
        if ((int) admin_setting('password_limit_enable', true)) {
            $passwordErrorCount = (int) Cache::get(CacheKey::get('PASSWORD_ERROR_LIMIT', $email), 0);
            if ($passwordErrorCount >= (int) admin_setting('password_limit_count', 5)) {
                return [
                    false,
                    [
                        429,
                        __('There are too many password errors, please try again after :minute minutes.', [
                            'minute' => admin_setting('password_limit_expire', 60)
                        ])
                    ]
                ];
            }
        }

        // 查找用户
        $user = User::byEmail($email)->first();
        if (!$user) {
            return [false, [400, __('Incorrect email or password')]];
        }

        // 验证密码
        if (
            !Helper::multiPasswordVerify(
                $user->password_algo,
                $user->password_salt,
                $password,
                $user->password
            )
        ) {
            // 增加密码错误计数
            if ((int) admin_setting('password_limit_enable', true)) {
                $passwordErrorCount = (int) Cache::get(CacheKey::get('PASSWORD_ERROR_LIMIT', $email), 0);
                Cache::put(
                    CacheKey::get('PASSWORD_ERROR_LIMIT', $email),
                    (int) $passwordErrorCount + 1,
                    60 * (int) admin_setting('password_limit_expire', 60)
                );
            }
            return [false, [400, __('Incorrect email or password')]];
        }

        // 检查账户状态
        if ($user->banned) {
            return [false, [400, __('Your account has been suspended')]];
        }

        // 历史密码（md5 / sha256 / *salt）登录通过后透明升级到 bcrypt。
        // Helper::multiPasswordVerify 注释承诺过"一次性登录后升级"，原本没真正实现 —— 这里补上。
        // 升级动作对前端完全透明：明文密码已经在握，只是把 DB 里弱哈希换成 bcrypt 并清空 algo/salt。
        if (!empty($user->password_algo)) {
            $user->password = password_hash($password, PASSWORD_DEFAULT);
            $user->password_algo = null;
            $user->password_salt = null;
        }

        // 更新最后登录时间
        $user->last_login_at = time();
        $user->save();

        HookManager::call('user.login.after', $user);
        return [true, $user];
    }

    /**
     * 处理密码重置
     *
     * @param string $email 用户邮箱
     * @param string $emailCode 邮箱验证码
     * @param string $password 新密码
     * @return array [成功状态, 结果或错误信息]
     */
    public function resetPassword(string $email, string $emailCode, string $password): array
    {
        $email = strtolower(trim((string) $email));
        $inputCode = (string) $emailCode;

        if (!preg_match('/^\d{6}$/', $inputCode)) {
            return [false, [400, __('Incorrect email verification code')]];
        }

        // 检查重置请求限制
        $forgetRequestLimitKey = CacheKey::get('FORGET_REQUEST_LIMIT', $email);
        $forgetRequestLimit = (int) Cache::get($forgetRequestLimitKey);
        if ($forgetRequestLimit >= 3) {
            return [false, [429, __('Reset failed, Please try again later')]];
        }

        // 验证邮箱验证码
        $cachedCode = Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $email));
        if ($cachedCode === null || $cachedCode === '' || !hash_equals((string) $cachedCode, $inputCode)) {
            Cache::put($forgetRequestLimitKey, $forgetRequestLimit + 1, 300);
            return [false, [400, __('Incorrect email verification code')]];
        }

        // 查找用户
        $user = User::byEmail($email)->first();
        if (!$user) {
            return [false, [400, __('This email is not registered in the system')]];
        }

        // 更新密码
        $user->password = password_hash($password, PASSWORD_DEFAULT);
        $user->password_algo = null;
        $user->password_salt = null;

        if (!$user->save()) {
            return [false, [500, __('Reset failed')]];
        }

        HookManager::call('user.password.reset.after', $user);

        // 清除邮箱验证码
        Cache::forget(CacheKey::get('EMAIL_VERIFY_CODE', $email));
        // 清除 PASSWORD_ERROR_LIMIT 与 FORGET_REQUEST_LIMIT：用户已经走完合法的"忘记密码"流程，
        // 不应该继续被"之前的错试次数"困住下次登录。
        Cache::forget(CacheKey::get('PASSWORD_ERROR_LIMIT', $email));
        Cache::forget($forgetRequestLimitKey);

        (new AuthService($user))->removeAllSessions();

        return [true, true];
    }


    /**
     * 生成临时登录令牌和快速登录URL
     *
     * @param User $user 用户对象
     * @param string $redirect 重定向路径
     * @return string|null 快速登录URL
     */
    public function generateQuickLoginUrl(User $user, ?string $redirect = null): ?string
    {
        if (!$user || !$user->exists) {
            return null;
        }

        $code = Helper::guid();
        $key = CacheKey::get('TEMP_TOKEN', $code);

        Cache::put($key, $user->id, 60);

        // 防 open redirect：与 MailLinkService::sanitizeRedirect 同款规则
        $redirect = self::sanitizeQuickLoginRedirect($redirect);
        $loginRedirect = '/#/login?verify=' . $code . '&redirect=' . rawurlencode($redirect);

        if (admin_setting('app_url')) {
            $url = admin_setting('app_url') . $loginRedirect;
        } else {
            $url = url($loginRedirect);
        }

        return $url;
    }

    private static function sanitizeQuickLoginRedirect(?string $redirect): string
    {
        $fallback = 'dashboard';
        if ($redirect === null) {
            return $fallback;
        }
        $r = trim((string) $redirect);
        if ($r === '' || strlen($r) > 255) {
            return $fallback;
        }
        if (str_starts_with($r, '//') || str_starts_with($r, '\\') || preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*:#', $r)) {
            return $fallback;
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $r)) {
            return $fallback;
        }
        return $r;
    }
}
