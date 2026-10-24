<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\TicketAttachmentResource;
use App\Models\Ticket;
use App\Services\TicketAttachmentService;
use App\Services\TicketService;
use Illuminate\Http\Request;

class TicketAttachmentController extends Controller
{
    /**
     * 上传一个待绑定附件。两种形态：
     *   - multipart：字段 file
     *   - JSON：{ name, content(base64) } —— stealth 加密通道只能承载 JSON
     */
    public function upload(Request $request)
    {
        $service = new TicketAttachmentService();
        if (!$service->config()->enable) {
            return $this->fail([400, __('Ticket attachments are disabled')]);
        }

        // 与开工单同一道门槛：否则开不了工单的小号照样能往存储里塞文件（2026-10-23 有人借此批量传 PHP / HTML 探针）。
        // 已有未关闭工单的照常放行——那是在给自己的工单补截图，门槛在开单时已经过了
        $user = $request->user();
        if (
            (int) admin_setting('ticket_active_subscription_required', 0)
            && !Ticket::where('user_id', $user->id)->where('status', Ticket::STATUS_OPENING)->exists()
            && !(new TicketService())->canOpenTicket($user)
        ) {
            return $this->fail([400, __('Please purchase a subscription, earn affiliate commission, or place an order before opening a ticket')]);
        }

        if ($request->hasFile('file')) {
            $attachment = $service->storeUploadedFile($request->file('file'), $user);
        } else {
            $request->validate([
                'name' => 'required|string|max:255',
                'content' => 'required|string',
            ]);
            $attachment = $service->storeBase64(
                (string) $request->input('name'),
                (string) $request->input('content'),
                $user
            );
        }

        return $this->success(TicketAttachmentResource::make($attachment));
    }

    /**
     * 撤回一个尚未随消息发出的附件。
     */
    public function delete(Request $request)
    {
        $request->validate(['id' => 'required|integer|min:1']);
        (new TicketAttachmentService())->deletePending((int) $request->input('id'), $request->user()->id);
        return $this->success(true);
    }
}
