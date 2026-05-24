<?php

namespace App\Http\Controllers\V1\Passport;

use App\Helpers\ResponseEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\AuthForget;
use App\Http\Requests\Passport\AuthLogin;
use App\Http\Requests\Passport\AuthRegister;
use App\Services\Auth\GoogleLoginService;
use App\Services\Auth\LoginService;
use App\Services\Auth\MailLinkService;
use App\Services\Auth\RegisterService;
use App\Services\AuthService;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    protected MailLinkService $mailLinkService;
    protected RegisterService $registerService;
    protected LoginService $loginService;
    protected GoogleLoginService $googleLoginService;

    public function __construct(
        MailLinkService $mailLinkService,
        RegisterService $registerService,
        LoginService $loginService,
        GoogleLoginService $googleLoginService
    ) {
        $this->mailLinkService = $mailLinkService;
        $this->registerService = $registerService;
        $this->loginService = $loginService;
        $this->googleLoginService = $googleLoginService;
    }

    /**
     * 通过邮件链接登录
     */
    public function loginWithMailLink(Request $request)
    {
        $params = $request->validate([
            'email' => 'required|string|email:strict|max:64',
            'redirect' => 'nullable|string|max:255'
        ]);

        [$success, $result] = $this->mailLinkService->handleMailLink(
            $params['email'],
            $request->input('redirect')
        );

        if (!$success) {
            return $this->fail($result);
        }

        return $this->success($result);
    }

    /**
     * 用户注册
     */
    public function register(AuthRegister $request)
    {
        [$success, $result] = $this->registerService->register($request);

        if (!$success) {
            return $this->fail($result);
        }

        $authService = new AuthService($result);
        return $this->success($authService->generateAuthData());
    }

    /**
     * 用户登录
     */
    public function login(AuthLogin $request)
    {
        $email = $request->input('email');
        $password = $request->input('password');

        [$success, $result] = $this->loginService->login($email, $password);

        if (!$success) {
            return $this->fail($result);
        }

        $authService = new AuthService($result);
        return $this->success($authService->generateAuthData());
    }

    /**
     * 跳转到 Google OAuth 授权页
     */
    public function googleRedirect(Request $request)
    {
        [$success, $result] = $this->googleLoginService->createRedirectUrl($request);

        if (!$success) {
            return $this->fail($result);
        }

        return redirect()->away($result);
    }

    /**
     * Google OAuth 回调
     */
    public function googleCallback(Request $request)
    {
        return redirect()->to($this->googleLoginService->handleCallback($request));
    }

    /**
     * 通过token登录
     */
    public function token2Login(Request $request)
    {
        $params = $request->validate([
            'verify' => 'nullable|string|max:64',
            'token' => 'nullable|string|max:128',
            'redirect' => 'nullable|string|max:255',
        ]);

        // 处理直接通过token重定向
        if (!empty($params['token'])) {
            $token = $params['token'];
            $redirect = '/#/login?verify=' . rawurlencode((string) $token)
                . '&redirect=' . rawurlencode((string) ($params['redirect'] ?? 'dashboard'));

            return redirect()->to(
                admin_setting('app_url')
                ? admin_setting('app_url') . $redirect
                : url($redirect)
            );
        }

        // 处理通过验证码登录
        if (!empty($params['verify'])) {
            $verify = $params['verify'];
            $userId = $this->mailLinkService->handleTokenLogin($verify);

            if (!$userId) {
                return response()->json([
                    'message' => __('Token error')
                ], 400);
            }

            $user = \App\Models\User::find($userId);

            if (!$user) {
                return response()->json([
                    'message' => __('User not found')
                ], 400);
            }

            $authService = new AuthService($user);

            return response()->json([
                'data' => $authService->generateAuthData()
            ]);
        }

        return response()->json([
            'message' => __('Invalid request')
        ], 400);
    }

    /**
     * 获取快速登录URL
     */
    public function getQuickLoginUrl(Request $request)
    {
        $authorization = $request->input('auth_data') ?? $request->header('authorization');

        if (!$authorization) {
            return response()->json([
                'message' => ResponseEnum::CLIENT_HTTP_UNAUTHORIZED
            ], 401);
        }

        $user = AuthService::findUserByBearerToken($authorization);

        if (!$user) {
            return response()->json([
                'message' => ResponseEnum::CLIENT_HTTP_UNAUTHORIZED_EXPIRED
            ], 401);
        }

        $url = $this->loginService->generateQuickLoginUrl($user, $request->input('redirect'));
        return $this->success($url);
    }

    /**
     * 忘记密码处理
     */
    public function forget(AuthForget $request)
    {
        [$success, $result] = $this->loginService->resetPassword(
            $request->input('email'),
            $request->input('email_code'),
            $request->input('password')
        );

        if (!$success) {
            return $this->fail($result);
        }

        return $this->success(true);
    }
}
