<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 佣金提现申请。
 *
 * 账务模型：申请时立刻从 commission_balance 扣除并冻结在本记录里；驳回 / 用户取消时退回；
 * 完成时不再动余额（钱在申请时就已经扣掉了，避免等待期内被划转 / 重复申请造成双花）。
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $ticket_id
 * @property int $amount 分
 * @property string $currency
 * @property string $chain_code
 * @property string $chain_name
 * @property string|null $network
 * @property string $address
 * @property string|null $usdt_rate
 * @property string|null $usdt_amount
 * @property int $status
 * @property int|null $admin_id
 * @property string|null $txid
 * @property string|null $paid_usdt
 * @property string|null $remark
 * @property string|null $reject_reason
 * @property int|null $settled_at
 * @property int $created_at
 * @property int $updated_at
 */
class CommissionWithdrawal extends Model
{
    protected $table = 'v2_commission_withdrawal';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'settled_at' => 'timestamp',
        'amount' => 'integer',
        'status' => 'integer',
    ];

    public const STATUS_PENDING = 0;
    public const STATUS_COMPLETED = 1;
    public const STATUS_REJECTED = 2;
    public const STATUS_CANCELLED = 3;

    public static array $statusMap = [
        self::STATUS_PENDING => '待处理',
        self::STATUS_COMPLETED => '已完成',
        self::STATUS_REJECTED => '已驳回',
        self::STATUS_CANCELLED => '已取消',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id', 'id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id', 'id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
