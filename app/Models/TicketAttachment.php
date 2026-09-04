<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Models\TicketAttachment
 *
 * @property int $id
 * @property int $user_id 上传者
 * @property int|null $ticket_id
 * @property int|null $ticket_message_id NULL = 已上传但尚未随消息发出
 * @property string $driver local|s3
 * @property string $path 存储 key
 * @property string $original_name
 * @property string $mime
 * @property int $size
 * @property bool $is_image
 * @property int|null $width
 * @property int|null $height
 * @property string $access_key 下载链接里的随机凭据
 * @property int $created_at
 * @property int $updated_at
 */
class TicketAttachment extends Model
{
    protected $table = 'v2_ticket_attachment';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'is_image' => 'boolean',
        'size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
    ];

    // 后台 ticket/fetch 直接 toArray() 下发，附上可用的下载地址
    protected $appends = ['download_path', 'download_url'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(TicketMessage::class, 'ticket_message_id', 'id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id', 'id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function isPending(): bool
    {
        return $this->ticket_message_id === null;
    }

    /**
     * 相对路径。前端优先用它自行拼接 origin（stealth 中间件下要改写成 /v2/<hex>/... 形态）。
     */
    public function downloadPath(): string
    {
        return "/api/v1/guest/ticket/attachment/{$this->id}/{$this->access_key}";
    }

    public function downloadUrl(): string
    {
        return url($this->downloadPath());
    }

    public function getDownloadPathAttribute(): string
    {
        return $this->downloadPath();
    }

    public function getDownloadUrlAttribute(): string
    {
        return $this->downloadUrl();
    }
}
