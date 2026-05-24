<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\UserService;
use App\Utils\CacheKey;
use App\Utils\Dict;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class GoogleLoginService
{
    private const STATE_CACHE_PREFIX = 'GOOGLE_OAUTH_STATE:';
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const USERINFO_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';

    public function createRedirectUrl(Request $request): array
    {
        if (!(int) admin_setting('google_login_enable', 0)) {
            return [false, [400, __('Google login is not enabled')]];
        }

        $clientId = trim((string) admin_setting('google_client_id', ''));
        $clientSecret = trim((string) admin_setting('google_client_secret', ''));

        if ($clientId === '' || $clientSecret === '') {
            return [false, [400, __('Google login is not configured')]];
        }

        $state = Str::random(40);
        Cache::put($this->stateCacheKey($state), [
            'redirect' => $this->sanitizeRedirect($request->query('redirect')),
            'invite_code' => $this->sanitizeInviteCode($request->query('invite_code')),
            'callback_uri' => $this->callbackUri($request),
        ], now()->addMinutes(10));

        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $this->callbackUri($request),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ]);

        return [true, self::AUTH_URL . '?' . $query];
    }

    public function handleCallback(Request $request): string
    {
        if (!(int) admin_setting('google_login_enable', 0)) {
            return $this->frontendLoginUrl(['error' => __('Google login is not enabled')]);
        }

        $code = trim((string) $request->query('code', ''));
        $state = trim((string) $request->query('state', ''));

        if ($code === '' || $state === '') {
            return $this->frontendLoginUrl(['error' => __('Invalid request')]);
        }

        $stateData = Cache::pull($this->stateCacheKey($state));
        if (!is_array($stateData)) {
            return $this->frontendLoginUrl(['error' => __('Invalid request')]);
        }

        $redirect = $this->sanitizeRedirect($stateData['redirect'] ?? null);
        $inviteCode = $this->sanitizeInviteCode($stateData['invite_code'] ?? null);
        $callbackUri = trim((string) ($stateData['callback_uri'] ?? $this->callbackUri($request)));

        [$tokenSuccess, $tokenResult] = $this->exchangeCodeForToken($code, $callbackUri);
        if (!$tokenSuccess) {
            return $this->frontendLoginUrl(['error' => $tokenResult], $redirect);
        }

        [$profileSuccess, $profileResult] = $this->fetchGoogleProfile($tokenResult['access_token'] ?? '');
        if (!$profileSuccess) {
            return $this->frontendLoginUrl(['error' => $profileResult], $redirect);
        }

        [$userSuccess, $userResult] = $this->resolveUser($profileResult, $request, $inviteCode);
        if (!$userSuccess) {
            $message = is_array($userResult) ? (string) ($userResult[1] ?? __('Login failed')) : (string) $userResult;
            return $this->frontendLoginUrl(['error' => $message], $redirect);
        }

        $loginUrl = app(LoginService::class)->generateQuickLoginUrl($userResult, $redirect);
        if (!$loginUrl) {
            return $this->frontendLoginUrl(['error' => __('Login failed')], $redirect);
        }

        return $loginUrl;
    }

    public function callbackUri(Request $request): string
    {
        $configured = trim((string) admin_setting('google_redirect_uri', ''));
        if ($configured !== '') {
            return $configured;
        }

        $apiVersion = Str::contains($request->path(), 'api/v2/') ? 'v2' : 'v1';
        $baseUrl = rtrim((string) admin_setting('app_url', ''), '/');

        if ($baseUrl === '') {
            $baseUrl = rtrim($request->getSchemeAndHttpHost(), '/');
        }

        return $baseUrl . "/api/{$apiVersion}/passport/auth/google/callback";
    }

    private function exchangeCodeForToken(string $code, string $callbackUri): array
    {
        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post(self::TOKEN_URL, [
                    'client_id' => trim((string) admin_setting('google_client_id', '')),
                    'client_secret' => trim((string) admin_setting('google_client_secret', '')),
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $callbackUri,
                ]);
        } catch (Throwable) {
            return [false, __('Google authorization failed')];
        }

        if (!$response->successful()) {
            return [false, __('Google authorization failed')];
        }

        $payload = $response->json();
        if (!is_array($payload) || empty($payload['access_token'])) {
            return [false, __('Google authorization failed')];
        }

        return [true, $payload];
    }

    private function fetchGoogleProfile(string $accessToken): array
    {
        if ($accessToken === '') {
            return [false, __('Google authorization failed')];
        }

        try {
            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->get(self::USERINFO_URL);
        } catch (Throwable) {
            return [false, __('Google authorization failed')];
        }

        if (!$response->successful()) {
            return [false, __('Google authorization failed')];
        }

        $profile = $response->json();
        if (!is_array($profile)) {
            return [false, __('Google authorization failed')];
        }

        $email = strtolower(trim((string) ($profile['email'] ?? '')));
        if (
            $email === ''
            || strlen($email) > 64
            || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || !filter_var($profile['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)
        ) {
            return [false, __('Google account email is not verified')];
        }

        return [true, [
            'email' => $email,
            'sub' => (string) ($profile['sub'] ?? ''),
            'name' => (string) ($profile['name'] ?? ''),
        ]];
    }

    private function resolveUser(array $profile, Request $request, ?string $inviteCode): array
    {
        $email = strtolower(trim((string) ($profile['email'] ?? '')));
        $user = User::byEmail($email)->first();

        if ($user) {
            if ($user->banned) {
                return [false, [400, __('Your account has been suspended')]];
            }

            $user->last_login_at = time();
            $user->save();

            HookManager::call('user.login.after', $user);

            return [true, $user];
        }

        [$valid, $error] = $this->validateRegistration($email, $request, $inviteCode);
        if (!$valid) {
            return [false, $error];
        }

        HookManager::call('user.register.before', $request);

        $inviteUserId = null;
        if ($inviteCode) {
            $inviteUserId = app(RegisterService::class)->handleInviteCode($inviteCode);
        }

        $user = app(UserService::class)->createUser([
            'email' => $email,
            'password' => Helper::guid(),
            'invite_user_id' => $inviteUserId,
        ]);

        if (!$user->save()) {
            return [false, [500, __('Register failed')]];
        }

        $user->last_login_at = time();
        $user->save();

        HookManager::call('user.register.after', $user);

        if ((int) admin_setting('register_limit_by_ip_enable', 0)) {
            $cacheKey = CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip());
            $registerCountByIP = Cache::get($cacheKey) ?? 0;
            Cache::put(
                $cacheKey,
                (int) $registerCountByIP + 1,
                (int) admin_setting('register_limit_expire', 60) * 60
            );
        }

        return [true, $user];
    }

    private function validateRegistration(string $email, Request $request, ?string $inviteCode): array
    {
        if ((int) admin_setting('register_limit_by_ip_enable', 0)) {
            $registerCountByIP = Cache::get(CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip())) ?? 0;
            if ((int) $registerCountByIP >= (int) admin_setting('register_limit_count', 3)) {
                return [
                    false,
                    [
                        429,
                        __('Register frequently, please try again after :minute minute', [
                            'minute' => admin_setting('register_limit_expire', 60),
                        ]),
                    ],
                ];
            }
        }

        if ((int) admin_setting('email_whitelist_enable', 0)) {
            if (!Helper::emailSuffixVerify($email, admin_setting('email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT))) {
                return [false, [400, __('Email suffix is not in the Whitelist')]];
            }
        }

        if ((int) admin_setting('email_gmail_limit_enable', 0)) {
            $prefix = explode('@', $email)[0];
            if (strpos($prefix, '.') !== false || strpos($prefix, '+') !== false) {
                return [false, [400, __('Gmail alias is not supported')]];
            }
        }

        if ((int) admin_setting('stop_register', 0)) {
            return [false, [400, __('Registration has closed')]];
        }

        if ((int) admin_setting('invite_force', 0) && !$inviteCode) {
            return [false, [422, __('You must use the invitation code to register')]];
        }

        return [true, null];
    }

    private function frontendLoginUrl(array $params = [], ?string $redirect = null): string
    {
        if ($redirect !== null && $redirect !== '') {
            $params['redirect'] = $redirect;
        }

        $baseUrl = rtrim((string) admin_setting('app_url', ''), '/');
        $path = '/#/login';

        if (!$params) {
            return $baseUrl !== '' ? $baseUrl . $path : url($path);
        }

        $query = http_build_query($params);
        return $baseUrl !== '' ? $baseUrl . $path . '?' . $query : url($path . '?' . $query);
    }

    private function stateCacheKey(string $state): string
    {
        return self::STATE_CACHE_PREFIX . $state;
    }

    private function sanitizeRedirect(mixed $redirect): ?string
    {
        $value = trim((string) $redirect);
        if ($value === '' || strlen($value) > 255) {
            return null;
        }

        return $value;
    }

    private function sanitizeInviteCode(mixed $inviteCode): ?string
    {
        $value = trim((string) $inviteCode);
        if ($value === '' || strlen($value) > 64) {
            return null;
        }

        return $value;
    }
}
