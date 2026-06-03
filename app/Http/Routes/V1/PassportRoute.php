<?php
namespace App\Http\Routes\V1;

use App\Http\Controllers\V1\Passport\AuthController;
use App\Http\Controllers\V1\Passport\CommController;
use Illuminate\Contracts\Routing\Registrar;

class PassportRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'passport'
        ], function ($router) {
            // Auth：限流键以 email 优先，IP 兜底（前提是 TrustProxies 正确识别真实客户端 IP）
            $router->post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:passport-register');
            $router->post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:passport-login');
            $router->get('/auth/google/redirect', [AuthController::class, 'googleRedirect'])->middleware('throttle:passport-login');
            $router->get('/auth/google/callback', [AuthController::class, 'googleCallback'])->middleware('throttle:passport-login');
            // token2Login 是邮件链接 / Quick Login 的兑换入口，verify 码是短期 60s TTL，但原本完全无限流，
            // 攻击者可对一个目标用户高速猜 verify 值；命中即取得长期 Sanctum token。
            // 复用 passport-login 限流（同 login / mail link 的预算），避免暴破。
            $router->get('/auth/token2Login', [AuthController::class, 'token2Login'])->middleware('throttle:passport-login');
            $router->post('/auth/forget', [AuthController::class, 'forget'])->middleware('throttle:passport-forget');
            // getQuickLoginUrl 是把当前会话换成"60s 内可在第三方设备登录的 URL"，发送频次不该无上限
            $router->post('/auth/getQuickLoginUrl', [AuthController::class, 'getQuickLoginUrl'])->middleware('throttle:passport-login');
            $router->post('/auth/loginWithMailLink', [AuthController::class, 'loginWithMailLink'])->middleware('throttle:passport-login');
            // Comm
            $router->post('/comm/sendEmailVerify', [CommController::class, 'sendEmailVerify'])->middleware('throttle:passport-email-verify');
            // pv 是邀请码访问量统计的匿名 UPDATE 入口，无限流时会被任意刷
            $router->post('/comm/pv', [CommController::class, 'pv'])->middleware('throttle:60,1');
        });
    }
}
