<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 修正 v2_stat_user 的 unique 约束：把 record_type 也纳入唯一键。
 *
 * 历史 schema 的 unique 是 (server_rate, user_id, record_at) —— 缺 record_type。
 * StatUserJob 在 MySQL upsert 时把 record_type 加进 $uniqueBy，但 MySQL 实际只看 schema
 * 的 unique 索引，所以同 (user_id, server_rate, record_at) 下 record_type='d' 与 ='m' 会撞库；
 * Postgres 路径 ON CONFLICT (user_id, server_rate, record_at) 也一样。
 *
 * 结果：daily 与 monthly 统计在同 user/server_rate/同日时会把 u/d 累加到同一行，
 * record_type 取决于先写谁，统计数据被静默错位。
 *
 * 这个迁移先检测有没有"同 (server_rate,user_id,record_at) 下多 record_type 行"的脏数据；
 * 有冲突就跳过加新 unique 并 log 警告，由运维手动 dedup 后再跑一次；没有冲突就把
 * unique 替换成 (server_rate, user_id, record_at, record_type)。
 */
return new class extends Migration {
    private const TABLE = 'v2_stat_user';
    private const OLD_INDEX = 'server_rate_user_id_record_at';
    private const NEW_INDEX = 'server_rate_user_id_record_at_record_type';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE) || !Schema::hasColumn(self::TABLE, 'record_type')) {
            return;
        }
        if ($this->indexExists(self::NEW_INDEX)) {
            return;
        }

        // 检查是否存在脏数据会导致新 unique 失败
        $conflicts = DB::table(self::TABLE)
            ->select('user_id', 'server_rate', 'record_at')
            ->selectRaw('COUNT(DISTINCT record_type) AS types')
            ->groupBy('user_id', 'server_rate', 'record_at')
            ->havingRaw('COUNT(DISTINCT record_type) > 1')
            ->limit(1)
            ->get();

        if ($conflicts->isNotEmpty()) {
            Log::warning('v2_stat_user 存在 (user_id, server_rate, record_at) 同键下多 record_type 的脏数据，跳过加 unique。请运维手动合并后重跑 migrate。');
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $t) {
            if ($this->indexExists(self::OLD_INDEX)) {
                $t->dropUnique(self::OLD_INDEX);
            }
            $t->unique(['server_rate', 'user_id', 'record_at', 'record_type'], self::NEW_INDEX);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }
        Schema::table(self::TABLE, function (Blueprint $t) {
            if ($this->indexExists(self::NEW_INDEX)) {
                $t->dropUnique(self::NEW_INDEX);
            }
            if (!$this->indexExists(self::OLD_INDEX)) {
                $t->unique(['server_rate', 'user_id', 'record_at'], self::OLD_INDEX);
            }
        });
    }

    private function indexExists(string $indexName): bool
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $table = self::TABLE;

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
