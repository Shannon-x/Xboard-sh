<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * App\Models\Ticket
 *
 * @property int $id
 * @property int $user_id 用户ID
 * @property string $subject 工单主题
 * @property string|null $level 工单等级
 * @property int $status 工单状态
 * @property int|null $reply_status 回复状态
 * @property int|null $last_reply_user_id 最后回复人
 * @property int $created_at
 * @property int $updated_at
 * 
 * @property-read User $user 关联的用户
 * @property-read \Illuminate\Database\Eloquent\Collection<int, TicketMessage> $messages 关联的工单消息
 */
class Ticket extends Model
{
    protected $table = 'v2_ticket';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];

    const STATUS_OPENING = 0;
    const STATUS_CLOSED = 1;

    // reply_status 专用。历史上这里误用了上面两个 status 常量，语义正好与列注释
    // 相反（管理员回复写 0、用户回复写 1），后台按反的语义渲染、用户前端按注释
    // 语义渲染，于是新建工单一落库就在用户端显示「官方已回复」。
    const REPLY_STATUS_PENDING = 0;   // 等客服回复
    const REPLY_STATUS_REPLIED = 1;   // 客服已回复

    public static $statusMap = [
        self::STATUS_OPENING => '开启',
        self::STATUS_CLOSED => '关闭'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
    
    /**
     * 关联的工单消息
     */
    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class, 'ticket_id', 'id');
    }
    
    // 即将删除
    public function message(): HasMany
    {
        return $this->hasMany(TicketMessage::class, 'ticket_id', 'id');
    }
}
