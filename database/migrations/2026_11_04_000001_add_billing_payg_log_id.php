<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 上网按量的月度保底补扣也进「账单与收据」：v2_billing_document.payg_log_id 指向那一笔 v2_payg_log（type = floor）。
 * 保底凭证按收据存（kind = receipt，order_id 为空），这一列的唯一约束保证每笔保底至多一份凭证（同收据的 order_id）。
 *
 * 纯新增可空列；老代码不读不写。
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('v2_billing_document', 'payg_log_id')) {
            Schema::table('v2_billing_document', function (Blueprint $table) {
                $table->unsignedBigInteger('payg_log_id')->nullable()->after('pay_order_id');
                $table->unique('payg_log_id', 'uniq_billing_doc_payg_log');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('v2_billing_document', 'payg_log_id')) {
            Schema::table('v2_billing_document', function (Blueprint $table) {
                $table->dropUnique('uniq_billing_doc_payg_log');
                $table->dropColumn('payg_log_id');
            });
        }
    }
};
