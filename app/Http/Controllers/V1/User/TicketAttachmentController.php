<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\TicketAttachmentResource;
use App\Services\TicketAttachmentService;
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

        if ($request->hasFile('file')) {
            $attachment = $service->storeUploadedFile($request->file('file'), $request->user());
        } else {
            $request->validate([
                'name' => 'required|string|max:255',
                'content' => 'required|string',
            ]);
            $attachment = $service->storeBase64(
                (string) $request->input('name'),
                (string) $request->input('content'),
                $request->user()
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
