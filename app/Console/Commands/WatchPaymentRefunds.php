<?php

namespace App\Console\Commands;

use App\Services\RefundWatchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 退款回查：已付的易支付订单 / 充值单按回查点再向网关查一次，用户通过投诉等途径退了款就
 * 自动封号、收回套餐并告警，管理员确认后删号（见 RefundWatchService）。Kernel 每 10 分钟跑一次。
 */
class WatchPaymentRefunds extends Command
{
    protected $signature = 'payment:refund-watch
        {--limit=150 : 单次最多回查多少笔}
        {--sleep-ms=200 : 两次网关查询之间的间隔（毫秒）}';

    protected $description = '退款回查：发现网关已退款的单自动封号';

    public function handle(RefundWatchService $service): int
    {
        $stats = $service->run(max(1, (int) $this->option('limit')), max(0, (int) $this->option('sleep-ms')));
        if ($stats['checked'] > 0) {
            Log::info('[refund-watch] run', $stats);
        }
        $this->info("回查 {$stats['checked']} 笔：已付 {$stats['paid']}，退款 {$stats['refunded']}，冻结 {$stats['frozen']}，失败 {$stats['errors']}");
        return self::SUCCESS;
    }
}
