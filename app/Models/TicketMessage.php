<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * App\Models\TicketMessage
 *
 * @property int $id
 * @property int $ticket_id
 * @property int $user_id
 * @property string $message
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read \App\Models\Ticket $ticket 关联的工单
 * @property-read bool $is_from_user 消息是否由工单发起人发送
 * @property-read bool $is_from_admin 消息是否由管理员发送
 * @property-read \Illuminate\Database\Eloquent\Collection<int, TicketAttachment> $attachments 随消息发出的附件
 */
class TicketMessage extends Model
{
    protected $table = 'v2_ticket_message';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];

    protected $appends = ['is_from_user', 'is_from_admin'];

    /**
     * 关联的工单
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id', 'id');
    }

    /**
     * 随消息发出的附件
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class, 'ticket_message_id', 'id');
    }

    /**
     * 判断消息是否由工单发起人发送
     *
     * 工单行已不存在时（早年删用户只删工单、没清消息，库里留下了孤儿消息）ticket 为 null，
     * 两个判断都返回 false：认不出是谁发的，但序列化不能因此抛错。
     */
    public function getIsFromUserAttribute(): bool
    {
        $ticket = $this->ticket;
        return $ticket !== null && $ticket->user_id === $this->user_id;
    }

    /**
     * 判断消息是否由管理员发送
     */
    public function getIsFromAdminAttribute(): bool
    {
        $ticket = $this->ticket;
        return $ticket !== null && $ticket->user_id !== $this->user_id;
    }
}
