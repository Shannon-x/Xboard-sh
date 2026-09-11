<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UserChangePassword;
use App\Http\Requests\User\UserTransfer;
use App\Http\Requests\User\UserUpdate;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Auth\LoginService;
use App\Services\AuthService;
use App\Services\Plugin\HookManager;
use App\Services\UserService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    protected $loginService;

    public function __construct(
        LoginService $loginService
    ) {
        $this->loginService = $loginService;
    }

    public function getActiveSession(Request $request)
    {
        $user = $request->user();
        $authService = new AuthService($user);
        return $this->success($authService->getSessions());
    }

    public function removeActiveSession(Request $request)
    {
        $user = $request->user();
        $authService = new AuthService($user);
        return $this->success($authService->removeSession($request->input('session_id')));
    }

    public function logout(Request $request)
    {
        // instanceof 防 TransientToken；sanctum guard 下 Bearer 请求恒为 PersonalAccessToken
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
            $token->delete();
        }
        return $this->success(true);
    }

    public function checkLogin(Request $request)
    {
        $data = [
            'is_login' => $request->user()?->id ? true : false
        ];
        if ($request->user()?->is_admin) {
            $data['is_admin'] = true;
        }
        return $this->success($data);
    }

    public function changePassword(UserChangePassword $request)
    {
        $user = $request->user();

        // 老密码错误计数：原实现完全不限流，已盗 token 的攻击者可在线撞库 old_password，得手后改密 + 踢掉所有 session
        // 这里复用 PASSWORD_ERROR_LIMIT cache key 与登录共享同一窗口（按 user.email），命中规则同 LoginService
        $email = (string) ($user->email ?? '');
        if ((int) admin_setting('password_limit_enable', true) && $email !== '') {
            $key = \App\Utils\CacheKey::get('PASSWORD_ERROR_LIMIT', $email);
            $errorCount = (int) \Illuminate\Support\Facades\Cache::get($key, 0);
            if ($errorCount >= (int) admin_setting('password_limit_count', 5)) {
                return $this->fail([429, __('There are too many password errors, please try again after :minute minutes.', [
                    'minute' => admin_setting('password_limit_expire', 60),
                ])]);
            }
        }

        if (
            !Helper::multiPasswordVerify(
                $user->password_algo,
                $user->password_salt,
                $request->input('old_password'),
                $user->password
            )
        ) {
            if ((int) admin_setting('password_limit_enable', true) && $email !== '') {
                $key = \App\Utils\CacheKey::get('PASSWORD_ERROR_LIMIT', $email);
                $errorCount = (int) \Illuminate\Support\Facades\Cache::get($key, 0);
                \Illuminate\Support\Facades\Cache::put(
                    $key,
                    $errorCount + 1,
                    60 * (int) admin_setting('password_limit_expire', 60)
                );
            }
            return $this->fail([400, __('The old password is wrong')]);
        }
        $user->password = password_hash($request->input('new_password'), PASSWORD_DEFAULT);
        $user->password_algo = NULL;
        $user->password_salt = NULL;
        if (!$user->save()) {
            return $this->fail([400, __('Save failed')]);
        }

        // 改密成功后清掉错误计数，避免合法用户被自己之前的错试影响
        if ($email !== '') {
            \Illuminate\Support\Facades\Cache::forget(\App\Utils\CacheKey::get('PASSWORD_ERROR_LIMIT', $email));
        }

        $currentToken = $user->currentAccessToken();
        if ($currentToken) {
            $user->tokens()->where('id', '!=', $currentToken->id)->delete();
        } else {
            $user->tokens()->delete();
        }

        return $this->success(true);
    }

    public function info(Request $request)
    {
        $user = User::where('id', $request->user()->id)
            ->select([
                'email',
                'transfer_enable',
                'last_login_at',
                'created_at',
                'banned',
                'remind_expire',
                'remind_traffic',
                'auto_renew',
                'expired_at',
                'balance',
                'commission_balance',
                'plan_id',
                'plan_options',
                'discount',
                'commission_rate',
                'telegram_id',
                'uuid'
            ])
            ->first();
        if (!$user) {
            return $this->fail([400, __('The user does not exist')]);
        }
        $user['avatar_url'] = 'https://cdn.v2ex.com/gravatar/' . md5($user->email) . '?s=64&d=identicon';
        if (!$request->boolean('include_addon_groups') && $user->plan_options) {
            $user->plan_options = array_intersect_key($user->plan_options, \App\Services\PlanCustomizationService::LIMITS);
        }
        return $this->success($user);
    }

    public function getStat(Request $request)
    {
        $stat = [
            Order::where('status', 0)
                ->where('user_id', $request->user()->id)
                ->count(),
            Ticket::where('status', 0)
                ->where('user_id', $request->user()->id)
                ->count(),
            User::where('invite_user_id', $request->user()->id)
                ->count()
        ];
        return $this->success($stat);
    }

    public function getSubscribe(Request $request)
    {
        $user = User::where('id', $request->user()->id)
            ->select([
                'plan_id',
                'token',
                'expired_at',
                'u',
                'd',
                'transfer_enable',
                'email',
                'uuid',
                'device_limit',
                'speed_limit',
                'next_reset_at',
                'plan_options',
                'transfer_topup',
                'admin_group_ids'
            ])
            ->first();
        if (!$user) {
            return $this->fail([400, __('The user does not exist')]);
        }
        $customizer = app(\App\Services\PlanCustomizationService::class);
        $planModel = null;
        if ($user->plan_id) {
            $planModel = Plan::find($user->plan_id);
            if (!$planModel) {
                return $this->fail([400, __('Subscription plan does not exist')]);
            }
            $user['plan'] = $customizer->forLegacyUser($planModel, $user);
        }
        $user['subscribe_url'] = Helper::getSubscribeUrl($user['token']);
        $userService = new UserService();
        $user['reset_day'] = $userService->getResetDay($user);
        // 纯新增键，旧前端不读：本周期流量加购规则 / 已加购量，以及此刻生效的增值线路（带展示名）。
        $user['traffic_topup'] = $customizer->topupSummary($planModel, $user);
        $user['addon_groups'] = $customizer->addonGroupsForUser($planModel, $user);
        // 续费助手：提醒天数 + 上次配置的续费规格与金额 + 自动续费状态（需要余额 / 折扣等未 select 的列）
        $user['renew'] = app(\App\Services\RenewService::class)->payload(User::find($request->user()->id));
        if (!$request->boolean('include_addon_groups') && $user->plan_options) {
            $user->plan_options = array_intersect_key($user->plan_options, \App\Services\PlanCustomizationService::LIMITS);
        } elseif ($user->plan_options) {
            // granted_groups 在响应里始终是「此刻生效」的集合（套餐实时包含 ∪ 已购 ∪ 管理员授予），
            // 前端据此判断哪些增值线路已解锁；它是派生键，回传时服务端忽略。
            $options = $user->plan_options;
            $options[\App\Services\PlanCustomizationService::GRANTED_KEY] = $user->addonGroupIds();
            $user->plan_options = $options;
        }
        $user = HookManager::filter('user.subscribe.response', $user);
        return $this->success($user);
    }

    public function resetSecurity(Request $request)
    {
        $user = $request->user();
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        if (!$user->save()) {
            return $this->fail([400, __('Reset failed')]);
        }
        return $this->success(Helper::getSubscribeUrl($user->token));
    }

    public function update(UserUpdate $request)
    {
        $updateData = $request->only([
            'remind_expire',
            'remind_traffic',
            'auto_renew',
        ]);

        $user = $request->user();
        try {
            $user->update($updateData);
        } catch (\Exception $e) {
            return $this->fail([400, __('Save failed')]);
        }

        return $this->success(true);
    }

    public function transfer(UserTransfer $request)
    {
        $amount = $request->input('transfer_amount');
        try {
            DB::transaction(function () use ($request, $amount) {
                $user = User::lockForUpdate()->find($request->user()->id);
                if (!$user) {
                    throw new \Exception(__('The user does not exist'));
                }
                if ($amount > $user->commission_balance) {
                    throw new \Exception(__('Insufficient commission balance'));
                }
                $user->commission_balance -= $amount;
                $user->balance += $amount;
                if (!$user->save()) {
                    throw new \Exception(__('Transfer failed'));
                }
            });
        } catch (\Exception $e) {
            return $this->fail([400, $e->getMessage()]);
        }
        return $this->success(true);
    }

    public function getQuickLoginUrl(Request $request)
    {
        $user = $request->user();

        $url = $this->loginService->generateQuickLoginUrl($user, $request->input('redirect'));
        return $this->success($url);
    }
}
