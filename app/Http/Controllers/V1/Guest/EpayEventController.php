<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Jobs\HandleEpayEventJob;
use App\Services\EpayEventService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 易支付 V2 事件消息订阅（交易投诉 / 订单退款）的接收端，见 EpayEventService。
 * 地址：/api/v1/guest/payment/notify/EPay/{uuid}/event，挂在支付回调前缀下，
 * sufe-middleware-rs 的回调白名单（/api/v1/guest/payment/notify/*）直接透传。
 */
class EpayEventController extends Controller
{
    public function receive(string $uuid, Request $request)
    {
        try {
            $payment = EpayEventService::paymentFor($uuid);
            if (!$payment) {
                // 与支付回调同一句话，不给探测者区分原因
                return $this->fail([422, 'verify error']);
            }
            $verified = EpayEventService::authenticate($payment, $this->candidates($request));
            $event = EpayEventService::describe($verified ?? $request->input());
            HandleEpayEventJob::dispatch((int) $payment->id, $event, $verified !== null, (string) $request->ip());
            if ($verified === null) {
                return $this->fail([422, 'verify error']);
            }
            return 'success';
        } catch (\Throwable $e) {
            try {
                Log::error($e);
            } catch (\Throwable) {
            }
            return $this->fail([500, 'fail']);
        }
    }

    /**
     * 同一请求的几种解析结果，逐个验签：框架解析的参数（会被 TrimStrings / 空串转 null 改动）、
     * 原始查询串、原始表单体、原始 JSON 体。
     *
     * @return list<array>
     */
    private function candidates(Request $request): array
    {
        $candidates = [$request->input()];
        $query = (string) $request->server('QUERY_STRING', '');
        if ($query !== '') {
            parse_str($query, $raw);
            $candidates[] = $raw;
        }
        $body = (string) $request->getContent();
        if ($body !== '') {
            $json = json_decode($body, true);
            if (is_array($json)) {
                $candidates[] = $json;
            } else {
                parse_str($body, $form);
                $candidates[] = $form;
            }
        }
        $unique = [];
        foreach ($candidates as $candidate) {
            if (is_array($candidate) && $candidate !== [] && !in_array($candidate, $unique, true)) {
                $unique[] = $candidate;
            }
        }
        return $unique;
    }
}
