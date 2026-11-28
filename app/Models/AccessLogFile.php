<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 一个上传到 S3 的访问记录批次。见 App\Services\AccessLog */
class AccessLogFile extends Model
{
    protected $table = 'v2_access_log_file';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = [
        'node_id' => 'integer',
        'seq' => 'integer',
        'hour' => 'integer',
        'hour_at' => 'integer',
        'bytes' => 'integer',
        'records' => 'integer',
        'dropped' => 'integer',
        'users' => 'integer',
        'from_at' => 'integer',
        'to_at' => 'integer',
        'offsets' => 'array',
        'created_at' => 'integer',
    ];

    /** 第 $group 段的 [起点, 长度] */
    public function range(int $group): array
    {
        $offsets = $this->offsets;
        $start = (int) ($offsets[$group] ?? 0);
        $end = $group + 1 < count($offsets) ? (int) $offsets[$group + 1] : $this->bytes;
        return [$start, max(0, $end - $start)];
    }
}
