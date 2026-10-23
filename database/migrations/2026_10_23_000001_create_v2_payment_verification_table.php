<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 付款事后核验：已付的订单 / 充值单入账几分钟后，由定时任务向支付网关查一次这笔交易，
 * 核对「网关上真有这笔、已付、金额一致」。每张单一行，结论落定后不再重复查询。
 * 见 App\Services\PaymentVerificationService。
 *
 * 同时给 v2_order.paid_at 加索引：核验每 5 分钟按「最近 N 天已付」选单，没有索引要全表扫。
 */
return new class extends Migration
{
    private const TABLE = 'v2_payment_verification';
    private const ORDER_PAID_INDEX = 'idx_order_paid_at';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('subject_type', 16)->comment('order | recharge');
                $table->unsignedBigInteger('subject_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedInteger('payment_id')->nullable();
                $table->string('callback_no', 255)->nullable()->comment('网关交易号（回调里的 trade_no）');
                $table->integer('expected_amount')->default(0)->comment('应付（分），含手续费');
                $table->string('verdict', 16)->default('pending')
                    ->comment('pending/ok/not_found/mismatch/refunded/error/skipped');
                $table->smallInteger('gateway_status')->nullable();
                $table->integer('gateway_money')->nullable()->comment('网关返回金额（分）');
                $table->string('gateway_type', 32)->nullable();
                $table->unsignedSmallInteger('attempts')->default(0)->comment('向网关查询的次数');
                $table->unsignedSmallInteger('miss_count')->default(0)->comment('网关返回「不存在」的次数');
                $table->string('last_error', 255)->nullable();
                $table->integer('checked_at')->nullable();
                $table->integer('next_check_at')->nullable();
                $table->integer('alerted_at')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');

                $table->unique(['subject_type', 'subject_id'], 'uniq_payment_verification_subject');
                $table->index(['verdict', 'next_check_at'], 'idx_payment_verification_due');
                $table->index('checked_at', 'idx_payment_verification_checked');
            });
        }

        if (!Schema::hasIndex('v2_order', self::ORDER_PAID_INDEX)) {
            Schema::table('v2_order', function (Blueprint $table) {
                $table->index('paid_at', self::ORDER_PAID_INDEX);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('v2_order', self::ORDER_PAID_INDEX)) {
            Schema::table('v2_order', function (Blueprint $table) {
                $table->dropIndex(self::ORDER_PAID_INDEX);
            });
        }
        Schema::dropIfExists(self::TABLE);
    }
};
