<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 给 v2_user.group_id 补单列索引。
 *
 * 节点端拉用户名单（ServerService::getAvailableUsers）按 group_id 筛用户，
 * 288 个节点每分钟各拉一次。此前 group_id 只出现在
 * [u, d, expired_at, group_id, banned, transfer_enable] 这个复合索引的第四位，
 * 前面是范围列，MySQL 用不上它 —— 每次拉名单都是对 2 万行用户表的全表扫描。
 * 单列索引让它变成几十行的索引定位；ServerGroup::users() 等按组查用户的地方一并受益。
 *
 * 纯新增索引：对旧代码零影响；MySQL 5.7+/8 在线 DDL，2 万行不到 1 秒。
 * 与 2026_05_06 的性能索引迁移同一套幂等写法（hasColumn / 索引存在性判断）。
 */
return new class extends Migration {
    public function up(): void
    {
        $this->ensureIndex('v2_user', 'group_id', 'idx_v2_user_group_id');
    }

    public function down(): void
    {
        $this->dropIndexIfExists('v2_user', 'idx_v2_user_group_id');
    }

    private function ensureIndex(string $table, string $column, string $indexName): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return;
        }
        if ($this->indexExists($table, $indexName)) {
            return;
        }
        Schema::table($table, function (Blueprint $t) use ($column, $indexName) {
            $t->index($column, $indexName);
        });
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }
        if (!$this->indexExists($table, $indexName)) {
            return;
        }
        Schema::table($table, function (Blueprint $t) use ($indexName) {
            $t->dropIndex($indexName);
        });
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

        if ($driver === 'sqlite') {
            // PRAGMA 不接受参数绑定，但 $table 来自本迁移内硬编码列表，无注入风险
            $rows = $connection->select("PRAGMA index_list(" . $connection->getPdo()->quote($table) . ")");
            foreach ($rows as $row) {
                if (($row->name ?? '') === $indexName) {
                    return true;
                }
            }
            return false;
        }

        if ($driver === 'pgsql') {
            $row = $connection->selectOne(
                'SELECT 1 AS c FROM pg_indexes WHERE tablename = ? AND indexname = ?',
                [$table, $indexName]
            );
            return $row !== null;
        }

        if (method_exists(Schema::class, 'hasIndex')) {
            return Schema::hasIndex($table, $indexName);
        }
        return false;
    }
};
