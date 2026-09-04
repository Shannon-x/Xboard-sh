<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 汇率改为实时获取后补的三列：
 * - usdt_fee    申请时锁定的通道费（USDT），用户看到的「实际到账」= 毛额 - 它
 * - settle_rate 打款那一刻的汇率，和申请时的 usdt_rate 分开存，事后对账能看出行情走了多少
 * - rate_source 汇率来源（binance_p2p / okx / coingecko / coinbase / manual），排查用
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_commission_withdrawal')) {
            return;
        }

        Schema::table('v2_commission_withdrawal', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_commission_withdrawal', 'usdt_fee')) {
                $table->decimal('usdt_fee', 14, 4)->nullable()->after('usdt_rate')->comment('申请时锁定的通道费（USDT）');
            }
            if (!Schema::hasColumn('v2_commission_withdrawal', 'settle_rate')) {
                $table->decimal('settle_rate', 12, 4)->nullable()->after('paid_usdt')->comment('打款时的实时汇率（法币/USDT）');
            }
            if (!Schema::hasColumn('v2_commission_withdrawal', 'rate_source')) {
                $table->string('rate_source', 32)->nullable()->after('settle_rate')->comment('汇率来源');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_commission_withdrawal')) {
            return;
        }

        Schema::table('v2_commission_withdrawal', function (Blueprint $table) {
            foreach (['usdt_fee', 'settle_rate', 'rate_source'] as $column) {
                if (Schema::hasColumn('v2_commission_withdrawal', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
