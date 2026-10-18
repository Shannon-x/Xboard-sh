<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 推广增长：
 *   v2_user.invite_code              注册时实际使用的邀请码（分渠道漏斗用；老用户为空 = 「未知来源」）
 *   v2_order.invite_discount_amount  被邀请人首单优惠金额（分），已计入 discount_amount，单独记一列方便展示与对账
 *
 * 两列都可空 / 有默认值，老代码不读不写。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_user', 'invite_code')) {
                $table->string('invite_code', 32)->nullable()->after('invite_user_id')->index('v2_user_invite_code_index');
            }
        });
        Schema::table('v2_order', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_order', 'invite_discount_amount')) {
                $table->integer('invite_discount_amount')->default(0)->after('discount_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            if (Schema::hasColumn('v2_user', 'invite_code')) {
                $table->dropIndex('v2_user_invite_code_index');
                $table->dropColumn('invite_code');
            }
        });
        Schema::table('v2_order', function (Blueprint $table) {
            if (Schema::hasColumn('v2_order', 'invite_discount_amount')) {
                $table->dropColumn('invite_discount_amount');
            }
        });
    }
};
