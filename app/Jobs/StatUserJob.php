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

        foreach ($this->data as $uid => $v) {
            try {
                $this->processUserStat($uid, $v, $recordAt);
            } catch (\Exception $e) {
                Log::error('StatUserJob failed for user ' . $uid . ': ' . $e->getMessage());
                throw $e;
            }
        }
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