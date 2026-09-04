<?php
namespace App\Http\Routes\V1;

use App\Http\Controllers\V1\Guest\CommController;
use App\Http\Controllers\V1\Guest\PaymentController;
use App\Http\Controllers\V1\Guest\PlanController;
use App\Http\Controllers\V1\Guest\TelegramController;
use App\Http\Controllers\V1\Guest\TicketAttachmentController;
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
        });
    }
}
