<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 余额流水：user.balance 的每一次变动一行，带变动前后快照。金额单位「分」，正数入账、负数出账。
 *
 * @property int $id
 * @property int $user_id
 * @property string $type            self::TYPE_*
 * @property int $amount             带符号
 * @property int $balance_before
 * @property int $balance_after
 * @property int|null $order_id
 * @property string|null $ref_type   关联对象：order / gift_card / commission / admin
 * @property string|null $ref_id     关联对象标识：订单号、礼品卡码…
 * @property string|null $remark
 * @property int|null $operator_id   后台操作时的管理员 id
 * @property int $created_at
 */
class BalanceLog extends Model
{
    public const TYPE_ORDER_PAY = 'order_pay';                      // 余额支付订单（出账）
    public const TYPE_ORDER_CANCEL = 'order_cancel';                // 取消待付订单，退回已抵扣的余额（入账）
    public const TYPE_ORDER_REFUND = 'order_refund';                // 套餐变更折抵超出订单金额的部分退回（入账）
    public const TYPE_GIFT_CARD = 'gift_card';                      // 礼品卡奖励，含邀请人奖励（入账）
    public const TYPE_COMMISSION_TRANSFER = 'commission_transfer';  // 佣金划转到余额（入账）
    public const TYPE_ADMIN_ADJUST = 'admin_adjust';                // 后台手工调整（正负都有）
    public const TYPE_RECHARGE = 'recharge';                        // 充值（预留给充值插件）

    public const TYPES = [
        self::TYPE_ORDER_PAY,
        self::TYPE_ORDER_CANCEL,
        self::TYPE_ORDER_REFUND,
        self::TYPE_GIFT_CARD,
        self::TYPE_COMMISSION_TRANSFER,
        self::TYPE_ADMIN_ADJUST,
        self::TYPE_RECHARGE,
    ];

    public const UPDATED_AT = null;

    protected $table = 'v2_balance_log';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
    ];
}
