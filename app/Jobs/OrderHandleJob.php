<?php

namespace App\Jobs;

use App\Exceptions\OrderNeedsManualReview;
use App\Models\Order;
use App\Services\OrderService;
use App\Support\AdminAlert;
use App\Support\PaymentMetrics;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OrderHandleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $order;
    protected $tradeNo;

    /** 待支付订单超过这么多秒仍未支付就取消；check:order 用同一阈值筛选要派发的订单 */
    public const PENDING_CANCEL_AFTER = 3600 * 2;

    /** 同一张「需人工处理」的订单多久提醒管理员一次（秒）；check:order 每分钟都会再派一次 */
    public const MANUAL_REVIEW_ALERT_EVERY = 86400;

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
        } catch (OrderNeedsManualReview $e) {
            // 重试不会自己好（线路权限变了、折抵源被占……），抛出去只会被 check:order 每分钟重派、
            // 每次失败三遍刷进 failed_jobs。事务已回滚、订单仍是「开通中」：告警给人看，
            // 条件恢复后（如管理员补回线路权限）下一轮 check:order 照常自动开通。
            $this->flagForManualReview($e);
        } catch (\Throwable $e) {
            PaymentMetrics::inc('order.handle.exception');
            throw $e; // 让 Horizon / 调用方记录失败并按 $tries 重试
        }
    }

    private function flagForManualReview(OrderNeedsManualReview $e): void
    {
        $order = Order::where('trade_no', $this->tradeNo)->first();
        PaymentMetrics::warn('order.open.manual_review', [
            'trade_no' => (string) $this->tradeNo,
            'order_id' => $order?->id,
            'user_id' => $order?->user_id,
            'reason' => $e->getMessage(),
        ]);
        if (!Cache::add('order_manual_review_alert:' . $this->tradeNo, 1, self::MANUAL_REVIEW_ALERT_EVERY)) {
            return;
        }
        $amount = $order ? number_format(((int) $order->total_amount + (int) ($order->balance_amount ?? 0)) / 100, 2) : '-';
        AdminAlert::send("⚠️ 已付款订单无法自动开通，需人工处理\n"
            . "订单号：{$this->tradeNo}\n"
            . '用户ID：' . ($order->user_id ?? '-') . "\n"
            . "金额：¥{$amount}\n"
            . "原因：{$e->getMessage()}\n"
            . '订单保持「开通中」，条件恢复后会自动开通；24 小时内不再重复提醒');
    }

    private function process(Order $order): void
    {
        $orderService = new OrderService($order);
        switch ($order->status) {
            case Order::STATUS_PENDING:
                if ($order->created_at <= (time() - self::PENDING_CANCEL_AFTER)) {
                    $orderService->cancel();
                }
                break;
            case Order::STATUS_PROCESSING:
                $orderService->open();
                break;
        }
    }
}
