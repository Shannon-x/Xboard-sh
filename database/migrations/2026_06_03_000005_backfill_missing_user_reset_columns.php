<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill missing v2_user.{last_reset_at, reset_count} columns + idx_next_reset_at index.
 *
 * 为什么单独一条迁移：
 *   - 2025_06_21_000003 的原 up() 用 `hasColumn(next_reset_at)` 一列守了 3 列 + 1 索引整块 add。
 *   - 若 next_reset_at 在更早某次升级里已被建过，整个块被跳过 →
 *     last_reset_at + reset_count + idx_next_reset_at 永远建不上。
 *   - migrations 表已经记 2025_06_21_000003 为 "Ran"，Laravel 不会重跑它，
 *     所以**已部署的库**必须靠这条新迁移自动补齐。
 *
 * 跟前面 2026_06_03_000003/000004 对 v2_stat_user 的修复模式一致：anti-pattern 已经写进
 * DB 历史时，源码改对治不了存量；只能用新迁移幂等补齐。
 *
 * 跨 driver：用 Schema::hasColumn / Schema::hasIndex 兜底，避免 driver-specific 语法。
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('v2_user')) {
            return;
        }

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

        // 索引单独检查 + 单独 add；同款 anti-pattern 把它跟着上面 column-add 一起漏了。
        if (!Schema::hasColumn('v2_user', 'next_reset_at')) {
            return;
        }
        if (!$this->indexExists('v2_user', 'idx_next_reset_at')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->index('next_reset_at', 'idx_next_reset_at');
            });
            Log::info('v2_user backfill: idx_next_reset_at index added');
        }
    }

    public function down(): void
    {
        // 不做反向 drop —— 这是 backfill 性质，原 add 的责任在 2025_06_21_000003，
        // 这里 drop 会跟那条 migration 的 down() 冲突。
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $count = $connection->selectOne(
                'SELECT COUNT(1) AS c FROM information_schema.statistics
                 WHERE table_schema = ? AND table_name = ? AND index_name = ?',
                [$connection->getDatabaseName(), $table, $indexName]
            );
            return ((int) ($count->c ?? 0)) > 0;
        }
        if ($driver === 'pgsql') {
            $row = $connection->selectOne(
                'SELECT 1 AS c FROM pg_indexes WHERE tablename = ? AND indexname = ?',
                [$table, $indexName]
            );
            return $row !== null;
        }
        if ($driver === 'sqlite') {
            $rows = $connection->select("PRAGMA index_list(" . $connection->getPdo()->quote($table) . ")");
            foreach ($rows as $row) {
                if (($row->name ?? '') === $indexName) {
                    return true;
                }
            }
            return false;
        }
        if (method_exists(Schema::class, 'hasIndex')) {
            return Schema::hasIndex($table, $indexName);
        }
        return false;
    }
};
