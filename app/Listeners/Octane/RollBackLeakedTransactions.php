<?php

namespace App\Listeners\Octane;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 代替 Octane 的 DisconnectFromDatabases：请求 / 任务 / tick 结束后保留 MySQL 连接复用，
 * 只有发现连接上还挂着没提交的事务时才回滚并断开。
 *
 * 原来每个操作结束都断开所有连接，生产每秒约 50 次新建连接（握手 + 认证 + 建线程）。
 * 保留它的唯一理由是代码里有手写的 DB::beginTransaction()：异常路径漏掉 rollBack 时，
 * 未提交的事务和它持有的行锁会跟着常驻 worker 的连接进入下一个请求。
 * 这里正是兜住那种情况：回滚到第 0 层、断开连接、记一条 warning，下一个请求拿到全新的连接。
 * 没有泄漏的连接原样保留（Horizon 的队列 worker 一直就是这样长连接运行的）。
 */
class RollBackLeakedTransactions
{
    public function handle($event): void
    {
        foreach ($event->sandbox->make('db')->getConnections() as $name => $connection) {
            if (!$connection instanceof Connection || $connection->transactionLevel() === 0) {
                continue;
            }

            Log::warning('Octane: database transaction left open at end of operation, rolled back', [
                'connection' => $name,
                'level' => $connection->transactionLevel(),
                'event' => class_basename($event),
                'path' => $this->path($event),
            ]);

            try {
                $connection->rollBack(0);
            } catch (Throwable) {
                // 连接可能已经断了；下面的 disconnect 会把计数清零
            }
            $connection->disconnect();
        }
    }

    private function path($event): ?string
    {
        $request = $event->request ?? null;

        return is_object($request) && method_exists($request, 'path') ? $request->path() : null;
    }
}
