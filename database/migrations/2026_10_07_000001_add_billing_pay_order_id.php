<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 续费账单的免登录付款：账单通过邮件里的付款链接下的那一单记在 v2_billing_document.pay_order_id 上，
 * 再打开链接接着付同一单。收据的 order_id 有唯一约束，账单不能复用那一列。
 *
 * 建表迁移（2026_10_06_000003）里已经带了这一列，这里只给按早一版建过表的库补列；纯新增可空列。
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('v2_billing_document', 'pay_order_id')) {
            Schema::table('v2_billing_document', function (Blueprint $table) {
                $table->integer('pay_order_id')->nullable()->index('idx_billing_doc_pay_order')->after('order_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('v2_billing_document', 'pay_order_id')) {
            Schema::table('v2_billing_document', function (Blueprint $table) {
                $table->dropIndex('idx_billing_doc_pay_order');
                $table->dropColumn('pay_order_id');
            });
        }
    }
};
