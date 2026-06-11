<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * This namespace is applied to your controller routes.
     *
     * In addition, it is set as the URL generator's root namespace.
     *
     * @var string
     */
    protected $namespace = 'App\Http\Controllers';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        // HTTPS scheme is forced per-request via middleware (Octane-safe).
        parent::boot();
        $this->configureRateLimiters();
    }

    /**
     * Passport 接口限流。
     *
     * 注意：限流键优先用 email（攻击者知道账号但需要枚举密码/验证码），辅以 IP 兜底。
     * 部署 CDN 时务必正确配置 TrustProxies，否则所有请求都会聚到 CDN 出口 IP，触发全站限流。
     */
    protected function configureRateLimiters(): void
    {
        $byEmailOrIp = static function (Request $request, string $tag): string {
            $email = strtolower(trim((string) $request->input('email', '')));
            return $email !== '' ? "{$tag}:email:{$email}" : "{$tag}:ip:{$request->ip()}";
        };

        RateLimiter::for('passport-login', function (Request $request) use ($byEmailOrIp) {
            return [
                Limit::perMinute(10)->by($byEmailOrIp($request, 'login')),
                Limit::perMinute(60)->by('login:ip:' . $request->ip()),
            ];
        });

        RateLimiter::for('passport-register', function (Request $request) use ($byEmailOrIp) {
            return [
                Limit::perMinute(5)->by($byEmailOrIp($request, 'register')),
                Limit::perHour(20)->by('register:ip:' . $request->ip()),
            ];
        });

        RateLimiter::for('passport-forget', function (Request $request) use ($byEmailOrIp) {
            return [
                Limit::perMinute(3)->by($byEmailOrIp($request, 'forget')),
                Limit::perHour(10)->by('forget:ip:' . $request->ip()),
            ];
        });

        // Email 验证码：单邮箱已有 60 秒 cache 锁，这里再加 IP 维度反扫
        RateLimiter::for('passport-email-verify', function (Request $request) use ($byEmailOrIp) {
            return [
                Limit::perMinute(2)->by($byEmailOrIp($request, 'email_verify')),
                Limit::perHour(20)->by('email_verify:ip:' . $request->ip()),
            ];
        });

        // 价值型 bearer 接口（coupon / gift-card 的 check / redeem）：
        // 仅靠登录态保护时，单账号可高速枚举兑换码 / 优惠码。这里按「用户 + IP」双维度限流，
        // 阈值远高于正常使用，只拦截枚举类滥用。未取到用户时退化为 IP 维度（不会因 user 为空而漏限）。
        $byUserOrIp = static function (Request $request, string $tag): string {
            $uid = $request->user()?->id;
            return $uid ? "{$tag}:user:{$uid}" : "{$tag}:ip:{$request->ip()}";
        };

        RateLimiter::for('coupon-check', function (Request $request) use ($byUserOrIp) {
            return [
                Limit::perMinute(20)->by($byUserOrIp($request, 'coupon_check')),
                Limit::perMinute(60)->by('coupon_check:ip:' . $request->ip()),
            ];
        });

        RateLimiter::for('gift-card-check', function (Request $request) use ($byUserOrIp) {
            return [
                Limit::perMinute(10)->by($byUserOrIp($request, 'giftcard_check')),
                Limit::perMinute(30)->by('giftcard_check:ip:' . $request->ip()),
            ];
        });

        RateLimiter::for('gift-card-redeem', function (Request $request) use ($byUserOrIp) {
            return [
                Limit::perMinute(5)->by($byUserOrIp($request, 'giftcard_redeem')),
                Limit::perMinute(20)->by('giftcard_redeem:ip:' . $request->ip()),
            ];
        });

        // 邀请码写操作：自定义码开放后，无限流的 save 可被脚本批量抢注品牌词 /
        // 通过「already taken」报错枚举他站用户的自定义码。阈值远高于手动操作频率。
        RateLimiter::for('invite-save', function (Request $request) use ($byUserOrIp) {
            return [
                Limit::perMinute(10)->by($byUserOrIp($request, 'invite_save')),
                Limit::perMinute(30)->by('invite_save:ip:' . $request->ip()),
            ];
        });

        RateLimiter::for('invite-delete', function (Request $request) use ($byUserOrIp) {
            return [
                Limit::perMinute(10)->by($byUserOrIp($request, 'invite_delete')),
                Limit::perMinute(30)->by('invite_delete:ip:' . $request->ip()),
            ];
        });
    }

    /**
     * Define the routes for the application.
     *
     * @return void
     */
    public function map()
    {
        $this->mapApiRoutes();
        $this->mapWebRoutes();

        //
    }

    /**
     * Define the "web" routes for the application.
     *
     * These routes all receive session state, CSRF protection, etc.
     *
     * @return void
     */
    protected function mapWebRoutes()
    {
        Route::middleware('web')
            ->namespace($this->namespace)
            ->group(base_path('routes/web.php'));
    }

    /**
     * Define the "api" routes for the application.
     *
     * These routes are typically stateless.
     *
     * @return void
     */
    protected function mapApiRoutes()
    {
        Route::group([
            'prefix' => '/api/v1',
            'middleware' => 'api',
            'namespace' => $this->namespace
        ], function ($router) {
            foreach (glob(app_path('Http//Routes//V1') . '/*.php') as $file) {
                $this->app->make('App\\Http\\Routes\\V1\\' . basename($file, '.php'))->map($router);
            }
        });


        Route::group([
            'prefix' => '/api/v2',
            'middleware' => 'api',
            'namespace' => $this->namespace
        ], function ($router) {
            foreach (glob(app_path('Http//Routes//V2') . '/*.php') as $file) {
                $this->app->make('App\\Http\\Routes\\V2\\' . basename($file, '.php'))->map($router);
            }
        });
    }
}
