<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketAttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this['id'],
            'name' => $this['original_name'],
            'size' => (int) $this['size'],
            'mime' => $this['mime'],
            'is_image' => (bool) $this['is_image'],
            'width' => $this['width'],
            'height' => $this['height'],
            // path 供前端自行拼 origin（stealth 中间件需改写成混淆形态），url 为后端按 app_url 拼好的绝对地址
            'path' => $this->resource->downloadPath(),
            'url' => $this->resource->downloadUrl(),
            'created_at' => $this['created_at'],
        ];
    }
}
