<?php

namespace App\Services\AccessLog;

/**
 * 批次里每个用户的小计先攒在这里，access-log:flush 定时并入 v2_access_log_user_day。
 * 同一个 (用户, 天, 节点) 在 5 分钟里会被十几个批次加到，直接写库就是十几次 upsert。
 */
interface SubtotalBuffer
{
    /**
     * 按批次号只加一次：重复上传的批次返回 false，不再累加。
     *
     * @param array<int,array{0:int,1:int,2:int,3:int}> $users [user_id, records, up, down]
     */
    public function add(string $batchId, string $dt, int $hour, string $nodeType, int $nodeId, array $users): bool;

    /**
     * 取出目前攒下的全部小计交给 $write。$write 抛异常时这部分留到下次再交。
     *
     * @param callable(array<int,array{dt:string,node_type:string,node_id:int,user_id:int,records:int,up:int,down:int,hours:int}>):void $write
     * @return int 交出的行数
     */
    public function drain(callable $write): int;
}
