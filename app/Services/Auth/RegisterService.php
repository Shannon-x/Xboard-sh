<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Models\InviteCode;
use App\Models\Plan;
use App\Models\User;
use App\Services\CaptchaService;
use App\Services\Plugin\HookManager;
use App\Services\UserService;
use App\Utils\CacheKey;
use App\Utils\Dict;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RegisterService
{
    /**
     * 验证用户注册请求
     *
     * @param Request $request 请求对象
     * @return array [是否通过, 错误消息]
     */
    public function validateRegister(Request $request): array
    {
        $email = strtolower(trim((string) $request->input('email')));

        // 检查IP注册限制
        if ((int) admin_setting('register_limit_by_ip_enable', 0)) {
            $registerCountByIP = Cache::get(CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip())) ?? 0;
            if ((int) $registerCountByIP >= (int) admin_setting('register_limit_count', 3)) {
                return [
                    false,
                    [
                        429,
                        __('Register frequently, please try again after :minute minute', [
                            'minute' => admin_setting('register_limit_expire', 60)
                        ])
                    ]
                ];
            }
        }

        // 检查验证码
        $captchaService = app(CaptchaService::class);
        [$captchaValid, $captchaError] = $captchaService->verify($request);
        if (!$captchaValid) {
            return [false, $captchaError];
        }

        // 检查邮箱白名单
        if ((int) admin_setting('email_whitelist_enable', 0)) {
            if (
                !Helper::emailSuffixVerify(
                    $email,
                    admin_setting('email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT)
                )
            ) {
                return [false, [400, __('Email suffix is not in the Whitelist')]];
            }
        }

        // 检查Gmail限制
        if ((int) admin_setting('email_gmail_limit_enable', 0)) {
            $prefix = explode('@', $email)[0];
            if (strpos($prefix, '.') !== false || strpos($prefix, '+') !== false) {
                return [false, [400, __('Gmail alias is not supported')]];
            }
        }

        // 检查是否关闭注册
        if ((int) admin_setting('stop_register', 0)) {
            return [false, [400, __('Registration has closed')]];
        }

        // 检查邀请码要求
        if ((int) admin_setting('invite_force', 0)) {
            if (empty($request->input('invite_code'))) {
                return [false, [422, __('You must use the invitation code to register')]];
            }
        }

        // 检查邮箱验证
        if ((int) admin_setting('email_verify', 0)) {
            $inputCode = (string) $request->input('email_code', '');
            if (!preg_match('/^\d{6}$/', $inputCode)) {
                return [false, [400, __('Incorrect email verification code')]];
            }

            $cachedCode = Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $email));
            if ($cachedCode === null || $cachedCode === '' || !hash_equals((string) $cachedCode, $inputCode)) {
                return [false, [400, __('Incorrect email verification code')]];
            }
        }

        // 检查邮箱是否存在
        $exist = User::byEmail($email)->first();
        if ($exist) {
            return [false, [400201, __('Email already exists')]];
        }

        return [true, null];
    }

    /**
     * 处理邀请码
     *
     * @param string $inviteCode 邀请码
     * @return int|null 邀请人ID
     */
    public function handleInviteCode(string $inviteCode): int|null
    {
        // 不允许重复消费：原实现"SELECT WHERE status=UNUSED → save status=USED" 非原子，
        // 并发注册可让同一码绑定多个用户。改成条件 UPDATE：
        //   - invite_never_expire=0（一次性码）：用 affected_rows 判断"我抢到了"
        //   - invite_never_expire=1（永久码）：不需要 mark used，直接读取 user_id 即可
        $neverExpire = (int) admin_setting('invite_never_expire', 0);

        if ($neverExpire) {
            $inviteCodeModel = InviteCode::where('code', $inviteCode)
                ->where('status', InviteCode::STATUS_UNUSED)
                ->first();
        } else {
            // 先读 id（仅用于事后取 user_id）
            $candidate = InviteCode::where('code', $inviteCode)
                ->where('status', InviteCode::STATUS_UNUSED)
                ->first();

            if ($candidate) {
                // 原子地把 UNUSED 翻成 USED。affected !== 1 说明被并发请求抢先消费了，等同 code 已失效。
                $affected = InviteCode::where('id', $candidate->id)
                    ->where('status', InviteCode::STATUS_UNUSED)
                    ->update([
                        'status' => InviteCode::STATUS_USED,
                        'updated_at' => time(),
                    ]);
                $inviteCodeModel = $affected === 1 ? $candidate : null;
            } else {
                $inviteCodeModel = null;
            }
        }

        if (!$inviteCodeModel) {
            if ((int) admin_setting('invite_force', 0)) {
                throw new ApiException(__('Invalid invitation code'));
            }
            return null;
        }

        return $inviteCodeModel->user_id;
    }



    /**
     * 注册用户
     *
     * @param Request $request 请求对象
     * @return array [成功状态, 用户对象或错误信息]
     */
    public function register(Request $request): array
    {
        // 验证注册数据
        [$valid, $error] = $this->validateRegister($request);
        if (!$valid) {
            return [false, $error];
        }

        HookManager::call('user.register.before', $request);

        $email = strtolower(trim((string) $request->input('email')));
        $password = $request->input('password');
        $inviteCode = $request->input('invite_code');

        // 把"消费邀请码 + 创建用户 + 保存"包在同一事务里。
        // 之前 handleInviteCode 把邀请码翻成 USED 是独立 DB 写入，如果后续 createUser/save 抛错或失败，
        // 一次性邀请码就被烧掉但用户没建。事务包住后任一步失败都会回滚邀请码的状态，码不会白扔。
        try {
            $user = DB::transaction(function () use ($email, $password, $inviteCode) {
                // 处理邀请码获取邀请人ID
                $inviteUserId = null;
                if ($inviteCode) {
                    $inviteUserId = $this->handleInviteCode($inviteCode);
                }

                // 创建用户
                $userService = app(UserService::class);
                $user = $userService->createUser([
                    'email' => $email,
                    'password' => $password,
                    'invite_user_id' => $inviteUserId,
                ]);

                // 保存用户 —— save 失败 / 唯一冲突会让事务回滚，handleInviteCode 的 USED 标记一起撤销
                if (!$user->save()) {
                    throw new \RuntimeException('user save failed');
                }
                return $user;
            });
        } catch (ApiException $e) {
            // handleInviteCode 在 invite_force 且 code 无效时会抛 ApiException —— 原样向上传，不要被
            // 上层 catch \RuntimeException 吞成 "Register failed" 掩盖真实原因
            throw $e;
        } catch (\Throwable $e) {
            return [false, [500, __('Register failed')]];
        }

        // after hook 与 cache 副作用放在事务外：它们不应该 rollback 已落库的用户
        HookManager::call('user.register.after', $user);

        // 清除邮箱验证码
        if ((int) admin_setting('email_verify', 0)) {
            Cache::forget(CacheKey::get('EMAIL_VERIFY_CODE', $email));
        }

        // 更新最近登录时间
        $user->last_login_at = time();
        $user->save();

        // 更新IP注册计数
        if ((int) admin_setting('register_limit_by_ip_enable', 0)) {
            $registerCountByIP = Cache::get(CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip())) ?? 0;
            Cache::put(
                CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip()),
                (int) $registerCountByIP + 1,
                (int) admin_setting('register_limit_expire', 60) * 60
            );
        }

        return [true, $user];
    }
}
