<?php


namespace App\Jobs;

use App\Models\StatUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StatUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected array $data;
    protected array $server;
    protected string $protocol;
    protected string $recordType;

    public $tries = 3;
    public $timeout = 60;
    public $maxExceptions = 3;

    /** 单条批量语句覆盖的用户数上限 */
    private const BATCH_SIZE = 500;

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        return [1, 5, 10];
    }

    /**
     * Create a new job instance.
     */
    public function __construct(array $server, array $data, string $protocol, string $recordType = 'd')
    {
        $this->onQueue('stat');
        $this->data = $data;
        $this->server = $server;
        $this->protocol = $protocol;
        $this->recordType = $recordType;
    }

    public function handle(): void
    {
        $recordAt = $this->recordType === 'm'
            ? strtotime(date('Y-m-01'))
            : strtotime(date('Y-m-d'));

        // MySQL 走批量路径：一次上报的整个 chunk 合并成 2~3 条语句，而不是每个用户一条。
        // 逐用户 UPDATE 时每条语句自成一个事务，binlog 里 408B 中有 272B（67%）是事务信封
        // （Anonymous_GTID + BEGIN + Table_map + Xid），真正的行变更只有 136B。
        // 实测 26,145 事务/分钟 → v2_stat_user 独占 14.3 GB/天 binlog。
        // 同一个 chunk 里 TrafficFetchJob 早已用 CASE WHEN 批量化（3,082 事务/分钟），
        // StatServerJob 每次推送只写 1 行（312 事务/分钟），只有这里还是逐用户。
        if (config('database.default') === 'mysql') {
            $this->processChunk($recordAt);
            return;
        }

        foreach ($this->data as $uid => $v) {
            try {
                $this->processUserStat($uid, $v, $recordAt);
            } catch (\Exception $e) {
                Log::error('StatUserJob failed for user ' . $uid . ': ' . $e->getMessage());
                throw $e;
            }
        }
    }

    /**
     * 批量累加整个 chunk 的用户统计（MySQL）。
     *
     * 同一个 chunk 共享 (server_rate, record_at, record_type)，只有 user_id 不同，
     * 因此可以先查出当天已存在的行做一条 CASE WHEN 批量 UPDATE，剩下的首次上报再走一次
     * 批量 upsert。upsert 仍然只覆盖真正缺失的行，保留「不让 upsert 空烧自增 id」的修复
     * （见 processUserStat 的注释与 2026-09-15 主键耗尽事故）。
     */
    protected function processChunk(int $recordAt): void
    {
        $rate = $this->server['rate'];

        $deltas = [];
        foreach ($this->data as $uid => $v) {
            $uid = (int) $uid;
            if ($uid <= 0) {
                continue;
            }
            $deltas[$uid] = [intval($v[0] * $rate), intval($v[1] * $rate)];
        }
        if (!$deltas) {
            return;
        }

        // 按 user_id 排序：并发的多个节点上报同一批用户时保持一致的加锁顺序，避免死锁。
        ksort($deltas);

        try {
            $existing = StatUser::query()
                ->where('server_rate', $rate)
                ->where('record_at', $recordAt)
                ->where('record_type', $this->recordType)
                ->whereIn('user_id', array_keys($deltas))
                ->toBase()
                ->pluck('user_id')
                ->all();
            $existing = array_flip(array_map('intval', $existing));

            $update = array_intersect_key($deltas, $existing);
            $insert = array_diff_key($deltas, $existing);

            // 单条语句最多覆盖 500 个用户：CASE WHEN 分支太多会让 SQL 文本和解析开销反超收益。
            foreach (array_chunk($update, self::BATCH_SIZE, true) as $slice) {
                $this->bulkIncrement($slice, $recordAt);
            }
            foreach (array_chunk($insert, self::BATCH_SIZE, true) as $slice) {
                $this->bulkUpsert($slice, $recordAt);
            }
        } catch (\Exception $e) {
            Log::error('StatUserJob batch failed for server ' . ($this->server['id'] ?? '?') . ': ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 一条 UPDATE 累加一批已存在的统计行。
     *
     * @param array<int, array{0:int,1:int}> $deltas
     */
    protected function bulkIncrement(array $deltas, int $recordAt): void
    {
        $caseU = '';
        $caseD = '';
        $bindings = [];
        foreach ($deltas as $uid => [$u, $d]) {
            $caseU .= ' WHEN ? THEN u + ?';
            $bindings[] = $uid;
            $bindings[] = $u;
        }
        foreach ($deltas as $uid => [$u, $d]) {
            $caseD .= ' WHEN ? THEN d + ?';
            $bindings[] = $uid;
            $bindings[] = $d;
        }
        $bindings[] = time();
        $bindings[] = $this->server['rate'];
        $bindings[] = $recordAt;
        $bindings[] = $this->recordType;
        $bindings = array_merge($bindings, array_keys($deltas));

        $table = (new StatUser())->getTable();
        $ids = implode(',', array_fill(0, count($deltas), '?'));
        $sql = "UPDATE {$table} SET "
            . "u = CASE user_id{$caseU} ELSE u END, "
            . "d = CASE user_id{$caseD} ELSE d END, "
            . "updated_at = ? "
            . "WHERE server_rate = ? AND record_at = ? AND record_type = ? AND user_id IN ({$ids})";

        DB::update($sql, $bindings);
    }

    /**
     * 批量插入当天首次上报的统计行；若同时被别的节点抢先建出来，按 ON DUPLICATE KEY 累加。
     *
     * @param array<int, array{0:int,1:int}> $deltas
     */
    protected function bulkUpsert(array $deltas, int $recordAt): void
    {
        $now = time();
        $rows = [];
        foreach ($deltas as $uid => [$u, $d]) {
            $rows[] = [
                'user_id' => $uid,
                'server_rate' => $this->server['rate'],
                'record_at' => $recordAt,
                'record_type' => $this->recordType,
                'u' => $u,
                'd' => $d,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        StatUser::upsert(
            $rows,
            ['user_id', 'server_rate', 'record_at', 'record_type'],
            [
                'u' => DB::raw('u + VALUES(u)'),
                'd' => DB::raw('d + VALUES(d)'),
                'updated_at' => $now,
            ]
        );
    }

    protected function processUserStat(int $uid, array $v, int $recordAt): void
    {
        $driver = config('database.default');
        if ($driver === 'sqlite') {
            $this->processUserStatForSqlite($uid, $v, $recordAt);
            return;
        }

        // 先 UPDATE 已有行，只有当天该 (用户, 倍率) 首次上报才走 upsert。
        // MySQL(innodb_autoinc_lock_mode=2) 的 INSERT ... ON DUPLICATE KEY UPDATE 与 PG 的
        // ON CONFLICT 每执行一次都会消耗一个自增 id（哪怕最终走的是更新）。实测每天烧 ~1600 万个，
        // 2026-09-15 把 int 主键烧到 2147483647 后，所有新行撞主键被累加到同一行上。
        if ($this->incrementExistingUserStat($uid, $v, $recordAt)) {
            return;
        }

        if ($driver === 'pgsql') {
            $this->processUserStatForPostgres($uid, $v, $recordAt);
        } else {
            $this->processUserStatForOtherDatabases($uid, $v, $recordAt);
        }
    }

    protected function incrementExistingUserStat(int $uid, array $v, int $recordAt): bool
    {
        $u = intval($v[0] * $this->server['rate']);
        $d = intval($v[1] * $this->server['rate']);

        // 返回的是"实际改动行数"：增量为 0 且同一秒内重复写时会返回 0，
        // 此时落到 upsert 只是多加一次 0，结果不变。
        $affected = StatUser::where([
            'user_id' => $uid,
            'server_rate' => $this->server['rate'],
            'record_at' => $recordAt,
            'record_type' => $this->recordType,
        ])->toBase()->update([
            'u' => DB::raw('u + ' . $u),
            'd' => DB::raw('d + ' . $d),
            'updated_at' => time(),
        ]);

        return $affected > 0;
    }

    protected function processUserStatForSqlite(int $uid, array $v, int $recordAt): void
    {
        DB::transaction(function () use ($uid, $v, $recordAt) {
            $existingRecord = StatUser::where([
                'user_id' => $uid,
                'server_rate' => $this->server['rate'],
                'record_at' => $recordAt,
                'record_type' => $this->recordType,
            ])->first();

            if ($existingRecord) {
                $existingRecord->update([
                    'u' => $existingRecord->u + intval($v[0] * $this->server['rate']),
                    'd' => $existingRecord->d + intval($v[1] * $this->server['rate']),
                    'updated_at' => time(),
                ]);
            } else {
                StatUser::create([
                    'user_id' => $uid,
                    'server_rate' => $this->server['rate'],
                    'record_at' => $recordAt,
                    'record_type' => $this->recordType,
                    'u' => intval($v[0] * $this->server['rate']),
                    'd' => intval($v[1] * $this->server['rate']),
                    'created_at' => time(),
                    'updated_at' => time(),
                ]);
            }
        }, 3);
    }

    protected function processUserStatForOtherDatabases(int $uid, array $v, int $recordAt): void
    {
        StatUser::upsert(
            [
                'user_id' => $uid,
                'server_rate' => $this->server['rate'],
                'record_at' => $recordAt,
                'record_type' => $this->recordType,
                'u' => intval($v[0] * $this->server['rate']),
                'd' => intval($v[1] * $this->server['rate']),
                'created_at' => time(),
                'updated_at' => time(),
            ],
            ['user_id', 'server_rate', 'record_at', 'record_type'],
            [
                'u' => DB::raw("u + VALUES(u)"),
                'd' => DB::raw("d + VALUES(d)"),
                'updated_at' => time(),
            ]
        );
    }

    /**
     * PostgreSQL upsert with arithmetic increments using ON CONFLICT ... DO UPDATE
     */
    protected function processUserStatForPostgres(int $uid, array $v, int $recordAt): void
    {
        $table = (new StatUser())->getTable();
        $now = time();
        $u = intval($v[0] * $this->server['rate']);
        $d = intval($v[1] * $this->server['rate']);

        // ON CONFLICT 必须包含 record_type，否则同 (user_id, server_rate, record_at) 下的
        // daily/monthly 行会撞库累加到一起，统计错位（见 2026_06_03_000003 迁移说明）。
        $sql = "INSERT INTO {$table} (user_id, server_rate, record_at, record_type, u, d, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (user_id, server_rate, record_at, record_type)
                DO UPDATE SET
                    u = {$table}.u + EXCLUDED.u,
                    d = {$table}.d + EXCLUDED.d,
                    updated_at = EXCLUDED.updated_at";

        DB::statement($sql, [
            $uid,
            $this->server['rate'],
            $recordAt,
            $this->recordType,
            $u,
            $d,
            $now,
            $now,
        ]);
    }
}