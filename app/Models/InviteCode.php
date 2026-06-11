<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InviteCode extends Model
{
    protected $table = 'v2_invite_code';
    protected $dateFormat = 'U';
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'status' => 'boolean',
    ];

    const STATUS_UNUSED = 0;
    const STATUS_USED = 1;

    /**
     * 墓碑态：用户删除的邀请码。
     *
     * 不做硬删除的原因：code 上有唯一索引，墓碑行继续占住该字符串，
     * 防止已外流的邀请链接被他人抢注劫持流量；本人重建同名码时自动恢复。
     *
     * ⚠️ status 列有 boolean cast，PHP 侧读 status=2 的行会得到 true（与 USED 无法区分）。
     *    所有涉及三态判断的逻辑必须用查询级 where('status', X) 过滤，
     *    或 getRawOriginal('status') 读原始值，不要直接比较 $model->status。
     *    现有查询（fetch/save 的 where status=0、注册消费的 where status=0）
     *    天然排除墓碑行，无需改动。
     */
    const STATUS_DELETED = 2;
}
