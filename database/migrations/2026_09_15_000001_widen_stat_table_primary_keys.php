<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 统计表主键 int → bigint。
 *
 * 2026-09-15 生产 v2_stat_user 的自增计数器到达 2147483647（表里只有 76 万行）：
 * StatUserJob 的 INSERT ... ON DUPLICATE KEY UPDATE 每次执行都消耗一个 id，每天约 1600 万。
 * 计数器到顶后每条新 INSERT 都拿到同一个 id，撞 PRIMARY KEY 走了 UPDATE 分支，
 * 全站当天新增的统计流量被静默累加到 id=2147483647 那一行所属的用户身上，并伴随行锁排队、stat 队列积压。
 *
 * 代码侧已改为"先 UPDATE、无行再 upsert"，id 消耗降到每天新增行数级别；这里再把三张高频统计表的
 * 主键放宽到 bigint 作为兜底。已经是 bigint 的跳过，幂等。
 *
 * 注意：MySQL 上是整表重建（COPY），期间写入会等待元数据锁。大表建议先停 horizon 再跑，
 * 或者先手工执行同样的 ALTER，本迁移检测到已是 bigint 会直接跳过。
 */
return new class extends Migration {
    private const TABLES = ['v2_stat_user', 'v2_stat_server', 'v2_stat'];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        foreach (self::TABLES as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'id')) {
                continue;
            }

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $type = DB::selectOne(
                    'SELECT DATA_TYPE AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$table, 'id']
                )?->t;
                if ($type !== null && strtolower($type) !== 'bigint') {
                    DB::statement("ALTER TABLE `{$table}` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT");
                }
            } elseif ($driver === 'pgsql') {
                $type = DB::selectOne(
                    'SELECT data_type AS t FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
                    [$table, 'id']
                )?->t;
                if ($type !== null && $type !== 'bigint') {
                    DB::statement("ALTER TABLE \"{$table}\" ALTER COLUMN id TYPE BIGINT");
                }
                $sequence = DB::selectOne('SELECT pg_get_serial_sequence(?, ?) AS s', [$table, 'id'])?->s;
                if ($sequence) {
                    DB::statement("ALTER SEQUENCE {$sequence} AS BIGINT");
                }
            }
            // sqlite: INTEGER PRIMARY KEY 已是 64 位，无需处理
        }
    }

    public function down(): void
    {
        // 不回缩：计数器可能已经超过 int 上限，缩回去会直接让写入失败。
    }
};
