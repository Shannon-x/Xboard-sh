<?php

namespace Tests\Unit;

use Laravel\Octane\Contracts\OperationTerminated;
use App\Listeners\Octane\RollBackLeakedTransactions;
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
    public function requests_go_to_idle_workers_instead_of_being_pinned_to_a_connection(): void
    {
        // 中间件只有几条 keep-alive 长连接；按连接固定分配（默认 2）会让 48 个 worker 里只有几个在干活
        $this->assertSame(3, config('octane.swoole.options.dispatch_mode'));
    }

    #[Test]
    public function database_connections_are_reused_but_leaked_transactions_are_rolled_back(): void
    {
        // 每请求断开重连是生产每秒约 50 次新建连接的来源；现在复用连接，
        // 只有连接上还挂着未提交事务（手写 beginTransaction 漏了 rollBack）时才回滚并断开。
        $listeners = config('octane.listeners')[OperationTerminated::class];
        $this->assertNotContains(DisconnectFromDatabases::class, $listeners);
        $this->assertContains(RollBackLeakedTransactions::class, $listeners);
    }
}
