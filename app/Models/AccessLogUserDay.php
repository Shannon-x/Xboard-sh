<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 用户 × 天 × 节点的访问记录汇总。主键是四列组合，只用查询构造器读写 */
class AccessLogUserDay extends Model
{
    protected $table = 'v2_access_log_user_day';
    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = null;
    protected $guarded = [];
    protected $casts = [
        'user_id' => 'integer',
        'node_id' => 'integer',
        'records' => 'integer',
        'up' => 'integer',
        'down' => 'integer',
        'hours' => 'integer',
        'updated_at' => 'integer',
    ];
}
