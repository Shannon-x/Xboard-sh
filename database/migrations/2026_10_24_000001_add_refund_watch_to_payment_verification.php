<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 退款回查：付款核验通过（verdict=ok）的单，付款后前 15 天每天、之后第 20/25/30/38/45 天再向网关查一次，
 * 发现用户通过投诉等途径把钱退回去了，就自动封号、收回套餐，等管理员确认后删号。
 * 见 App\Services\RefundWatchService。
 */
return new class extends Migration
{
    private const TABLE = 'v2_payment_verification';
    private const INDEX = 'idx_payment_verification_recheck';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table) {
            if (!Schema::hasColumn(self::TABLE, 'recheck_stage')) {
                $table->unsignedTinyInteger('recheck_stage')->default(0)->comment('已完成的退款回查轮次');
            }
            if (!Schema::hasColumn(self::TABLE, 'recheck_at')) {
                $table->integer('recheck_at')->nullable()->comment('下一次退款回查时间');
            }
            if (!Schema::hasColumn(self::TABLE, 'refunded_at')) {
                $table->integer('refunded_at')->nullable()->comment('发现网关已退款并完成处置的时间');
            }
            if (!Schema::hasColumn(self::TABLE, 'enforce_snapshot')) {
                $table->text('enforce_snapshot')->nullable()->comment('处置前的用户状态（JSON），误判时照此恢复');
            }
        });
        if (!Schema::hasIndex(self::TABLE, self::INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->index(['verdict', 'recheck_at'], self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex(self::TABLE, self::INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropIndex(self::INDEX);
            });
        }
        Schema::table(self::TABLE, function (Blueprint $table) {
            foreach (['recheck_stage', 'recheck_at', 'refunded_at', 'enforce_snapshot'] as $column) {
                if (Schema::hasColumn(self::TABLE, $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
