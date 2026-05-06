<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\OrderService;
use App\Support\PaymentMetrics;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class OrderHandleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $order;
    protected $tradeNo;

    public $tries = 3;
    public $timeout = 5;

    public function __construct($tradeNo)
    {
        $this->onQueue('order_handle');
        $this->tradeNo = $tradeNo;
    }

    /**
     * 处理订单状态推进（取消过期 PENDING / 开通 PROCESSING）。
     *
     * 必须在 DB::transaction 内执行 lockForUpdate——MySQL autocommit 下行锁会立刻释放，
     * 旧实现的 lockForUpdate() 形同虚设。把整个 process 包入事务后行锁才真正持有，
     * 同一笔 trade_no 的并发推进（webhook 同步派发 + cron 自愈）不会冲突。
     */
    public function handle(): void
    {
        try {
            DB::transaction(function () {
                $order = Order::where('trade_no', $this->tradeNo)
                    ->lockForUpdate()
                    ->first();
                if (!$order) {
                    PaymentMetrics::inc('order.handle.missing');
                    return;
                }
                $this->process($order);
            });
        } catch (\Throwable $e) {
            PaymentMetrics::inc('order.handle.exception');
            throw $e; // 让 Horizon / 调用方记录失败并按 $tries 重试
        }
    }

    private function process(Order $order): void
    {
        $orderService = new OrderService($order);
        switch ($order->status) {
            case Order::STATUS_PENDING:
                if ($order->created_at <= (time() - 3600 * 2)) {
                    $orderService->cancel();
                }
                break;
            case Order::STATUS_PROCESSING:
                $orderService->open();
                break;
        }
    }
}
