<?php

namespace Tests\Feature;

use App\Listeners\Octane\RollBackLeakedTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Octane\Events\RequestTerminated;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * 去掉 DisconnectFromDatabases 之后的安全网：干净的连接跨请求复用，
 * 漏提交的事务在操作结束时被回滚、连接被换掉，不会把未提交的写和行锁带进下一个请求。
 */
class OctaneTransactionLeakTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'xb-leak-') . '.sqlite';
        touch($this->file);
        config(['database.connections.leak_probe' => [
            'driver' => 'sqlite',
            'database' => $this->file,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);
        DB::connection('leak_probe')->statement('CREATE TABLE probe (id INTEGER PRIMARY KEY, v TEXT)');
    }

    protected function tearDown(): void
    {
        DB::purge('leak_probe');
        @unlink($this->file);
        parent::tearDown();
    }

    private function terminate(string $path = '/api/v1/user/order/save'): void
    {
        $event = new RequestTerminated($this->app, $this->app, Request::create($path, 'POST'), new Response());
        (new RollBackLeakedTransactions())->handle($event);
    }

    public function test_a_clean_connection_survives_the_end_of_a_request(): void
    {
        $db = DB::connection('leak_probe');
        $db->table('probe')->insert(['v' => 'committed']);
        $pdo = $db->getPdo();

        Log::shouldReceive('warning')->never();
        $this->terminate();

        $this->assertSame($pdo, DB::connection('leak_probe')->getPdo(), '没有泄漏时连接应原样复用');
        $this->assertSame(1, DB::connection('leak_probe')->table('probe')->count());
    }

    public function test_a_leaked_manual_transaction_is_rolled_back_and_the_connection_replaced(): void
    {
        $db = DB::connection('leak_probe');
        $db->beginTransaction();
        $db->beginTransaction(); // 嵌套一层（savepoint），也要整体回滚
        $db->table('probe')->insert(['v' => 'never committed']);
        $pdo = $db->getPdo();

        Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context) {
            return str_contains($message, 'rolled back')
                && $context['connection'] === 'leak_probe'
                && $context['level'] === 2
                && $context['path'] === 'api/v1/user/order/save';
        });
        $this->terminate();

        $next = DB::connection('leak_probe');
        $this->assertSame(0, $next->transactionLevel());
        $this->assertNotSame($pdo, $next->getPdo(), '泄漏事务的连接必须换掉');
        $this->assertSame(0, $next->table('probe')->count(), '未提交的写不能留下来');
    }
}
