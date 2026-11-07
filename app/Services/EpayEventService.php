<?php

namespace App\Services;

use App\Models\Payment;
use App\Support\AdminAlert;
use App\Support\EpayV2;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 易支付 V2「事件消息订阅」的接收与处理（牛牛支付商户后台 → 事件消息订阅设置，2026-11-07 接入）。
 *
 * 网关在发生「交易投诉」「订单退款」时推送到订阅 URL（/api/v1/guest/payment/notify/EPay/{uuid}/event），
 * 签名同 V2 异步通知：平台公钥 SHA256withRSA。网关文档没写推送字段，这里按字段名宽松识别事件类型与单号。
 *
 * 推送只当「该去查了」的信号，不当事实：
 * - 退款：拿网关交易号交给 RefundWatchService 立即回查，网关两次查单都说退了才封号（与日常回查同一套）；
 * - 投诉：立即跑一轮投诉列表（EpayGatewayMonitor，告警与回查都在那里），并回查这笔；
 * - 验签不过：只记日志、限频告警，并提前跑一轮投诉列表（查的仍是网关自己的数据），不按推送里的单号做任何事。
 * 每 10 分钟的投诉轮询与每日回查照旧，推送丢了也能兜住。
 *
 * 推送内容可能带投诉人手机号等，日志与告警里只写字段名，不写值。
 */
final class EpayEventService
{
    public const KIND_REFUND = 'refund';
    public const KIND_COMPLAINT = 'complaint';
    public const KIND_UNKNOWN = 'unknown';

    /** 同一笔同一类事件多久内只处理一次（网关重投、一次投诉多次状态变化） */
    private const DEDUPE_TTL = 600;
    /** 由推送触发的投诉列表轮询最短间隔 */
    private const COMPLAINT_POLL_THROTTLE = 60;
    /** 验签失败告警的最短间隔 */
    private const BAD_SIGN_ALERT_TTL = 3600;
    /** 「已收到第一条某类推送」只告诉一次 */
    private const FIRST_SEEN_TTL = 365 * 86400;

    private const EVENT_FIELDS = ['event', 'event_type', 'eventtype', 'notify_type', 'msg_type', 'action', 'topic', 'type'];

    public function __construct(
        private ?RefundWatchService $refundWatch = null,
        private ?EpayGatewayMonitor $monitor = null,
    ) {
    }

    /** uuid 对应的、能收事件推送的支付配置（V2 的 EPay；停用的也收，旧单的投诉退款照样要处理） */
    public static function paymentFor(string $uuid): ?Payment
    {
        $payment = Payment::where('uuid', $uuid)->first();
        if (!$payment || (string) $payment->payment !== 'EPay' || !EpayV2::enabledFor((array) $payment->config)) {
            return null;
        }
        return $payment;
    }

