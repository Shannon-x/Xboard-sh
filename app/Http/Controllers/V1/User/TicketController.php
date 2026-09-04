<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\TicketSave;
use App\Http\Requests\User\TicketWithdraw;
use App\Http\Resources\TicketResource;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Commission\WithdrawalConfig;
use App\Services\CommissionWithdrawalService;
use App\Services\TicketAttachmentService;
use App\Services\TicketService;
use App\Utils\Dict;
use Illuminate\Http\Request;
use App\Services\Plugin\HookManager;
use Illuminate\Support\Facades\Log;

class TicketController extends Controller
{
    public function fetch(Request $request)
    {
        if ($request->input('id')) {
            $ticket = Ticket::where('id', $request->input('id'))
                ->where('user_id', $request->user()->id)
                ->first()
                ->load('message');
            if (!$ticket) {
                return $this->fail([400, __('Ticket does not exist')]);
            }
            $ticket['message'] = TicketMessage::where('ticket_id', $ticket->id)->with('attachments')->get();
            $ticket['message']->each(function ($message) use ($ticket) {
                $message['is_me'] = ($message['user_id'] == $ticket->user_id);
            });
            return $this->success(TicketResource::make($ticket)->additional(['message' => true]));
        }
        $ticket = Ticket::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'DESC')
            ->get();
        return $this->success(TicketResource::collection($ticket));
    }

    public function save(TicketSave $request)
    {
        if ((int) admin_setting('ticket_active_subscription_required', 0) && !$this->canOpenTicket($request)) {
            return $this->fail([400, __('Please purchase a subscription, earn affiliate commission, or place an order before opening a ticket')]);
        }

        // 附件是先上传后绑定的：这里只校验归属与数量，真正绑定在创建消息的事务里完成
        $attachmentIds = (new TicketAttachmentService())->validatePendingIds(
            $request->input('attachment_ids', []),
            $request->user()->id
        );

        $ticketService = new TicketService();
        $ticket = $ticketService->createTicket(
            $request->user()->id,
            $request->input('subject'),
            $request->input('level'),
            $request->input('message'),
            $attachmentIds
        );
        HookManager::call('ticket.create.after', $ticket);
        return $this->success(true);

    }

    public function reply(Request $request)
    {
        if (empty($request->input('id'))) {
            return $this->fail([400, __('Invalid parameter')]);
        }
        $attachmentIds = (new TicketAttachmentService())->validatePendingIds(
            $request->input('attachment_ids', []),
            $request->user()->id
        );
        // 带附件时允许正文为空（只发一张截图是最常见的用法）
        $message = is_string($request->input('message')) ? trim($request->input('message')) : '';
        if ($message === '' && empty($attachmentIds)) {
            return $this->fail([400, __('Message cannot be empty')]);
        }
        if (mb_strlen($message) > 10000) {
            return $this->fail([400, __('Invalid parameter')]);
        }
        $ticket = Ticket::where('id', $request->input('id'))
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$ticket) {
            return $this->fail([400, __('Ticket does not exist')]);
        }
        if ($ticket->status) {
            return $this->fail([400, __('The ticket is closed and cannot be replied')]);
        }
        if ((int) admin_setting('ticket_must_wait_reply', 0) && $request->user()->id == $this->getLastMessage($ticket->id)->user_id) {
            return $this->fail(codeResponse: [400, __('Please wait for the technical enginneer to reply')]);
        }
        $ticketService = new TicketService();
        if (
            !$ticketService->reply(
                $ticket,
                $message,
                $request->user()->id,
                $attachmentIds
            )
        ) {
            return $this->fail([400, __('Ticket reply failed')]);
        }
        HookManager::call('ticket.reply.user.after', $ticket);
        return $this->success(true);
    }


    public function close(Request $request)
    {
        if (empty($request->input('id'))) {
            return $this->fail([422, __('Invalid parameter')]);
        }
        $ticket = Ticket::where('id', $request->input('id'))
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$ticket) {
            return $this->fail([400, __('Ticket does not exist')]);
        }
        $ticket->status = Ticket::STATUS_CLOSED;
        if (!$ticket->save()) {
            return $this->fail([500, __('Close failed')]);
        }
        return $this->success(true);
    }

    private function getLastMessage($ticketId)
    {
        return TicketMessage::where('ticket_id', $ticketId)
            ->orderBy('id', 'DESC')
            ->first();
    }

    private function canOpenTicket(Request $request): bool
    {
        $user = $request->user();

        if ($user->isActive()) {
            return true;
        }

        if ((int) ($user->balance ?? 0) > 0 || (int) ($user->commission_balance ?? 0) > 0) {
            return true;
        }

        return Order::query()
            ->where('user_id', $user->id)
            ->exists();
    }

    public function withdraw(TicketWithdraw $request)
    {
        if ((int) admin_setting('withdraw_close_enable', 0)) {
            return $this->fail([400, 'Unsupported withdraw']);
        }
        if (
            !in_array(
                $request->input('withdraw_method'),
                admin_setting('commission_withdraw_method', Dict::WITHDRAW_METHOD_WHITELIST_DEFAULT)
            )
        ) {
            return $this->fail([422, __('Unsupported withdrawal method')]);
        }
        $user = User::find($request->user()->id);
        $limit = admin_setting('commission_withdraw_limit', 100);
        if ($limit > ($user->commission_balance / 100)) {
            return $this->fail([422, __('The current required minimum withdrawal commission is :limit', ['limit' => $limit])]);
        }

        // 老前端的接口：只给「方式 + 账号」，按全部余额走新的提现工作流（申请即冻结、自动开工单、后台结算）。
        // 方式若能对上后台配置的链就用那条链的地址校验，否则合成一条不校验格式的链。
        $service = new CommissionWithdrawalService();
        $method = (string) $request->input('withdraw_method');
        $chain = $service->config()->findChain(WithdrawalConfig::slug($method))
            ?? $service->config()->findChain(WithdrawalConfig::slug('usdt_' . $method))
            ?? [
                'code' => WithdrawalConfig::slug($method) ?: 'legacy',
                'name' => mb_substr($method, 0, 64),
                'network' => '',
                'preset' => 'none',
                'explorer_tx' => '',
                'hint' => '',
                'pattern' => null,
            ];
        $service->apply(
            $user,
            (int) $user->commission_balance,
            $chain,
            (string) $request->input('withdraw_account')
        );
        return $this->success(true);
    }
}
