<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 收据 / 续费账单邮件。
 *
 * v2_order.receipt_sent_at           收据邮件成功交给 SMTP 的时间戳 —— 一笔订单只发一次收据。
 * v2_user.invoice_notified_at        首张续费账单（到期前 N 天）对应的到期时间戳 —— 同一个到期日只发一次；
 *                                    续费后 expired_at 变了，下一个周期自然会再发。
 * v2_user.invoice_final_notified_at  最后提醒（到期前 24 小时）对应的到期时间戳，同上。
 *
 * 纯新增可空列；老代码不读不写。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_order', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_order', 'receipt_sent_at')) {
                $table->integer('receipt_sent_at')->nullable()->after('paid_at');
            }
        });
        Schema::table('v2_user', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_user', 'invoice_notified_at')) {
                $table->integer('invoice_notified_at')->nullable()->after('auto_renew_notified_at');
            }
            if (!Schema::hasColumn('v2_user', 'invoice_final_notified_at')) {
                $table->integer('invoice_final_notified_at')->nullable()->after('invoice_notified_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            foreach (['invoice_final_notified_at', 'invoice_notified_at'] as $column) {
                if (Schema::hasColumn('v2_user', $column)) $table->dropColumn($column);
            }
        });
        Schema::table('v2_order', function (Blueprint $table) {
            if (Schema::hasColumn('v2_order', 'receipt_sent_at')) $table->dropColumn('receipt_sent_at');
        });
    }
};
