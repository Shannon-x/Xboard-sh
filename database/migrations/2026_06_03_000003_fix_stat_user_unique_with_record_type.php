<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 修正 v2_stat_user 的 unique 约束：把 record_type 也纳入唯一键。
 *
 * 历史背景：
 *   - 2023_03_19 初版 migration 给 v2_stat_user 建过 unique (server_rate, user_id, record_at)
 *   - 但**很多老库（v2board 升级、二次 fork）的 v2_stat_user 表早于 2023_03_19 就存在**，
 *     Schema::hasTable 判断为已存在跳过 create，所以那个 unique **从未真正生效**
 *   - StatUserJob 的 upsert ON DUPLICATE KEY UPDATE 因此一直退化为纯 INSERT，
 *     生产库里累积了大量"同 (user_id, server_rate, record_at, record_type) 4 元组完全重复"的脏数据
 *
 * 本迁移设计为只做"清干净"的库的 add unique：
 *   - 若已存在新 unique：直接 return（幂等）
 *   - 若检测到 4 元组完全重复：**不 throw 不 ALTER**，log 后 return，
 *     让下一个迁移 2026_06_03_000004 做 dedup + add unique
 *   - 否则就在表已经干净时直接换 unique，与原始意图一致
 *
 * ⚠️ 原始版本的 safety check 只查"3 元组下多 record_type"这种合法情况（daily 与 monthly
 * 同时存在本来就合法），漏掉了真正的"4 元组整组重复"脏数据，结果在 user 的库上撞 1062。
 * 这里把 check 改成检测真正会撞 ADD UNIQUE 的形态。
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

        // 检查是否存在"4 元组完全重复"的脏数据 —— 这种行会让 ADD UNIQUE 直接撞 1062。
        // 原 check 只看 3 元组下多 record_type（这种是 daily/monthly 共存的合法情况），
        // 漏检本场景。
        $hasDup = DB::table(self::TABLE)
            ->select('user_id', 'server_rate', 'record_at', 'record_type')
            ->selectRaw('COUNT(*) AS c')
            ->groupBy('user_id', 'server_rate', 'record_at', 'record_type')
            ->havingRaw('COUNT(*) > 1')
            ->limit(1)
            ->exists();

        if ($hasDup) {
            Log::warning('v2_stat_user 存在 4 元组完全重复的脏数据，本迁移跳过 ADD UNIQUE，将由 2026_06_03_000004 做 dedup 后处理。');
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
