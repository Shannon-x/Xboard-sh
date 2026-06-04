<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddTrafficResetFieldsToUsers extends Migration
{
    /**
     * Run the migrations.
     *
     * 历史 anti-pattern：原实现用 `hasColumn('v2_user', 'next_reset_at')` 一列的存在性
     * 守卫了 3 列（next_reset_at, last_reset_at, reset_count）+ 1 索引 的整个 add 块。
     * 若 next_reset_at 已被先前的别处 SQL/迁移建过，整个块被跳过 → last_reset_at 和
     * reset_count 永远加不上，AdvanceCycleService::advance 跑到 update v2_user set
     * last_reset_at=... 时 throw 1054 Unknown column。
     *
     * 改为 per-column 守卫；索引也单独判定（同款 anti-pattern 也漏在 next_reset_at 的
     * 守卫覆盖范围里）。
     *
     * 注意：已经跑过本迁移（migrations 表有记录）的库，改这个源文件不会重跑。对那些库，
     * 由后续 backfill migration 2026_06_03_000005 兜底补列。
     */
    public function up(): void
    {
        ini_set('memory_limit', '-1');

        Schema::table('v2_user', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_user', 'next_reset_at')) {
                $table->integer('next_reset_at')->nullable()->after('expired_at')->comment('下次流量重置时间');
            }
            if (!Schema::hasColumn('v2_user', 'last_reset_at')) {
                $table->integer('last_reset_at')->nullable()->after('next_reset_at')->comment('上次流量重置时间');
            }
            if (!Schema::hasColumn('v2_user', 'reset_count')) {
                $table->integer('reset_count')->default(0)->after('last_reset_at')->comment('流量重置次数');
            }
        });

        // 索引单独 add，并且自检是否已存在 —— 原实现塞在上面 column-add 块里，
        // 一旦上方 hasColumn 短路就连索引也建不出来。
        $hasIndex = collect(DB::select('SHOW INDEX FROM v2_user'))
            ->pluck('Key_name')
            ->contains('idx_next_reset_at');
        if (!$hasIndex && Schema::hasColumn('v2_user', 'next_reset_at')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->index('next_reset_at', 'idx_next_reset_at');
            });
        }

        // Set initial reset time for existing users
        Artisan::call('reset:traffic', ['--fix-null' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            $table->dropIndex('idx_next_reset_at');
            $table->dropColumn(['next_reset_at', 'last_reset_at', 'reset_count']);
        });
    }
}