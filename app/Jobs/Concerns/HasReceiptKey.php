<?php

namespace App\Jobs\Concerns;

/**
 * 由 TrafficReportJob 派发的子任务带一个确定的回执键（父任务 uuid + 任务类 + 块号）。
 * 父任务重试时会重新派发全部子任务，新副本的队列 uuid 不同，但回执键相同，
 * JobReceipt 据此跳过已经落库的那一份。没有回执键（旧版直接派发、已在队列里的任务）
 * 时仍按队列 uuid 记回执，行为与原来一样。
 */
trait HasReceiptKey
{
    protected ?string $receiptKey = null;

    public function withReceiptKey(string $key): static
    {
        $this->receiptKey = $key;
        return $this;
    }

    public function receiptKey(): ?string
    {
        return $this->receiptKey;
    }
}
