<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\CommSendEmailVerify;
use App\Jobs\SendEmailJob;
use App\Models\InviteCode;
use App\Models\User;
use App\Services\CaptchaService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CommController extends Controller
{

    public function sendEmailVerify(CommSendEmailVerify $request)
    {
        // 验证人机验证码
        $captchaService = app(CaptchaService::class);
        [$captchaValid, $captchaError] = $captchaService->verify($request);
        if (!$captchaValid) {
            return $this->fail($captchaError);
        }

        $email = strtolower(trim((string) $request->input('email')));

        // 检查白名单后缀限制
        if ((int) admin_setting('email_whitelist_enable', 0)) {
            $isRegisteredEmail = User::byEmail($email)->exists();
            if (!$isRegisteredEmail) {
                $allowedSuffixes = Helper::getEmailSuffix();
                $emailSuffix = strtolower((string) substr(strrchr($email, '@'), 1));
                $allowedSuffixes = array_map('strtolower', $allowedSuffixes);

                if (!in_array($emailSuffix, $allowedSuffixes)) {
                    return $this->fail([400, __('Email suffix is not in whitelist')]);
                }
            }
        }

        if (Cache::get(CacheKey::get('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', $email))) {
            return $this->fail([400, __('Email verification code has been sent, please request again later')]);
        }
        // 使用 random_int（CSPRNG）而非 rand()，避免可预测序列被枚举命中
        $code = random_int(100000, 999999);
        $subject = admin_setting('app_name', 'XBoard') . __('Email verification code');

        SendEmailJob::dispatch([
            'email' => $email,
            'subject' => $subject,
            'template_name' => 'verify',
            'template_value' => [
                'name' => admin_setting('app_name', 'XBoard'),
                'code' => $code,
                'url' => admin_setting('app_url')
            ]
        ]);

        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $email), $code, 300);
        Cache::put(CacheKey::get('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', $email), time(), 60);
        return $this->success(true);
    }

    public function pv(Request $request)
    {
        // 仅对活跃码（status=0）累加 pv：
        //   1) 与注册消费、fetch 展示口径一致（都只认 status=0）；
        //   2) 规避对 status=2 墓碑行做 model save —— InviteCode.status 有 boolean cast，
        //      整模型 save 会把 raw 2 写回 1，破坏「重建同名码恢复」依赖的 getRawOriginal('status')===2。
        //      用条件 increment（不走 model 的 status cast）双重保险，绝不触碰 status 列。
        InviteCode::where('code', $request->input('invite_code'))
            ->where('status', InviteCode::STATUS_UNUSED)
            ->increment('pv');

        return $this->success(true);
    }

}