    /**
     * 验签，返回验过的那组参数；都验不过返回 null。
     *
     * @param list<array> $candidates 同一请求的几种解析结果（框架解析的参数、原始查询串、原始 JSON 体）
     */
    public static function authenticate(Payment $payment, array $candidates): ?array
    {
        try {
            $client = EpayV2::fromConfig((array) $payment->config);
        } catch (\InvalidArgumentException) {
            return null;
        }
        foreach ($candidates as $candidate) {
            if ($candidate !== [] && $client->verify($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * 从推送里认出事件类型与单号。字段可能平铺，也可能包在 data / content 等子对象或 JSON 串里。
     *
     * @return array{kind: string, trade_no: string, out_trade_no: string, fields: list<string>}
     */
    public static function describe(array $params): array
    {
        $flat = self::flatten($params);
        $kind = self::KIND_UNKNOWN;
        foreach (self::EVENT_FIELDS as $field) {
            $kind = self::kindOf((string) ($flat[$field] ?? ''));
            if ($kind !== self::KIND_UNKNOWN) {
                break;
            }
        }
        if ($kind === self::KIND_UNKNOWN) {
            foreach (array_keys($flat) as $key) {
                $kind = self::kindOf((string) $key);
                if ($kind !== self::KIND_UNKNOWN) {
                    break;
                }
            }
        }
        $fields = array_values(array_filter(array_map('strval', array_keys($flat)), fn ($k) => !in_array($k, ['sign', 'sign_type'], true)));
        sort($fields);
        return [
            'kind' => $kind,
            'trade_no' => self::scalar($flat, ['trade_no', 'api_trade_no', 'transaction_id']),
            'out_trade_no' => self::scalar($flat, ['out_trade_no']),
            'fields' => $fields,
        ];
    }

    /** 验过签的推送：按事件类型立即回查 / 拉投诉列表 */
    public function handleVerified(Payment $payment, array $event): void
    {
        $this->announceFirst($payment, $event);

        if ($event['kind'] === self::KIND_COMPLAINT) {
            $this->pollComplaints('投诉推送');
        }

        $tradeNo = $event['trade_no'] !== '' ? $event['trade_no'] : $this->tradeNoFromGateway($payment, $event['out_trade_no']);
        if ($tradeNo === '') {
            return;
        }
        if (!Cache::add('epay_event:' . $event['kind'] . ':' . $tradeNo, 1, self::DEDUPE_TTL)) {
            return;
        }
        $label = ['refund' => '退款推送', 'complaint' => '投诉推送'][$event['kind']] ?? '网关事件推送';
        $outcome = $this->refundWatch()->recheckByCallbackNo($tradeNo, $label);

        if ($outcome === null && $event['kind'] === self::KIND_REFUND) {
            $this->alertOnce('epay_event_orphan:' . $tradeNo, implode("\n", [
                '❓ <b>网关推送了一笔退款，本站没有对应的已核验单</b>',
                '网关交易号：<code>' . TelegramService::escapeHtml($tradeNo) . '</code>',
                '可能是别的站点用同一商户号收的款，或是核验功能上线前的老单，请在网关后台看一下。',
            ]));
        } elseif ($outcome === 'paid' && $event['kind'] === self::KIND_REFUND) {
            // 推送说退了、查单还是已付：可能网关查单滞后，回查节奏照旧，不封号
            $this->alertOnce('epay_event_mismatch:' . $tradeNo, implode("\n", [
                '⚠️ <b>网关推送了退款，但查单仍显示已支付</b>，暂不处置',
                '网关交易号：<code>' . TelegramService::escapeHtml($tradeNo) . '</code>',
                '每日回查会继续盯这笔，查到退款会自动封号。',
            ]));
        }
    }

    /** 验签不过的推送：记字段名、限频告警，只提前跑一轮投诉列表 */
    public function handleUnverified(Payment $payment, array $event, string $ip): void
    {
        try {
            Log::warning('[epay-event] 推送验签失败', [
                'payment_id' => $payment->id, 'kind' => $event['kind'], 'fields' => $event['fields'], 'ip' => $ip,
            ]);
        } catch (\Throwable) {
        }
        if (Cache::add('epay_event_bad_sign:' . $payment->id, 1, self::BAD_SIGN_ALERT_TTL)) {
            AdminAlert::send(implode("\n", [
                '⚠️ <b>收到网关事件推送，但验签没过</b>（1 小时内只提醒一次）',
                '支付配置：#' . $payment->id . ' ' . TelegramService::escapeHtml((string) $payment->name),
                '字段：' . TelegramService::escapeHtml(implode(', ', $event['fields']) ?: '（无）'),
                '来源 IP：' . TelegramService::escapeHtml($ip),
                '没按推送内容做任何处理，只提前拉了一次投诉列表。若是网关的真实推送，说明推送签名格式与文档不同，需要适配。',
            ]), 'html', true);
        }
        $this->pollComplaints('验签失败的推送');
    }

    private function pollComplaints(string $why): void
    {
        if (!Cache::add('epay_event:complaint_poll', 1, self::COMPLAINT_POLL_THROTTLE)) {
            return;
        }
        try {
            $this->monitor()->complaints();
        } catch (\Throwable $e) {
            try {
                Log::warning('[epay-event] 投诉列表拉取失败', ['why' => $why, 'error' => $e->getMessage()]);
            } catch (\Throwable) {
            }
        }
    }

    /** 推送只带商户单号时，向网关查出网关交易号（商户单号可能是支付会话号，不一定是本站订单号） */
    private function tradeNoFromGateway(Payment $payment, string $outTradeNo): string
    {
        if ($outTradeNo === '') {
            return '';
        }
        try {
            $response = EpayV2::fromConfig((array) $payment->config)->query('', $outTradeNo);
        } catch (\Throwable) {
            return '';
        }
        if (!($response['ok'] ?? false)) {
            return '';
        }
        return trim((string) ($response['data']['trade_no'] ?? ''));
    }

    private function announceFirst(Payment $payment, array $event): void
    {
        if ($event['kind'] === self::KIND_UNKNOWN || !Cache::add('epay_event_first:' . $payment->id . ':' . $event['kind'], 1, self::FIRST_SEEN_TTL)) {
            return;
        }
        AdminAlert::send(implode("\n", [
            '✅ <b>网关事件订阅已打通</b>：收到第一条' . ($event['kind'] === self::KIND_REFUND ? '退款' : '投诉') . '推送，验签通过',
            '支付配置：#' . $payment->id . ' ' . TelegramService::escapeHtml((string) $payment->name),
            '字段：' . TelegramService::escapeHtml(implode(', ', $event['fields'])),
        ]), 'html', true);
    }

    private function alertOnce(string $key, string $html): void
    {
        if (Cache::add($key, 1, 7 * 86400)) {
            AdminAlert::send($html, 'html', true);
        }
    }

    private static function kindOf(string $text): string
    {
        $text = strtolower($text);
        if ($text === '') {
            return self::KIND_UNKNOWN;
        }
        if (str_contains($text, 'refund') || str_contains($text, '退款')) {
            return self::KIND_REFUND;
        }
        if (str_contains($text, 'complain') || str_contains($text, '投诉')) {
            return self::KIND_COMPLAINT;
        }
        return self::KIND_UNKNOWN;
    }

    /** 顶层优先；子对象和 JSON 串里的字段展开一层（不覆盖顶层同名字段） */
    private static function flatten(array $params): array
    {
        $flat = [];
        $nested = [];
        foreach ($params as $key => $value) {
            if (is_string($value) && strlen($value) > 1 && ($value[0] === '{' || $value[0] === '[')) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $nested[] = $decoded;
                    $flat[$key] = $value;
                    continue;
                }
            }
            if (is_array($value)) {
                $nested[] = $value;
                continue;
            }
            $flat[$key] = $value;
        }
        foreach ($nested as $child) {
            foreach ($child as $key => $value) {
                if (!is_array($value) && !array_key_exists($key, $flat)) {
                    $flat[$key] = $value;
                }
            }
        }
        return $flat;
    }

    private static function scalar(array $flat, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $flat[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }
        return '';
    }

    private function refundWatch(): RefundWatchService
    {
        return $this->refundWatch ??= new RefundWatchService();
    }

    private function monitor(): EpayGatewayMonitor
    {
        return $this->monitor ??= new EpayGatewayMonitor($this->refundWatch());
    }
}
