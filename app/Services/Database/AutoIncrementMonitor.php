<?php

namespace App\Services\Database;

use Illuminate\Support\Facades\DB;

/**
 * 盘点当前库所有自增主键 / 序列离类型上限还有多远。
 *
 * 背景：2026-09-15 v2_stat_user 的 int 主键被 upsert 烧到 2147483647，之后所有新统计行撞主键、
 * 被 ON DUPLICATE KEY UPDATE 静默累加到同一行上——没有任何报错，只有一个用户"用了 4TB"。
 * 这类问题只会在上限那一刻暴露，所以要提前按百分比告警。
 */
class AutoIncrementMonitor
{
    private const MAX = [
        'tinyint' => [127, 255],
        'smallint' => [32767, 65535],
        'mediumint' => [8388607, 16777215],
        'int' => [2147483647, 4294967295],
        'bigint' => [9223372036854775807, 18446744073709551615],
    ];

    /**
     * @return array<int, array{table: string, column: string, type: string, next: int|float, max: int|float, ratio: float}>
     */
    public function scan(): array
    {
        $driver = DB::connection()->getDriverName();

        $rows = match ($driver) {
            'mysql', 'mariadb' => $this->scanMysql(),
            'pgsql' => $this->scanPostgres(),
            default => [], // sqlite 的 INTEGER PRIMARY KEY 本来就是 64 位
        };

        usort($rows, fn ($a, $b) => $b['ratio'] <=> $a['ratio']);

        return $rows;
    }

    private function scanMysql(): array
    {
        // MySQL 8 默认把 information_schema.TABLES.AUTO_INCREMENT 缓存 24h，不关掉会读到旧值。
        try {
            DB::statement('SET SESSION information_schema_stats_expiry = 0');
        } catch (\Throwable) {
            // MariaDB 没有这个变量，它的值本来就是实时的
        }

        $columns = DB::select(
            "SELECT c.TABLE_NAME AS table_name, c.COLUMN_NAME AS column_name, c.DATA_TYPE AS data_type,
                    c.COLUMN_TYPE AS column_type, t.AUTO_INCREMENT AS next_value
             FROM information_schema.COLUMNS c
             JOIN information_schema.TABLES t
               ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
             WHERE c.TABLE_SCHEMA = DATABASE() AND c.EXTRA LIKE '%auto_increment%'"
        );

        $rows = [];
        foreach ($columns as $col) {
            $type = strtolower($col->data_type);
            if (!isset(self::MAX[$type])) {
                continue;
            }
            $max = self::MAX[$type][str_contains(strtolower($col->column_type), 'unsigned') ? 1 : 0];
            // 计数器可能落后于真实数据（缓存/重启后重算），取两者较大值
            $maxId = DB::table($col->table_name)->max($col->column_name);
            $next = max((float) ($col->next_value ?? 0), (float) ($maxId ?? 0) + 1);

            $rows[] = $this->row($col->table_name, $col->column_name, $col->column_type, $next, $max);
        }

        return $rows;
    }

    private function scanPostgres(): array
    {
        $sequences = DB::select(
            "SELECT d.refobjid::regclass::text AS table_name, a.attname AS column_name,
                    s.data_type::text AS data_type, COALESCE(s.last_value, 0) AS last_value, s.max_value
             FROM pg_depend d
             JOIN pg_class seq ON seq.oid = d.objid AND seq.relkind = 'S'
             JOIN pg_sequences s ON s.schemaname = current_schema() AND s.sequencename = seq.relname
             JOIN pg_attribute a ON a.attrelid = d.refobjid AND a.attnum = d.refobjsubid
             WHERE d.deptype IN ('a', 'i')"
        );

        $rows = [];
        foreach ($sequences as $seq) {
            // 序列本身是 bigint 但列是 int 时，上限按列算
            $colType = DB::selectOne(
                'SELECT data_type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
                [$seq->table_name, $seq->column_name]
            )?->data_type;
            $max = match ($colType) {
                'smallint' => 32767,
                'integer' => 2147483647,
                default => (float) $seq->max_value,
            };
            $max = min($max, (float) $seq->max_value);

            $rows[] = $this->row($seq->table_name, $seq->column_name, (string) $colType, (float) $seq->last_value + 1, $max);
        }

        return $rows;
    }

    private function row(string $table, string $column, string $type, int|float $next, int|float $max): array
    {
        return [
            'table' => $table,
            'column' => $column,
            'type' => $type,
            'next' => $next,
            'max' => $max,
            'ratio' => $max > 0 ? $next / $max : 0.0,
        ];
    }
}
