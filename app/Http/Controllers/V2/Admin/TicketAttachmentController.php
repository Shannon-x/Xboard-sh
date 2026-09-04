<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\TicketAttachmentResource;
use App\Models\TicketAttachment;
use App\Services\TicketAttachmentService;
use Illuminate\Http\Request;

class TicketAttachmentController extends Controller
{
    /**
     * 管理员回复时上传附件：不受用户每日额度限制，体积 / 类型限制照旧。
     */
    public function upload(Request $request)
    {
        $service = new TicketAttachmentService();
        if (!$service->config()->enable) {
            return $this->fail([400, '工单附件功能未开启']);
        }

        if ($request->hasFile('file')) {
            $attachment = $service->storeUploadedFile($request->file('file'), $request->user(), true);
        } else {
            $request->validate([
                'name' => 'required|string|max:255',
                'content' => 'required|string',
            ]);
            $attachment = $service->storeBase64(
                (string) $request->input('name'),
                (string) $request->input('content'),
                $request->user(),
                true
            );
        }

        return $this->success(TicketAttachmentResource::make($attachment));
    }

    /**
     * 删除任意附件（含用户已发出的，用于处理违规内容）。
     */
    public function delete(Request $request)
    {
        $request->validate(['id' => 'required|integer|min:1']);
        $attachment = TicketAttachment::find((int) $request->input('id'));
        if (!$attachment) {
            return $this->fail([400202, '附件不存在']);
        }
        (new TicketAttachmentService())->forceDelete($attachment);
        return $this->success(true);
    }
}
