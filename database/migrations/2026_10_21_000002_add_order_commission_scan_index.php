<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * check:commission 的 autoCheck 条件是 commission_status=0 AND status=3 AND invite_user_id IS NOT NULL
 * AND updated_at <= 三天前。原来只有单列索引（status 基数 3、commission_status 基数 2），优化器挑 status
 * 索引后要扫全部已完成订单并逐行加锁（2026-10-21 生产死锁记录：122,664 个行锁）。
 * 复合索引让扫描只落在「待确认 × 已完成 × 有邀请人」的几十行上，updated_at 在索引内过滤。
 * 54k 行的表上 InnoDB 在线加索引，不阻塞写入。
 */
return new class extends Migration
{
    private const INDEX = 'idx_order_commission_scan';

    public function up(): void
    {
        if (Schema::hasIndex('v2_order', self::INDEX)) {
            return;
        }
        Schema::table('v2_order', function (Blueprint $table) {
            $table->index(['commission_status', 'status', 'invite_user_id', 'updated_at'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (!Schema::hasIndex('v2_order', self::INDEX)) {
            return;
        }
        Schema::table('v2_order', function (Blueprint $table) {
            $table->dropIndex(self::INDEX);
        });
    }
};
