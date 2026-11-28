<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * 一次节点上报 = 一个队列任务。
 *
 * 原先上报请求里按 1,000 个用户一块，每块派发 TrafficFetchJob + StatUserJob，最后再派发一个
 * StatServerJob。派发到一半 Redis 出错时，请求回 500，但前面几块已经进了队列：节点按失败处理，
 * 要么整批重发（已入队的那几块记两遍），要么整批丢掉（没入队的那几块漏记）。现在请求里只派发
 * 这一个任务，要么整份上报进队列，要么什么都没进。
 *
 * 拆块和派发挪到这里，在 worker 里做。中途失败时本任务重试，重新派发全部子任务；子任务的回执键
 * 由本任务的 uuid、任务类和块号决定（见 HasReceiptKey），已经落库的那一份不会再记一遍。
 * 子任务的队列、拆块大小、数据和原来完全一样。
 */
class TrafficReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 每块用户数，与原先 UserService::trafficFetch 的拆法一致 */
    public const CHUNK = 1000;

    /** 子任务回执键的命名空间（UUID v5），固定不变：改了会让重试无法认出已经落库的子任务 */
    private const RECEIPT_NAMESPACE = 'b0f0a7f4-6a43-4f53-9a62-5d0a8c3f1e27';

    protected array $server;
    protected array $data;
    protected string $protocol;
    protected int $timestamp;

    public $tries = 5;
    public $timeout = 30;

    public function backoff(): array
    {
        return [1, 5, 10, 30];
    }

    public function __construct(array $server, array $data, string $protocol, int $timestamp)
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
        // 没有队列上下文（测试里直接 handle()）时没有 uuid：每次执行都算一次新的上报。
        $parent = $this->job?->uuid() ?: (string) Str::uuid();

        $totalU = 0;
        $totalD = 0;
        foreach (array_chunk($this->data, self::CHUNK, true) as $index => $chunk) {
            Bus::dispatch((new TrafficFetchJob($this->server, $chunk, $this->protocol, $this->timestamp))
                ->withReceiptKey(self::receiptKey($parent, 'traffic_fetch', $index)));
            Bus::dispatch((new StatUserJob($this->server, $chunk, $this->protocol, 'd'))
                ->withReceiptKey(self::receiptKey($parent, 'stat_user', $index)));
            foreach ($chunk as $traffic) {
                $totalU += (int) $traffic[0];
                $totalD += (int) $traffic[1];
            }
        }
        // StatServerJob 只累加 $traffic[0] / [1]、不乘倍率（与原来逐块求和的结果完全一致），直接给它总和
        Bus::dispatch((new StatServerJob($this->server, [[$totalU, $totalD]], $this->protocol, 'd'))
            ->withReceiptKey(self::receiptKey($parent, 'stat_server', 0)));
    }

    public static function receiptKey(string $parent, string $job, int $index): string
    {
        return Uuid::uuid5(self::RECEIPT_NAMESPACE, "{$parent}:{$job}:{$index}")->toString();
    }
}
