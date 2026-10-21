<?php

namespace Tests\Feature;

use App\Jobs\StatServerJob;
use App\Jobs\StatUserJob;
use App\Jobs\TrafficFetchJob;
use App\Models\Server;
use App\Services\UserService;
use App\Support\JobReceipt;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 节点上报 → 队列任务的形状（每块 2 个 + 整次 1 个 StatServerJob，payload 只带 id/rate），
 * 以及 JobReceipt 让重试恰好一次。
 */
class TrafficPipelineDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::shouldReceive('sadd')->andReturn(1)->byDefault();
    }

    private function node(float $rate = 2): Server
    {
        return Server::create([
            'type' => 'vless', 'name' => 'n', 'rate' => $rate, 'group_ids' => ['1'], 'show' => true,
            'host' => 'n.example.test', 'port' => '443', 'server_port' => 443,
            'protocol_settings' => ['network' => 'tcp', 'tls' => 1, 'tls_settings' => ['server_name' => 'n.example.test']],
            'tags' => ['IEPL', '家宽'], 'route_ids' => [1, 2],
        ]);
    }

    private function report(int $users): array
    {
        $data = [];
        for ($uid = 1; $uid <= $users; $uid++) {
            $data[$uid] = [$uid, $uid * 2];
        }
        return $data;
    }

    private function userRow(int $uid): array
    {
        return ['id' => $uid, 'email' => "u{$uid}@example.test", 'password' => 'x', 'uuid' => "uuid-{$uid}", 'token' => "token-{$uid}",
            'u' => 0, 'd' => 0, 't' => 0, 'created_at' => time(), 'updated_at' => time()];
    }

    /** 读任务的 protected 属性 */
    private function prop(object $job, string $name): mixed
    {
        return (fn () => $this->{$name})->call($job);
    }

    #[Test]
    public function one_report_dispatches_two_jobs_per_chunk_and_a_single_summed_server_stat_job(): void
    {
        Queue::fake();
        $node = $this->node(2);
        $data = $this->report(2500);

        (new UserService())->trafficFetch($node, 'vless', $data);

        Queue::assertPushedOn('traffic_fetch', TrafficFetchJob::class);
        Queue::assertPushed(TrafficFetchJob::class, 3);
        Queue::assertPushedOn('stat', StatUserJob::class);
        Queue::assertPushed(StatUserJob::class, 3);
        Queue::assertPushedOn('stat', StatServerJob::class);
        Queue::assertPushed(StatServerJob::class, 1);

        $expectU = array_sum(array_column($data, 0));
        $expectD = array_sum(array_column($data, 1));
        Queue::assertPushed(StatServerJob::class, function (StatServerJob $job) use ($expectU, $expectD, $node) {
            $this->assertSame([[$expectU, $expectD]], $this->prop($job, 'data'));
            $this->assertSame(['id', 'rate'], array_keys($this->prop($job, 'server')));
            $this->assertSame($node->id, $this->prop($job, 'server')['id']);
            $this->assertEquals(2, $this->prop($job, 'server')['rate']);
            return true;
        });
        // 分块保留 uid 作为键，且三块拼起来就是整份上报
        $seen = [];
        Queue::assertPushed(TrafficFetchJob::class, function (TrafficFetchJob $job) use (&$seen, $node) {
            $this->assertSame(['id', 'rate'], array_keys($this->prop($job, 'server')));
            $this->assertSame($node->id, $this->prop($job, 'server')['id']);
            $this->assertLessThanOrEqual(1000, count($this->prop($job, 'data')));
            $seen += $this->prop($job, 'data');
            return true;
        });
        $this->assertSame($data, $seen);
    }

    #[Test]
    public function rate_rewritten_by_the_traffic_hook_reaches_the_jobs(): void
    {
        Queue::fake();
        \App\Services\Plugin\HookManager::registerFilter('traffic.process.before', function (array $payload) {
            [$server, $protocol, $data] = $payload;
            $server['rate'] = 7.5;
            return [$server, $protocol, $data];
        });
        (new UserService())->trafficFetch($this->node(2), 'vless', $this->report(3));
        Queue::assertPushed(StatUserJob::class, fn (StatUserJob $job) => $this->prop($job, 'server')['rate'] === 7.5);
        \App\Services\Plugin\HookManager::reset();
    }

    #[Test]
    public function empty_report_dispatches_nothing(): void
    {
        Queue::fake();
        (new UserService())->trafficFetch($this->node(), 'vless', []);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function sync_run_writes_the_same_totals_as_the_old_per_chunk_server_jobs(): void
    {
        // QUEUE_CONNECTION=sync：dispatch 立即执行，三类任务真的落库
        $node = $this->node(2);
        $data = $this->report(1200);
        foreach (array_chunk(array_keys($data), 500) as $ids) {
            DB::table('v2_user')->insert(array_map(fn ($uid) => $this->userRow($uid), $ids));
        }

        (new UserService())->trafficFetch($node, 'vless', $data);

        $expectU = array_sum(array_column($data, 0));
        $expectD = array_sum(array_column($data, 1));
        $server = DB::table('v2_stat_server')->where('server_id', $node->id)->sole();
        $this->assertSame([$expectU, $expectD], [(int) $server->u, (int) $server->d]);
        $this->assertSame(1, DB::table('v2_stat_server')->count());
        $this->assertSame([$expectU * 2, $expectD * 2], [(int) DB::table('v2_user')->sum('u'), (int) DB::table('v2_user')->sum('d')]);
        $this->assertSame(1200, DB::table('v2_stat_user')->count());
        $this->assertSame([$expectU * 2, $expectD * 2], [(int) DB::table('v2_stat_user')->sum('u'), (int) DB::table('v2_stat_user')->sum('d')]);
        // sync 任务也有 uuid：每个任务一张回执
        $this->assertSame(5, DB::table(JobReceipt::TABLE)->count());
    }

    private function queuedJob(string $uuid): Job
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('uuid')->andReturn($uuid);
        $job->shouldIgnoreMissing();
        return $job;
    }

    #[Test]
    public function a_retry_of_a_committed_traffic_job_does_not_count_the_traffic_twice(): void
    {
        DB::table('v2_user')->insert($this->userRow(1));
        $server = ['id' => 1, 'rate' => 1];
        $ts = strtotime(date('Y-m-d'));

        $first = new TrafficFetchJob($server, [1 => [100, 200]], 'vless', $ts);
        $first->setJob($this->queuedJob('11111111-1111-1111-1111-111111111111'));
        $first->handle();
        // Horizon 按 retry_after 重新投递的是同一份 payload：uuid 相同
        $retry = new TrafficFetchJob($server, [1 => [100, 200]], 'vless', $ts);
        $retry->setJob($this->queuedJob('11111111-1111-1111-1111-111111111111'));
        $retry->handle();
        // 另一次上报（新 uuid）照常累加
        $next = new TrafficFetchJob($server, [1 => [1, 1]], 'vless', $ts);
        $next->setJob($this->queuedJob('22222222-2222-2222-2222-222222222222'));
        $next->handle();

        $user = DB::table('v2_user')->where('id', 1)->sole();
        $this->assertSame([101, 201], [(int) $user->u, (int) $user->d]);
        $this->assertSame(2, DB::table(JobReceipt::TABLE)->count());
    }

    #[Test]
    public function stat_jobs_are_also_applied_exactly_once_per_payload(): void
    {
        $server = ['id' => 3, 'rate' => 1];
        for ($i = 0; $i < 2; $i++) {
            $job = new StatUserJob($server, [5 => [10, 20]], 'vless');
            $job->setJob($this->queuedJob('33333333-3333-3333-3333-333333333333'));
            $job->handle();
            $job = new StatServerJob($server, [[10, 20]], 'vless');
            $job->setJob($this->queuedJob('44444444-4444-4444-4444-444444444444'));
            $job->handle();
        }
        $this->assertSame([10, 20], [(int) DB::table('v2_stat_user')->sum('u'), (int) DB::table('v2_stat_user')->sum('d')]);
        $this->assertSame([10, 20], [(int) DB::table('v2_stat_server')->sum('u'), (int) DB::table('v2_stat_server')->sum('d')]);
    }

    #[Test]
    public function a_failed_attempt_leaves_no_receipt_so_the_retry_runs(): void
    {
        $job = $this->queuedJob('55555555-5555-5555-5555-555555555555');
        $calls = 0;
        try {
            JobReceipt::applyOnce($job, 'test', function () use (&$calls) {
                $calls++;
                throw new \RuntimeException('deadlock');
            });
            $this->fail('exception should propagate');
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, DB::table(JobReceipt::TABLE)->count());
        $this->assertTrue(JobReceipt::applyOnce($job, 'test', function () use (&$calls) { $calls++; }));
        $this->assertFalse(JobReceipt::applyOnce($job, 'test', function () use (&$calls) { $calls++; }));
        $this->assertSame(2, $calls);
        $this->assertSame(1, DB::table(JobReceipt::TABLE)->count());
    }

    #[Test]
    public function without_a_queue_context_the_work_simply_runs(): void
    {
        $calls = 0;
        $this->assertTrue(JobReceipt::applyOnce(null, 'test', function () use (&$calls) { $calls++; }));
        $this->assertTrue(JobReceipt::applyOnce(null, 'test', function () use (&$calls) { $calls++; }));
        $this->assertSame(2, $calls);
        $this->assertSame(0, DB::table(JobReceipt::TABLE)->count());
    }

    #[Test]
    public function prune_keeps_recent_receipts_and_drops_old_ones(): void
    {
        DB::table(JobReceipt::TABLE)->insert([
            ['job_uuid' => 'old', 'job' => 't', 'created_at' => time() - (JobReceipt::RETENTION_DAYS + 1) * 86400],
            ['job_uuid' => 'new', 'job' => 't', 'created_at' => time() - 60],
        ]);
        $this->assertSame(1, JobReceipt::prune());
        $this->assertSame(['new'], DB::table(JobReceipt::TABLE)->pluck('job_uuid')->all());
    }
}
