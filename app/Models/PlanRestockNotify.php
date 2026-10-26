<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 售罄套餐的到货提醒登记（见 App\Services\RestockNotifier）。同一套餐同一邮箱一行；
 * notified_at 为空 = 还在等，发过一封就记上时间，不再发第二封（用户可以重新登记）。
 *
 * @property int $id
 * @property int $plan_id
 * @property int|null $user_id
 * @property string $email
 * @property int|null $notified_at
 * @property int $created_at
 * @property int $updated_at
 */
class PlanRestockNotify extends Model
{
    protected $table = 'v2_plan_restock_notify';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'plan_id' => 'integer',
        'user_id' => 'integer',
        'notified_at' => 'integer',
    ];
}
