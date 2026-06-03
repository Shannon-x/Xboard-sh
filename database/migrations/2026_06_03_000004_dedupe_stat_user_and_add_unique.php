<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Dedupe v2_stat_user + add 4-column unique。是 2026_06_03_000003 的接力。
 *
 * 为什么需要单独一条迁移：
 *   - v2_stat_user 在很多老库（v2board / 二次 fork / 早于 2023_03_19 建表的部署）里
 *     根本没有 unique 索引（2023_03_19 的 Schema::hasTable check 跳过 create）。
 *   - StatUserJob 的 upsert ON DUPLICATE KEY 因此一直退化为纯 INSERT，
 *     生产库累积了"同 (user_id, server_rate, record_at, record_type) 4 元组完全重复"的脏数据。
 *   - 直接 ADD UNIQUE 会撞 1062；2026_06_03_000003 在检测到这种 dup 时主动 skip + warning，
 *     把"清干净 + 再加 unique"的活留给本迁移完成，避免 migrate 卡住。
 *
 * Dedupe 策略：**每组保留 MAX(id) 那一行，其它删掉**。
 *   - 语义：模拟"如果 UPSERT 当时能生效，最后一次写入会覆盖所有先前值"
 *   - 不做 SUM 合并：旧 upsert 路径在不同代码版本里 u/d 的语义混杂了 increment 和 snapshot；
 *     SUM 会错误放大流量统计，影响下游 commission/计费推断
 *   - 不可逆：删掉的中间行无法恢复
 *
 * 如果运维**确定**希望 SUM 合并语义：跑 migrate 之前手动执行 SUM 合并 SQL（让 dup 行的 u/d
 * 合并到保留的那一行），本迁移到达时已无 dup，直接走"无操作 → drop 旧 unique → add 新 unique"。
 *
 * 全程幂等：新 unique 已存在则 return；dedupe 完毕再 add unique；多次跑 migrate 安全。
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
            // 已经被 #3 加好（fresh install 无 dup 的库）或本迁移上次已成功
            return;
        }

        $deleted = $this->dedupeFullDuplicates();
        if ($deleted > 0) {
            Log::warning("v2_stat_user dedupe：删除 {$deleted} 行 4 元组完全重复（每组保留 MAX(id)）");
        }

        // 双保险：dedupe 后再次确认无 dup，避免别的并发写在 dedupe 与 ADD UNIQUE 之间又产生重复
        $stillHasDup = DB::table(self::TABLE)
            ->select('user_id', 'server_rate', 'record_at', 'record_type')
            ->selectRaw('COUNT(*) AS c')
            ->groupBy('user_id', 'server_rate', 'record_at', 'record_type')
            ->havingRaw('COUNT(*) > 1')
            ->limit(1)
            ->exists();
        if ($stillHasDup) {
            // 罕见：可能是 dedupe SQL 跑完后又有并发上报落入旧 INSERT 路径
            // 不 throw，下次 migrate 会再尝试；admin 也可手动暂停 horizon 后重跑
            Log::error('v2_stat_user dedupe 后仍存在重复行（可能是并发写入），本次跳过 ADD UNIQUE。下次 migrate 会再试。');
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
        // 仅 drop 新 unique；dedupe 删掉的行无法恢复
        if (Schema::hasTable(self::TABLE) && $this->indexExists(self::NEW_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $t) {
                $t->dropUnique(self::NEW_INDEX);
            });
        }
    }

    /**
     * 按 4 元组 dedupe，保留每组 MAX(id) 那一行，返回删除行数。
     * MySQL / MariaDB / Postgres / SQLite 各给一份 SQL，避免依赖 driver-specific 语法。
     */
    private function dedupeFullDuplicates(): int
    {
        $driver = DB::connection()->getDriverName();
        $table = self::TABLE;

        if ($driver === 'mysql' || $driver === 'mariadb') {
            return DB::affectingStatement(
                "DELETE s
                 FROM {$table} s
                 INNER JOIN (
                   SELECT user_id, server_rate, record_at, record_type, MAX(id) AS keep_id
                   FROM {$table}
                   GROUP BY user_id, server_rate, record_at, record_type
                   HAVING COUNT(*) > 1
                 ) k
                   ON s.user_id = k.user_id
                  AND s.server_rate = k.server_rate
                  AND s.record_at = k.record_at
                  AND s.record_type = k.record_type
                 WHERE s.id < k.keep_id"
            );
        }
        if ($driver === 'pgsql') {
            return DB::affectingStatement(
                "DELETE FROM {$table} s
                 USING (
                   SELECT user_id, server_rate, record_at, record_type, MAX(id) AS keep_id
                   FROM {$table}
                   GROUP BY user_id, server_rate, record_at, record_type
                   HAVING COUNT(*) > 1
                 ) k
                 WHERE s.user_id = k.user_id
                   AND s.server_rate = k.server_rate
                   AND s.record_at = k.record_at
                   AND s.record_type = k.record_type
                   AND s.id < k.keep_id"
            );
        }
        // SQLite 兜底：删除"不在每组 MAX(id) 集合里"的行。
        // 单组未重复时 MAX(id) = 那一行，仍在 IN 集合里，不会被删。
        return DB::affectingStatement(
            "DELETE FROM {$table}
             WHERE id NOT IN (
               SELECT MAX(id) FROM {$table}
               GROUP BY user_id, server_rate, record_at, record_type
             )"
        );
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
