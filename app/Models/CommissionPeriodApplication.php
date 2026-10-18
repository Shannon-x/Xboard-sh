<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 周期返佣申请（见 App\Services\Commission\PeriodCommission）。
 *
 * @property int $id
 * @property int $user_id
 * @property int $status
 * @property int $active_count
 * @property int $min_invitees
 * @property int|null $admin_id
 * @property string|null $reason
 * @property int|null $decided_at
 * @property int|null $revoked_at
 * @property int|null $revoked_count
 * @property int $created_at
 * @property int $updated_at
 */
class CommissionPeriodApplication extends Model
{
    protected $table = 'v2_commission_period_application';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'decided_at' => 'integer',
        'revoked_at' => 'integer',
        'status' => 'integer',
        'active_count' => 'integer',
        'min_invitees' => 'integer',
    ];

    public const STATUS_PENDING = 0;
    public const STATUS_APPROVED = 1;
    public const STATUS_REJECTED = 2;
    public const STATUS_REVOKED = 3;

    /** 给前端的状态字符串（数字状态只在库里用） */
    public const STATUS_KEYS = [
        self::STATUS_PENDING => 'pending',
        self::STATUS_APPROVED => 'approved',
        self::STATUS_REJECTED => 'rejected',
        self::STATUS_REVOKED => 'revoked',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id', 'id');
    }

    public function statusKey(): string
    {
        return self::STATUS_KEYS[(int) $this->status] ?? 'pending';
    }
}
