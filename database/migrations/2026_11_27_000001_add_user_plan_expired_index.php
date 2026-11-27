<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2_user (plan_id, expired_at) 联合索引：套餐席位统计（PlanCapacity / PlanService::hasCapacity）是
 *   WHERE plan_id IN (...) AND (expired_at >= ? OR expired_at IS NULL) GROUP BY plan_id
 * 只有 plan_id 单列索引时，大部分用户都落在这几十个套餐里，每行都要回表取 expired_at，
 * 2026-11-27 生产 4 万用户一次约 170 ms；联合索引后整条查询只读索引。
 *
 * 纯新增索引，老代码不受影响；InnoDB 在线建索引，不锁表。
 */
return new class extends Migration {
    private const INDEX = 'idx_v2_user_plan_id_expired_at';

    public function up(): void
    {
        if (!Schema::hasTable('v2_user') || Schema::hasIndex('v2_user', self::INDEX)) {
            return;
        }
        Schema::table('v2_user', function (Blueprint $table) {
            $table->index(['plan_id', 'expired_at'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_user') || !Schema::hasIndex('v2_user', self::INDEX)) {
            return;
        }
        Schema::table('v2_user', function (Blueprint $table) {
            $table->dropIndex(self::INDEX);
        });
    }
};
