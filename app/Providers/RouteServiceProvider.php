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

        // 自选套餐报价：下单前的纯只读计算，且 guest 端无鉴权。每次调用都要取套餐、
        // 跑完整的规格校验再逐项计价，是有 DB 读 + 计算成本的 POST，不限流时任何人
        // 都能无限调用。但它返回的只是本就公开的定价，没有 coupon / gift-card 那种
        // 枚举价值，所以这里防的是资源耗尽而非枚举，阈值取得比那两个宽。
        // 前端对规格变更做了 250ms 防抖，拖动滑块的中间态不会发请求，认真配置一轮
        // 也远到不了每分钟 40 次。
        //
        // 兜底键刻意用 `plan_quote_ip:` 而不是 `plan_quote:ip:`：未登录时
        // $byUserOrIp 本身就会退化成 `plan_quote:ip:<ip>`，若兜底沿用同一前缀，
        // 两条 Limit 会落在**同一个 key** 上被重复计数（coupon-check 那组是 bearer
        // 接口、不存在未登录调用才没暴露这个问题，此处不能照抄）。
        RateLimiter::for('plan-quote', function (Request $request) use ($byUserOrIp) {
            return [
                Limit::perMinute(40)->by($byUserOrIp($request, 'plan_quote')),
                Limit::perMinute(120)->by('plan_quote_ip:' . $request->ip()),
            ];
        });

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

        // 工单附件上传：体积 / 每日额度在业务层另有限制，这里只拦脚本式高频写入。
        // 剪贴板连续粘贴几张截图也远到不了每分钟 20 次。
        RateLimiter::for('ticket-attachment-upload', function (Request $request) use ($byUserOrIp) {
            return [
                Limit::perMinute(20)->by($byUserOrIp($request, 'ticket_attachment_upload')),
                Limit::perMinute(60)->by('ticket_attachment_upload:ip:' . $request->ip()),
            ];
        });

        // 附件下载是免登录的能力 URL（128 位随机 key），按 IP 限流防枚举扫描；
        // 一个工单详情页几十张图的正常加载不会触碰这个阈值。
        // 提现申请：每人同时只能有一笔待处理，限流只防脚本刷提交
        RateLimiter::for('withdraw-apply', function (Request $request) use ($byUserOrIp) {
            return [
                Limit::perMinute(5)->by($byUserOrIp($request, 'withdraw_apply')),
                Limit::perMinute(20)->by('withdraw_apply:ip:' . $request->ip()),
            ];
        });

        RateLimiter::for('ticket-attachment-download', function (Request $request) {
            return Limit::perMinute(240)->by('ticket_attachment_download:ip:' . $request->ip());
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
