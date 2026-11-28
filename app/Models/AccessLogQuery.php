<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 后台的一次访问明细查询，兼作查询审计记录 */
class AccessLogQuery extends Model
{
    public const STATUS_PENDING = 0;
    public const STATUS_RUNNING = 1;
    public const STATUS_DONE = 2;
    public const STATUS_FAILED = 3;

    protected $table = 'v2_access_log_query';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'admin_id' => 'integer',
        'user_id' => 'integer',
        'filter' => 'array',
        'status' => 'integer',
        'files' => 'integer',
        'missing' => 'integer',
        'bytes' => 'integer',
        'rows' => 'integer',
        'truncated' => 'boolean',
        'summary' => 'array',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
    protected $hidden = ['export_key'];

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
