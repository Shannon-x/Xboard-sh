<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 为节点表新增 listen（监听地址）列。
 *
 * - 幂等：已存在则跳过。
 * - 默认值 '0.0.0.0' 与历史 buildNodeConfig 硬编码值一致，
 *   升级镜像后旧节点 listen 列填充默认值，v2node 行为完全不变。
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('v2_server')) {
            return;
        }

        if (!$this->hasColumn('v2_server', 'listen')) {
            Schema::table('v2_server', function (Blueprint $table) {
                $table->string('listen', 64)
                    ->default('0.0.0.0')
                    ->after('host')
                    ->comment('监听地址，默认 0.0.0.0');
            });
        }

        // 历史行可能因旧版本写入 NULL，回填一次默认值。
        DB::table('v2_server')
            ->whereNull('listen')
            ->update(['listen' => '0.0.0.0']);
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_server')) {
            return;
        }

        if ($this->hasColumn('v2_server', 'listen')) {
            Schema::table('v2_server', function (Blueprint $table) {
                $table->dropColumn('listen');
            });
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        $database = DB::connection()->getDatabaseName();
        $exists = DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$database, $table, $column]
        );
        return ((int) ($exists->c ?? 0)) > 0;
    }
};
