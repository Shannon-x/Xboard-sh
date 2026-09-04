<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('v2_commission_withdrawal')) {
            return;
        }

        Schema::create('v2_commission_withdrawal', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('user_id');
            $table->integer('ticket_id')->nullable()->comment('随申请自动创建的系统工单');
            $table->integer('amount')->comment('提现金额（分），申请时已从 commission_balance 扣除冻结');
            $table->string('currency', 8)->default('CNY');
            $table->string('chain_code', 32)->comment('后台配置的链 code，如 usdt_trc20');
            $table->string('chain_name', 64);
            $table->string('network', 64)->nullable();
            $table->string('address', 255);
            $table->decimal('usdt_rate', 12, 4)->nullable()->comment('申请时的参考汇率（法币/USDT）');
            $table->decimal('usdt_amount', 14, 4)->nullable()->comment('按参考汇率估算的 USDT 数量');
            $table->tinyInteger('status')->default(0)->comment('0 待处理 1 已完成 2 已驳回 3 用户取消');
            $table->integer('admin_id')->nullable();
            $table->string('txid', 255)->nullable()->comment('打款交易哈希');
            $table->decimal('paid_usdt', 14, 4)->nullable()->comment('实际打款 USDT');
            $table->string('remark', 500)->nullable()->comment('管理员备注（不对用户展示）');
            $table->string('reject_reason', 255)->nullable();
            $table->integer('settled_at')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');

            $table->index(['user_id', 'created_at'], 'idx_commission_withdrawal_user_time');
            $table->index(['status', 'created_at'], 'idx_commission_withdrawal_status_time');
            $table->index('ticket_id', 'idx_commission_withdrawal_ticket');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_commission_withdrawal');
    }
};
