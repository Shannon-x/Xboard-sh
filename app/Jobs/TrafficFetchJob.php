<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class TrafficFetchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $data;
    protected $server;
    protected $protocol;
    protected $timestamp;
    public $tries = 1;
    public $timeout = 20;

    public function __construct(array $server, array $data, $protocol, int $timestamp)
    {
        $this->onQueue('traffic_fetch');
        $this->server = $server;
        $this->data = $data;
        $this->protocol = $protocol;
        $this->timestamp = $timestamp;
    }

    public function handle(): void
    {
        if (empty($this->data)) {
            return;
        }

        // rate 可能是 float（数据库 decimal cast），但 u/d 是 bigint。
        // 这里乘完用 intval 截到整数；同时 max(0, ...) 兜底，即便上游清洗漏过负数也不会让累计倒扣。
        $rate = (float) $this->server['rate'];
        $now = time();
        $rows = [];
        foreach ($this->data as $uid => $v) {
            $uid = (int) $uid;
            if ($uid <= 0) {
                continue;
            }
            $u = (int) max(0, ((int) $v[0]) * $rate);
            $d = (int) max(0, ((int) $v[1]) * $rate);
            // 即便 rate=0 或上报全 0，也保留行以更新 t（last seen），与历史 incrementEach({...},['t'=>now]) 行为一致
            $rows[$uid] = [$u, $d];
        }

        if (empty($rows)) {
            return;
        }

        $userIds = array_keys($rows);

        // 单条 CASE WHEN UPDATE 覆盖整个 chunk，避免历史上每个 user 一条 UPDATE 命中 v2_user 主键写锁与 binlog。
        //   节点 N × 在线 M × 1/push_interval QPS 全部命中同一主键，10×5000×1/60 ≈ 833 UPS 落单条 SQL。
        // 这里改成一次 SQL：UPDATE ... SET u = CASE id WHEN ? THEN u + ? ... END, ...
        $caseU = '';
        $caseD = '';
        $bindings = [];
        foreach ($rows as $uid => [$u, $d]) {
            $caseU .= ' WHEN ? THEN u + ?';
            $caseD .= ' WHEN ? THEN d + ?';
            $bindings[] = $uid; $bindings[] = $u;       // for caseU
        }
        // 第二段绑定（caseD）追加，必须与 caseD 占位顺序一致
        foreach ($rows as $uid => [$u, $d]) {
            $bindings[] = $uid; $bindings[] = $d;
        }
        $bindings[] = $now;                              // t = ?
        $bindings = array_merge($bindings, $userIds);    // WHERE id IN (?,?,...)

        $idPlaceholders = implode(',', array_fill(0, count($userIds), '?'));
        $sql = "UPDATE v2_user SET "
            . "u = CASE id{$caseU} ELSE u END, "
            . "d = CASE id{$caseD} ELSE d END, "
            . "t = ? "
            . "WHERE id IN ({$idPlaceholders})";

        DB::update($sql, $bindings);

        Redis::sadd('traffic:pending_check', ...$userIds);
    }
}
