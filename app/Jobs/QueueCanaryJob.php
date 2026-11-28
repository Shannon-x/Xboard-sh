<?php

namespace App\Jobs;

use App\Services\Monitor\QueueHealth;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * 队列金丝雀：什么也不干，只记下自己排了多久（见 QueueHealth）。
 * 它被执行了，就说明这条队列此刻真的有 worker 在取任务。
 */
class QueueCanaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 1;
    public $timeout = 10;

    public function __construct(public string $lane, public float $dispatchedAt)
    {
        $this->onQueue($lane);
    }

    public function handle(): void
    {
        QueueHealth::canaryRan($this->lane, $this->dispatchedAt);
    }
}
