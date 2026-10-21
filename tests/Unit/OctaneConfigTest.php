<?php

namespace Tests\Unit;

use Laravel\Octane\Contracts\OperationTerminated;
use Laravel\Octane\Listeners\DisconnectFromDatabases;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OctaneConfigTest extends TestCase
{
    #[Test]
    public function swoole_workers_are_not_recycled_every_few_hundred_requests(): void
    {
        // 命令行默认 500：生产 48 个 worker、约 40 req/s 时每 11.7 秒就有一个 worker 重新 boot 框架
        $this->assertGreaterThanOrEqual(10000, (int) config('octane.swoole.options.max_request'));
        $this->assertGreaterThanOrEqual(10000, (int) config('octane.swoole.options.task_max_request'));
    }

    #[Test]
    public function database_connections_are_still_dropped_after_each_operation(): void
    {
        // 有意保留：代码里有手写的 DB::beginTransaction()，每请求重连保证未提交事务不会带进下一个请求。
        // 代价是每秒几十次新建连接（0.1 ms 一次），换的是「不出错」。
        $this->assertContains(DisconnectFromDatabases::class, config('octane.listeners')[OperationTerminated::class]);
    }
}
