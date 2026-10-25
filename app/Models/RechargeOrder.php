<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 余额充值单。金额单位都是「分」。
 *
 * 和套餐订单（v2_order）分开存：充值不开通任何东西、不返佣、不参与折抵与续费推算，
 * 混进 v2_order 会让「用户的上一张已付订单」「首单」一类的判断全部失真。
 * 支付链路（收银台、回调、金额守卫、事后核验）则与订单共用，见 App\Services\Recharge\RechargeService。
 *
 * @property int $id
 * @property int $user_id
 * @property string $trade_no
 * @property int $deposit_amount   充值金额
 * @property int $bonus_amount     赠送金额，下单时按当时的档位定死
 * @property int $total_credit     入账总额 = deposit + bonus
 * @property int $pay_amount       应付金额 = deposit + 手续费；首次选定支付方式时算出
 * @property int|null $handling_amount
 * @property int|null $payment_id
 * @property string|null $payment_method
 * @property int $status
 * @property string|null $callback_no
 * @property int|null $invite_user_id
 * @property int $commission_balance 恒为 0（充值不返佣），保留列兼容历史数据
 * @property int|null $paid_at
 * @property int $created_at
 * @property int $updated_at
 *
 * @property-read User|null $user
 * @property-read Payment|null $payment
 */
class RechargeOrder extends Model
{
    protected $table = 'v2_recharge_order';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'user_id' => 'integer',
        'deposit_amount' => 'integer',
        'bonus_amount' => 'integer',
        'total_credit' => 'integer',
        'pay_amount' => 'integer',
        'handling_amount' => 'integer',
        'payment_id' => 'integer',
        'status' => 'integer',
        'invite_user_id' => 'integer',
        'commission_balance' => 'integer',
        'paid_at' => 'integer',
    ];

    public const STATUS_PENDING = 0;
    public const STATUS_CANCELLED = 2;
    public const STATUS_COMPLETED = 3;

    public static array $statusMap = [
        self::STATUS_PENDING => '待支付',
        self::STATUS_CANCELLED => '已取消',
        self::STATUS_COMPLETED => '已到账',
    ];

    /** 充值单在「订单列表」接口里的 plan_id / period / type（沿用 wyx2685/v2board 前端的约定） */
    public const LEGACY_PLAN_ID = 0;
    public const LEGACY_PERIOD = 'deposit';
    public const LEGACY_ORDER_TYPE = 9;

    /** 手动确认到账时写进 callback_no 的值，与订单的 manual_operation 同义 */
    public const MANUAL_CALLBACK_NO = 'manual_operation';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id', 'id');
    }

    /** 已到账充值的现金收入（充值金额，不含手续费），按到账时间落区间 [$from, $to) */
    public static function incomeBetween(int $from, int $to): int
    {
        return (int) self::where('status', self::STATUS_COMPLETED)
            ->where('paid_at', '>=', $from)
            ->where('paid_at', '<', $to)
            ->sum('deposit_amount');
    }
}
