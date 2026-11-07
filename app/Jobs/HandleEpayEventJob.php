<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Services\EpayEventService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * 易支付事件推送的后续处理（回查、拉投诉列表、告警）放进队列：要连查几次网关，
 * 不能让网关等着，接收端先回 success。只带解析出的事件类型、单号和字段名，不带推送原文。
 */
class HandleEpayEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 2;
    public $timeout = 120;

    /**
     * @param array{kind: string, trade_no: string, out_trade_no: string, fields: list<string>} $event
     */
    public function __construct(
        public int $paymentId,
        public array $event,
        public bool $verified,
        public string $ip = '',
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $payment = Payment::find($this->paymentId);
        if (!$payment) {
            return;
        }
        $service = new EpayEventService();
        $this->verified
            ? $service->handleVerified($payment, $this->event)
            : $service->handleUnverified($payment, $this->event, $this->ip);
    }
}
