<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 自动续费。
 *
 * v2_user.auto_renew             用户自己打开的开关：到期前余额足够时按上次配置自动续费。
 * v2_user.auto_renew_notified_at 上一次「自动续费未执行」通知对应的到期时间戳 —— 同一个到期日只通知一次。
 * v2_order.auto_renew            这笔订单由自动续费任务创建（用户端 / 管理端打标签、可筛选）。
 *
 * 纯新增列，均有默认值；老代码不读不写。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_user', 'auto_renew')) {
                $table->tinyInteger('auto_renew')->default(0)->after('remind_traffic');
            }
            if (!Schema::hasColumn('v2_user', 'auto_renew_notified_at')) {
                $table->integer('auto_renew_notified_at')->nullable()->after('auto_renew');
            }
        });
        Schema::table('v2_order', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_order', 'auto_renew')) {
                $table->tinyInteger('auto_renew')->default(0)->after('type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('v2_order', function (Blueprint $table) {
            if (Schema::hasColumn('v2_order', 'auto_renew')) $table->dropColumn('auto_renew');
        });
        Schema::table('v2_user', function (Blueprint $table) {
            foreach (['auto_renew_notified_at', 'auto_renew'] as $column) {
                if (Schema::hasColumn('v2_user', $column)) $table->dropColumn($column);
            }
        });
    }
};
