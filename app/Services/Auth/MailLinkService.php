<?php

namespace App\Services\Auth;

use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;

class MailLinkService
{
    /**
     * 处理邮件链接登录逻辑
     *
     * @param string $email 用户邮箱
     * @param string|null $redirect 重定向地址
     * @return array 返回处理结果
     */
    public function handleMailLink(string $email, ?string $redirect = null): array
    {
        $email = strtolower(trim((string) $email));

        if (!(int) admin_setting('login_with_mail_link_enable')) {
            return [false, [404, null]];
        }

        if (Cache::get(CacheKey::get('LAST_SEND_LOGIN_WITH_MAIL_LINK_TIMESTAMP', $email))) {
            return [false, [429, __('Sending frequently, please try again later')]];
        }

        $user = User::byEmail($email)->first();
        if (!$user) {
            return [true, true]; // 成功但用户不存在，保护用户隐私
        }

        $code = Helper::guid();
        $key = CacheKey::get('TEMP_TOKEN', $code);
        Cache::put($key, $user->id, 300);
        Cache::put(CacheKey::get('LAST_SEND_LOGIN_WITH_MAIL_LINK_TIMESTAMP', $email), time(), 60);

        // 防 open redirect：redirect 只允许"相对路径"形式（例如 dashboard / orders）
        // 任何带 scheme / 双斜杠开头 / 反斜杠的都被丢弃，避免邮件链接被改写后跳到攻击者域名
        $safeRedirect = self::sanitizeRedirect($redirect);

        $redirectUrl = '/#/login?verify=' . rawurlencode($code)
            . '&redirect=' . rawurlencode($safeRedirect);
        if (admin_setting('app_url')) {
            $link = admin_setting('app_url') . $redirectUrl;
        } else {
            $link = url($redirectUrl);
        }

        $this->sendMailLinkEmail($user, $link);

        return [true, true];
    }

    /**
     * 发送邮件链接登录邮件
     *
     * @param User $user 用户对象
     * @param string $link 登录链接
     * @return void
     */
    private function sendMailLinkEmail(User $user, string $link): void
    {
        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => __('Login to :name', [
                'name' => admin_setting('app_name', 'XBoard')
            ]),
            'template_name' => 'login',
            'template_value' => [
                'name' => admin_setting('app_name', 'XBoard'),
                'link' => $link,
                'url' => admin_setting('app_url')
            ]
        ]);
    }

    /**
     * 把任意用户传入的 redirect 收敛到"安全的相对路径"。
     * 拒绝：scheme（http: / https: / javascript: / data: ...）、//host、反斜杠。
     * 拒绝时回退到 'dashboard'，对前端约定的"登录后默认页"无破坏。
     */
    private static function sanitizeRedirect(?string $redirect): string
    {
        $fallback = 'dashboard';
        if ($redirect === null) {
            return $fallback;
        }
        $r = trim((string) $redirect);
        if ($r === '' || strlen($r) > 255) {
            return $fallback;
        }
        // protocol-relative or scheme: //example.com  http://...  javascript:  data:
        if (str_starts_with($r, '//') || str_starts_with($r, '\\') || preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*:#', $r)) {
            return $fallback;
        }
        // 只允许 [a-zA-Z0-9_\-/?#&=.] 的子集，避免 URL 拼接出 newline / control / NUL
        if (preg_match('/[\x00-\x1f\x7f]/', $r)) {
            return $fallback;
        }
        return $r;
    }

    /**
     * 处理Token登录
     *
     * @param string $token 登录令牌
     * @return int|null 用户ID或null
     */
    public function handleTokenLogin(string $token): ?int
    {
        $key = CacheKey::get('TEMP_TOKEN', $token);
        $userId = Cache::get($key);

        if (!$userId) {
            return null;
        }

        $user = User::find($userId);

        if (!$user || $user->banned) {
            return null;
        }

        Cache::forget($key);

        return $userId;
    }
}
