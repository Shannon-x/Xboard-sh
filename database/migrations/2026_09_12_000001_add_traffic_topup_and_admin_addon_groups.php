<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 流量加购包 + 管理员手动授予的增值组。
 *
 * transfer_topup  本周期内加购的流量（byte）。加购时同时加进 transfer_enable，
 *                 所以旧前端 / 节点端 / 订阅信息看到的总量自动正确；
 *                 任何把 u/d 清零的动作（月度重置、重置包、提前周期、换套餐、新购、
 *                 管理员手动重置）都会把它从 transfer_enable 里扣回并归零 —— 加购只活在买它的那个周期。
 * admin_group_ids 管理员在用户编辑里手动授予的增值组（赔偿、工单处理）。与套餐无关、
 *                 不参与续费报价、换套餐也保留，只能由管理员撤销。
 *
 * 纯新增列，均有默认值；老代码不读不写它们，行为不变。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_user', 'transfer_topup')) {
                $table->bigInteger('transfer_topup')->default(0)->after('transfer_enable');
            }
            if (!Schema::hasColumn('v2_user', 'admin_group_ids')) {
                $table->json('admin_group_ids')->nullable()->after('plan_options');
            }
        });
    }

    public function down(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            if (Schema::hasColumn('v2_user', 'admin_group_ids')) {
                $table->dropColumn('admin_group_ids');
            }
            if (Schema::hasColumn('v2_user', 'transfer_topup')) {
                $table->dropColumn('transfer_topup');
            }
        });
    }
};
