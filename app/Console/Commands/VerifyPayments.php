<?php

namespace App\Console\Commands;

use App\Services\PaymentVerificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 付款事后核验：向支付网关复核已付订单 / 充值单（见 PaymentVerificationService）。
 * Kernel 每 5 分钟跑一次；只读网关、只写核验表，不影响开通。
 * 首次上线可加 --backfill-days 回填最近若干天（网关约保留 60 天）。
 */
class VerifyPayments extends Command
{
    protected $signature = 'payment:verify
        {--limit=100 : 单次最多核验多少笔}
        {--backfill-days= : 回填：核验最近 N 天已付的单（默认只看 7 天）}
        {--sleep-ms=200 : 两次网关查询之间的间隔（毫秒）}';

    protected $description = '付款事后核验：向支付网关复核已付订单与充值单';

    public function handle(PaymentVerificationService $service): int
    {
        $days = $this->option('backfill-days');
        $window = $days !== null && $days !== '' ? max(1, (int) $days) : PaymentVerificationService::DEFAULT_WINDOW_DAYS;
        $result = $service->run(max(1, (int) $this->option('limit')), $window, max(0, (int) $this->option('sleep-ms')));

        if ($result['checked'] > 0) {
            Log::info('[payment-verification] run', $result);
        }
        $summary = collect($result['verdicts'])->map(fn ($n, $v) => "{$v}={$n}")->implode(' ');
        $this->info("核验 {$result['checked']} 笔" . ($summary !== '' ? "：{$summary}" : ''));
        return self::SUCCESS;
    }
}
