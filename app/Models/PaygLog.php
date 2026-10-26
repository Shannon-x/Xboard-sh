<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 按量钱包事件（见 App\Services\Payg\PaygService）。钱包余额不在这里：它就是 v2_user 的
 * transfer_enable − u − d；这里只记每一笔充值 / 赠送 / 月度保底，给扣费明细和幂等判断用。
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $plan_id
 * @property int|null $order_id
 * @property string $type               self::TYPE_*
 * @property int $opening               这一单开了新钱包
 * @property string|null $month         floor：YYYY-MM
 * @property int $amount                分
 * @property int $bytes
 * @property int $bonus_bytes
 * @property int $first_bonus_bytes
 * @property int $usage_bytes
 * @property int $created_at
 * @property int $updated_at
 */
class PaygLog extends Model
{
    public const TYPE_RECHARGE = 'recharge';
    public const TYPE_AUTO_TOPUP = 'auto_topup';
    public const TYPE_FLOOR = 'floor';

    protected $table = 'v2_payg_log';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'user_id' => 'integer',
        'plan_id' => 'integer',
        'order_id' => 'integer',
        'opening' => 'integer',
        'amount' => 'integer',
        'bytes' => 'integer',
        'bonus_bytes' => 'integer',
        'first_bonus_bytes' => 'integer',
        'usage_bytes' => 'integer',
    ];
}
