<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * App\Models\Order
 *
 * @property int $id
 * @property int $user_id
 * @property int $plan_id
 * @property int|null $payment_id
 * @property string $period
 * @property string $trade_no
 * @property int $total_amount
 * @property int|null $handling_amount
 * @property int|null $balance_amount
 * @property int|null $refund_amount
 * @property int|null $surplus_amount
 * @property int $type
 * @property int $status
 * @property array|null $surplus_order_ids
 * @property int|null $coupon_id
 * @property int $created_at
 * @property int $updated_at
 * @property int|null $commission_status
 * @property int|null $invite_user_id
 * @property int|null $actual_commission_balance
 * @property int|null $commission_rate
 * @property int|null $commission_auto_check
 * @property int|null $commission_balance
 * @property int|null $discount_amount
 * @property int|null $paid_at
 * @property string|null $callback_no
 *
 * @property-read Plan $plan
 * @property-read Payment|null $payment
 * @property-read User $user
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CommissionLog> $commission_log
 */
class Order extends Model
{
    protected $table = 'v2_order';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'surplus_order_ids' => 'array',
        // 所有金钱字段都是"分"单位的整数。补全 cast 让读写两端统一：
        // ① 从 DB 取出来始终是 int（驱动有时给 string），前端 JSON 类型稳定；
        // ② 写入时把 string '30' 整数化，避免 admin 前端 form 误传字符串/小数把列写花。
        // 不影响 JSON 字段名/字段数量，对前端透明。
        'total_amount'              => 'integer',
        'handling_amount'           => 'integer',
        'balance_amount'            => 'integer',
        'refund_amount'             => 'integer',
        'surplus_amount'            => 'integer',
        'discount_amount'           => 'integer',
        'commission_balance'        => 'integer',
        'actual_commission_balance' => 'integer',
    ];

    const STATUS_PENDING = 0; // 待支付
    const STATUS_PROCESSING = 1; // 开通中
    const STATUS_CANCELLED = 2; // 已取消
    const STATUS_COMPLETED = 3; // 已完成
    const STATUS_DISCOUNTED = 4; // 已折抵

    public static $statusMap = [
        self::STATUS_PENDING => '待支付',
        self::STATUS_PROCESSING => '开通中',
        self::STATUS_CANCELLED => '已取消',
        self::STATUS_COMPLETED => '已完成',
        self::STATUS_DISCOUNTED => '已折抵',
    ];

    const TYPE_NEW_PURCHASE = 1; // 新购
    const TYPE_RENEWAL = 2; // 续费
    const TYPE_UPGRADE = 3; // 套餐变更
    const TYPE_RESET_TRAFFIC = 4; //流量重置包
    public static $typeMap = [
        self::TYPE_NEW_PURCHASE => '新购',
        self::TYPE_RENEWAL => '续费',
        self::TYPE_UPGRADE => '套餐变更',
        self::TYPE_RESET_TRAFFIC => '流量重置',
    ];

    /**
     * 获取与订单关联的支付方式
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id', 'id');
    }

    /**
     * 获取与订单关联的用户
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 获取邀请人
     */
    public function invite_user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invite_user_id', 'id');
    }

    /**
     * 获取与订单关联的套餐
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'id');
    }

    /**
     * 获取与订单关联的佣金记录
     */
    public function commission_log(): HasMany
    {
        return $this->hasMany(CommissionLog::class, 'trade_no', 'trade_no');
    }
}
