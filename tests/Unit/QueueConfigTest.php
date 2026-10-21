<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/** 队列配置之间的硬约束，改任一处时由这里把关。 */
class QueueConfigTest extends TestCase
{
    /** @return array<string, int> 任务类 → $timeout */
    private function jobTimeouts(): array
    {
        $timeouts = [];
        foreach (glob(app_path('Jobs/*.php')) as $file) {
            $class = 'App\\Jobs\\' . basename($file, '.php');
            $defaults = (new ReflectionClass($class))->getDefaultProperties();
            $timeouts[$class] = (int) ($defaults['timeout'] ?? 60);
        }
        return $timeouts;
    }

    #[Test]
    public function redis_retry_after_exceeds_every_job_timeout(): void
    {
        $retryAfter = (int) config('queue.connections.redis.retry_after');
        $timeouts = $this->jobTimeouts();
        $this->assertNotEmpty($timeouts);
        foreach ($timeouts as $class => $timeout) {
            $this->assertGreaterThan($timeout, $retryAfter, "{$class} 的 timeout={$timeout} 不小于 retry_after={$retryAfter}：任务还在跑就会被重新投递");
        }
        foreach (config('horizon.environments') as $env => $supervisors) {
            foreach ($supervisors as $name => $options) {
                $this->assertGreaterThan((int) ($options['timeout'] ?? 60), $retryAfter, "horizon {$env}.{$name} 的 timeout 不小于 retry_after");
            }
        }
    }

    #[Test]
    public function blocking_pop_is_only_used_with_per_queue_process_pools(): void
    {
        if (config('queue.connections.redis.block_for') === null) {
            $this->markTestSkipped('block_for 未启用');
        }
        // block_for 让 worker 在它列表里的第一个队列上阻塞等待；一个进程守多个队列（balance=false）
        // 时后面的队列会挨饿。Horizon 的 auto / simple 给每个队列独立进程池，才可以阻塞。
        foreach (config('horizon.environments') as $env => $supervisors) {
            foreach ($supervisors as $name => $options) {
                if (count((array) $options['queue']) > 1) {
                    $this->assertContains($options['balance'] ?? false, ['auto', 'simple'], "horizon {$env}.{$name} 守多个队列却没有按队列分池，block_for 会让后面的队列挨饿");
                }
            }
        }
    }

    #[Test]
    public function every_queue_a_job_uses_has_a_production_supervisor_and_a_wait_threshold(): void
    {
        $queues = [];
        foreach (glob(app_path('Jobs/*.php')) as $file) {
            if (preg_match_all("/onQueue\\('([a-z_]+)'\\)/", file_get_contents($file), $m)) {
                $queues = array_merge($queues, $m[1]);
            }
        }
        $queues = array_unique(array_merge($queues, ['default']));
        $served = [];
        foreach (config('horizon.environments.production') as $options) {
            $served = array_merge($served, (array) $options['queue']);
        }
        foreach ($queues as $queue) {
            $this->assertContains($queue, $served, "队列 {$queue} 没有生产 supervisor 消费");
            $this->assertArrayHasKey("redis:{$queue}", config('horizon.waits'), "队列 {$queue} 没有 waits 阈值");
        }
    }
}
