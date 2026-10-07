<?php
namespace App\Http\Routes\V1;

use App\Http\Controllers\V1\Guest\CommController;
use App\Http\Controllers\V1\Guest\PaymentController;
use App\Http\Controllers\V1\Guest\PlanController;
use App\Http\Controllers\V1\Guest\TelegramController;
use App\Http\Controllers\V1\Guest\TicketAttachmentController;
use App\Http\Controllers\V1\Guest\BillingDocumentController;
use App\Http\Controllers\V1\Guest\BillingPayController;
use App\Http\Controllers\V1\Guest\NotificationController;
use Illuminate\Contracts\Routing\Registrar;

class GuestRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'guest'
        ], function ($router) {
            // Plan
            $router->get('/plan/fetch', [PlanController::class, 'fetch']);
            $router->post('/plan/quote', [PlanController::class, 'quote'])
                ->middleware('throttle:plan-quote');
            // Telegram
            $router->post('/telegram/webhook', [TelegramController::class, 'webhook']);
            // Payment
            $router->match(['get', 'post'], '/payment/notify/{method}/{uuid}', [PaymentController::class, 'notify']);
            // Comm
            $router->get('/comm/config', [CommController::class, 'config']);
            // Ticket attachment 下载：<img> 带不上 Bearer，用 URL 里的随机 access_key 做凭据
            $router->get('/ticket/attachment/{id}/{key}', [TicketAttachmentController::class, 'download'])
                ->where(['id' => '[0-9]+', 'key' => '[a-f0-9]{32}'])
                ->middleware('throttle:ticket-attachment-download');
            // 收据 / 账单 PDF 下载：凭据是 URL 里带过期时间的签名（8 位过期时间 + 32 位签名）
            $router->get('/billing/document/{id}/{key}', [BillingDocumentController::class, 'download'])
                ->where(['id' => '[0-9]+', 'key' => '[a-f0-9]{40}'])
                ->middleware('throttle:billing-document-download');
            // 续费账单的免登录付款：凭据（账单 id + 签名）在请求体里，由 BillingPayService 验签；
            // 查状态的两条给付款页轮询用，下单 / 取消要碰网关和余额，限流更紧
            $router->post('/billing/pay/fetch', [BillingPayController::class, 'fetch'])
                ->middleware('throttle:billing-pay');
            $router->post('/billing/pay/check', [BillingPayController::class, 'check'])
                ->middleware('throttle:billing-pay');
            $router->post('/billing/pay/checkout', [BillingPayController::class, 'checkout'])
                ->middleware('throttle:billing-pay-action');
            $router->post('/billing/pay/cancel', [BillingPayController::class, 'cancel'])
                ->middleware('throttle:billing-pay-action');
            // 邮件页脚「管理通知偏好」打开的免登录偏好页：凭据在请求体里，走加密通道（登记进中间件路径表）
            $router->post('/notify/fetch', [NotificationController::class, 'fetch'])
                ->middleware('throttle:notify-pref');
            $router->post('/notify/update', [NotificationController::class, 'update'])
                ->middleware('throttle:notify-pref-action');
            // List-Unsubscribe 头的落点：邮件客户端直接 POST（一键退订）或 GET（跳偏好页）。
            // 明文到达，中间件把这条前缀当支付回调一样直通（route.rs 的内置透传规则）
            $router->match(['get', 'post'], '/notify/unsubscribe/{key}/{category}', [NotificationController::class, 'unsubscribe'])
                ->where(['key' => '[a-f0-9]{32}', 'category' => '[a-z_]{1,32}'])
                ->middleware('throttle:notify-pref-action');
        });
    }
}
