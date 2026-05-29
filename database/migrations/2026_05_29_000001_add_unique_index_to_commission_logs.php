<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('v2_commission_log')) {
            return;
        }

        $duplicates = DB::table('v2_commission_log')
            ->select(
                'trade_no',
                'invite_user_id',
                DB::raw('MIN(id) as keep_id'),
                DB::raw('SUM(get_amount) as total_get_amount'),
                DB::raw('MAX(updated_at) as last_updated_at')
            )
            ->whereNotNull('invite_user_id')
            ->groupBy('trade_no', 'invite_user_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('keep_id')
            ->get();

        foreach ($duplicates as $duplicate) {
            DB::table('v2_commission_log')
                ->where('id', $duplicate->keep_id)
                ->update([
                    'get_amount' => $duplicate->total_get_amount,
                    'updated_at' => $duplicate->last_updated_at,
                ]);

            DB::table('v2_commission_log')
                ->where('trade_no', $duplicate->trade_no)
                ->where('invite_user_id', $duplicate->invite_user_id)
                ->where('id', '!=', $duplicate->keep_id)
                ->delete();
        }

        Schema::table('v2_commission_log', function (Blueprint $table) {
            $table->unique(['trade_no', 'invite_user_id'], 'uniq_commission_trade_inviter');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_commission_log')) {
            return;
        }

        Schema::table('v2_commission_log', function (Blueprint $table) {
            $table->dropUnique('uniq_commission_trade_inviter');
        });
    }
};
