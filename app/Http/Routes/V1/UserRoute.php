<?php
namespace App\Http\Routes\V1;

use App\Http\Controllers\V1\User\CommController;
use App\Http\Controllers\V1\User\AdvanceCycleController;
use App\Http\Controllers\V1\User\CouponController;
use App\Http\Controllers\V1\User\GiftCardController;
use App\Http\Controllers\V1\User\InviteController;
use App\Http\Controllers\V1\User\KnowledgeController;
use App\Http\Controllers\V1\User\NoticeController;
use App\Http\Controllers\V1\User\OrderController;
use App\Http\Controllers\V1\User\PlanController;
use App\Http\Controllers\V1\User\ServerController;
use App\Http\Controllers\V1\User\StatController;
use App\Http\Controllers\V1\User\TelegramController;
use App\Http\Controllers\V1\User\TicketAttachmentController;
use App\Http\Controllers\V1\User\TicketController;
use App\Http\Controllers\V1\User\UserController;
use App\Http\Controllers\V1\User\WithdrawController;
use Illuminate\Contracts\Routing\Registrar;

class UserRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'user',
            'middleware' => 'user'
        ], function ($router) {
            // User
            $router->get('/resetSecurity', [UserController::class, 'resetSecurity']);
            $router->get('/info', [UserController::class, 'info']);
            $router->post('/changePassword', [UserController::class, 'changePassword']);
            $router->post('/update', [UserController::class, 'update']);
            $router->get('/getSubscribe', [UserController::class, 'getSubscribe']);
            $router->get('/getStat', [UserController::class, 'getStat']);
            $router->get('/checkLogin', [UserController::class, 'checkLogin']);
            $router->post('/transfer', [UserController::class, 'transfer']);
            $router->post('/getQuickLoginUrl', [UserController::class, 'getQuickLoginUrl']);
            $router->get('/getActiveSession', [UserController::class, 'getActiveSession']);
            $router->post('/removeActiveSession', [UserController::class, 'removeActiveSession']);
            $router->post('/logout', [UserController::class, 'logout']);
            $router->get('/traffic/advance-cycle/preview', [AdvanceCycleController::class, 'preview']);
            $router->post('/traffic/advance-cycle', [AdvanceCycleController::class, 'advance']);
            // Order
            $router->post('/order/save', [OrderController::class, 'save']);
            $router->post('/order/checkout', [OrderController::class, 'checkout']);
            $router->get('/order/check', [OrderController::class, 'check']);
            $router->get('/order/detail', [OrderController::class, 'detail']);
            $router->get('/order/fetch', [OrderController::class, 'fetch']);
            $router->get('/order/getPaymentMethod', [OrderController::class, 'getPaymentMethod']);
            $router->post('/order/cancel', [OrderController::class, 'cancel']);
            // Plan
            $router->get('/plan/fetch', [PlanController::class, 'fetch']);
            // Invite
            // GET save 保留给老前端（无参随机码）；POST save 支持自定义 code；同方法双路由
            $router->get('/invite/save', [InviteController::class, 'save'])
                ->middleware('throttle:invite-save');
            $router->post('/invite/save', [InviteController::class, 'save'])
                ->middleware('throttle:invite-save');
            $router->post('/invite/delete', [InviteController::class, 'delete'])
                ->middleware('throttle:invite-delete');
            $router->get('/invite/fetch', [InviteController::class, 'fetch']);
            $router->get('/invite/details', [InviteController::class, 'details']);
            $router->get('/invite/users', [InviteController::class, 'users']);
            // Notice
            $router->get('/notice/fetch', [NoticeController::class, 'fetch']);
            // Ticket
            $router->post('/ticket/reply', [TicketController::class, 'reply']);
            $router->post('/ticket/close', [TicketController::class, 'close']);
            $router->post('/ticket/save', [TicketController::class, 'save']);
            $router->get('/ticket/fetch', [TicketController::class, 'fetch']);
            $router->post('/ticket/withdraw', [TicketController::class, 'withdraw']);
            // Ticket attachment（先上传拿 id，再随 save / reply 的 attachment_ids 绑定）
            // 新增端点务必同步到 sufe-middleware-rs 的 pathname.rs 路径表，否则 stealth 模式下永久 404
            $router->post('/ticket/attachment/upload', [TicketAttachmentController::class, 'upload'])
                ->middleware('throttle:ticket-attachment-upload');
            $router->post('/ticket/attachment/delete', [TicketAttachmentController::class, 'delete']);
            // Commission withdraw（新工作流；/ticket/withdraw 保留给老前端并已转发到同一服务）
            // 同样需要登记进 sufe-middleware-rs 的 pathname.rs
            $router->get('/withdraw/config', [WithdrawController::class, 'config']);
            $router->get('/withdraw/fetch', [WithdrawController::class, 'fetch']);
            $router->post('/withdraw/apply', [WithdrawController::class, 'apply'])
                ->middleware('throttle:withdraw-apply');
            $router->post('/withdraw/cancel', [WithdrawController::class, 'cancel']);
            $router->post('/withdraw/saved/clear', [WithdrawController::class, 'clearSaved']);
            // Server
            $router->get('/server/fetch', [ServerController::class, 'fetch']);
            // Coupon
            $router->post('/coupon/check', [CouponController::class, 'check'])
                ->middleware('throttle:coupon-check');
            // Gift Card
            $router->post('/gift-card/check', [GiftCardController::class, 'check'])
                ->middleware('throttle:gift-card-check');
            $router->post('/gift-card/redeem', [GiftCardController::class, 'redeem'])
                ->middleware('throttle:gift-card-redeem');
            $router->get('/gift-card/history', [GiftCardController::class, 'history']);
            $router->get('/gift-card/detail', [GiftCardController::class, 'detail']);
            $router->get('/gift-card/types', [GiftCardController::class, 'types']);
            // Telegram
            $router->get('/telegram/getBotInfo', [TelegramController::class, 'getBotInfo']);
            // Comm
            $router->get('/comm/config', [CommController::class, 'config']);
            $router->Post('/comm/getStripePublicKey', [CommController::class, 'getStripePublicKey']);
            // Knowledge
            $router->get('/knowledge/fetch', [KnowledgeController::class, 'fetch']);
            $router->get('/knowledge/getCategory', [KnowledgeController::class, 'getCategory']);
            // Stat
            $router->get('/stat/getTrafficLog', [StatController::class, 'getTrafficLog']);
        });
    }
}
