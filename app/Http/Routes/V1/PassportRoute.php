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
            $router->get('/auth/token2Login', [AuthController::class, 'token2Login']);
            $router->post('/auth/forget', [AuthController::class, 'forget'])->middleware('throttle:passport-forget');
            $router->post('/auth/getQuickLoginUrl', [AuthController::class, 'getQuickLoginUrl']);
            $router->post('/auth/loginWithMailLink', [AuthController::class, 'loginWithMailLink'])->middleware('throttle:passport-login');
            // Comm
            $router->post('/comm/sendEmailVerify', [CommController::class, 'sendEmailVerify'])->middleware('throttle:passport-email-verify');
            $router->post('/comm/pv', [CommController::class, 'pv']);
        });
    }
}
