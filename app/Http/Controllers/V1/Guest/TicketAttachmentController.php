<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\TicketAttachment;
use App\Services\TicketAttachmentService;

class TicketAttachmentController extends Controller
{
    /**
     * 附件下载。不走登录态：<img> 标签带不上 Bearer 头，改由 URL 里 128 位随机 access_key 做能力凭据，
     * 用户前端与后台共用同一条链接。只有已随消息发出的附件可下载，待绑定的不对外提供。
     */
    public function download(int $id, string $key)
    {
        $attachment = TicketAttachment::find($id);
        if (
            !$attachment
            || $attachment->ticket_message_id === null
            || !hash_equals((string) $attachment->access_key, $key)
        ) {
            abort(404);
        }

        return (new TicketAttachmentService())->downloadResponse($attachment);
    }
}
