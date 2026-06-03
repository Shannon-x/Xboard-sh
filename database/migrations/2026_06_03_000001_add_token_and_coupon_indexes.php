<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 补齐 v2_user.token 与 v2_coupon.code 的索引。
 *
 *   - v2_user.token  ：订阅链路（Client middleware）每次访问都 where token=?，是系统最热的读路径。
 *                       原表只 `char(32)`，无 unique/index；10w+ 用户后单次订阅请求会全表扫。
 *   - v2_coupon.code ：下单时按 code 校验优惠券，无索引同样全表扫。
 *
 * 这里只加普通索引（非 unique）。两列在业务上虽然天然应该唯一（Helper::guid / Helper::randomChar
 * 生成的 32+ 字符随机串撞概率可忽略），但历史脏数据 / 二次 fork 库可能有重复，强加 UNIQUE 会直接 fail。
 * 索引到位后查询计划已经是 type=ref，性能收益拿到；后续可单独清理脏数据再加 unique。
 *
 * 与 2026_05_06_000001_add_missing_performance_indexes 同款幂等模式：检查 column 与 index 存在性。
 */
return new class extends Migration {
    public function up(): void
    {
        $this->ensureIndex('v2_user', 'token', 'idx_v2_user_token');
        $this->ensureIndex('v2_coupon', 'code', 'idx_v2_coupon_code');
    }

    public function down(): void
    {
        $this->dropIndexIfExists('v2_user', 'idx_v2_user_token');
        $this->dropIndexIfExists('v2_coupon', 'idx_v2_coupon_code');
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
