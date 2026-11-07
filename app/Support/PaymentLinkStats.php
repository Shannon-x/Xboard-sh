<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * 收款链接拉取统计：每个支付配置每天向网关要收款地址成功几次、失败几次、失败原因归类。
 *
 * CheckoutService::requestPayment 在调网关前后各记一次；网关插件知道上游原话（「单笔交易金额」
 * 「请使用国内IP」之类），在抛出给用户看的通用报错前用 reason() 留下，这里归成少数几类。
 *
 * 同一张单在支付会话有效期内反复点「去支付」会拿到同一个网关单号（插件也缓存了地址），
 * 按 网关单号 + 结果 去重，不让一个人连点十次把失败率拉高。
 *
 * 跳转式网关（易支付 V2 jump、V1 submit）在面板这一步只拿到一个跳转地址，「未适配到通道」「限国内IP」
 * 要等用户浏览器打开网关页面才出现，面板看不到——这类故障只能从「拉取成功但没人付款」看出来，
 * 所以日报和白天告警都带上付款数和转化率。
 *
 * 存在缓存里（生产是 Redis）：日计数 10 天过期，10 分钟桶 3 小时过期。任何缓存故障都吞掉，不影响下单。
 */
final class PaymentLinkStats
{
    public const OK = 'ok';
    public const FAIL = 'fail';

    private const PREFIX = 'payment_link_stats:';
    private const DAY_TTL = 10 * 86400;
    private const BUCKET_TTL = 3 * 3600;
    private const SEEN_TTL = 86400;
    private const BUCKET_SECONDS = 600;

    /** 失败原因：关键字 → 归类。按顺序匹配，第一条命中的为准 */
    private const REASONS = [
        '无可用通道' => ['未适配到通道', '无满足', '没有可用', '无可用', '通道不存在', '未开通'],
        '限国内IP' => ['国内IP', '国内 IP', '境外', '海外'],
        '单笔限额' => ['单笔', '限额', '金额范围', '最小金额', '最大金额', '金额不符'],
        '通道维护' => ['维护', '暂停', '关闭'],
        '单号重复' => ['订单已存在', '订单号重复', '已存在'],
        '签名或配置错误' => ['签名', 'sign', '商户不存在', '密钥', '配置'],
        '网关连不上' => ['timed out', 'timeout', 'cURL', 'Connection', 'HTTP ', '应答不是 JSON', 'resolve'],
        '跳转地址不认' => ['地址不认'],
    ];

    /** 本次请求里插件留下的上游原话；requestPayment 开头清空，结尾读走（Octane 下静态变量跨请求，必须清） */
    private static ?string $pendingReason = null;

    public static function begin(): void
    {
        self::$pendingReason = null;
    }

    /** 插件在抛出通用报错前调用，留下网关原话 */
    public static function reason(?string $gatewayMessage): void
    {
        $text = trim((string) $gatewayMessage);
        self::$pendingReason = $text === '' ? '网关未给原因' : mb_substr($text, 0, 120);
    }

    public static function takeReason(?\Throwable $e = null): string
    {
        $reason = self::$pendingReason;
        self::$pendingReason = null;
        if ($reason !== null) {
            return $reason;
        }
        return $e ? mb_substr(trim($e->getMessage()), 0, 120) : '';
    }

    public static function category(string $message): string
    {
        foreach (self::REASONS as $category => $needles) {
            foreach ($needles as $needle) {
                if ($needle !== '' && mb_stripos($message, $needle) !== false) {
                    return $category;
                }
            }
        }
        return '其他';
    }

    public static function record(int $paymentId, string $gatewayTradeNo, string $outcome, string $reason = '', ?int $now = null): void
    {
        $now ??= time();
        try {
            $seen = self::PREFIX . 'seen:' . hash('sha256', $paymentId . "\0" . $gatewayTradeNo . "\0" . $outcome);
            if ($gatewayTradeNo !== '' && !Cache::add($seen, 1, self::SEEN_TTL)) {
                return;
            }
            $day = date('Ymd', $now);
            self::bump(self::dayKey($paymentId, $day, $outcome), self::DAY_TTL);
            self::bump(self::bucketKey($paymentId, intdiv($now, self::BUCKET_SECONDS), $outcome), self::BUCKET_TTL);
            if ($outcome === self::FAIL) {
                $category = self::category($reason);
                $reasonsKey = self::PREFIX . "reasons:{$paymentId}:{$day}";
                $reasons = Cache::get($reasonsKey, []);
                $reasons = is_array($reasons) ? $reasons : [];
                $reasons[$category]['count'] = (int) ($reasons[$category]['count'] ?? 0) + 1;
                $reasons[$category]['sample'] = $reason;
                Cache::put($reasonsKey, $reasons, self::DAY_TTL);
            }
            $index = self::PREFIX . "ids:{$day}";
            $ids = Cache::get($index, []);
            $ids = is_array($ids) ? $ids : [];
            if (!in_array($paymentId, $ids, true)) {
                $ids[] = $paymentId;
                Cache::put($index, $ids, self::DAY_TTL);
            }
        } catch (\Throwable $e) {
            try {
                Log::warning('[payment-link-stats] 记录失败', ['error' => $e->getMessage()]);
            } catch (\Throwable $ignored) {
            }
        }
    }

    /**
     * 某天的统计。
     *
     * @return array<int, array{ok: int, fail: int, reasons: array<string, array{count: int, sample: string}>}>
     */
    public static function day(string $ymd): array
    {
        $out = [];
        try {
            $ids = Cache::get(self::PREFIX . "ids:{$ymd}", []);
            foreach (is_array($ids) ? $ids : [] as $id) {
                $id = (int) $id;
                $reasons = Cache::get(self::PREFIX . "reasons:{$id}:{$ymd}", []);
                $out[$id] = [
                    'ok' => (int) Cache::get(self::dayKey($id, $ymd, self::OK), 0),
                    'fail' => (int) Cache::get(self::dayKey($id, $ymd, self::FAIL), 0),
                    'reasons' => is_array($reasons) ? $reasons : [],
                ];
            }
        } catch (\Throwable $e) {
            return [];
        }
        ksort($out);
        return $out;
    }

    /**
     * 最近一段时间（按 10 分钟桶）的成功 / 失败数。$skipSeconds 跳过最近这段（刚拉的链接还没来得及付）。
     *
     * @return array{ok: int, fail: int}
     */
    public static function window(int $paymentId, int $seconds, int $skipSeconds = 0, ?int $now = null): array
    {
        $now ??= time();
        $result = ['ok' => 0, 'fail' => 0];
        try {
            $last = intdiv($now - $skipSeconds, self::BUCKET_SECONDS);
            $first = intdiv($now - $seconds, self::BUCKET_SECONDS);
            for ($b = $first; $b <= $last; $b++) {
                $result['ok'] += (int) Cache::get(self::bucketKey($paymentId, $b, self::OK), 0);
                $result['fail'] += (int) Cache::get(self::bucketKey($paymentId, $b, self::FAIL), 0);
            }
        } catch (\Throwable $e) {
        }
        return $result;
    }

    /** 当天出现过的支付配置 */
    public static function paymentIds(string $ymd): array
    {
        try {
            $ids = Cache::get(self::PREFIX . "ids:{$ymd}", []);
            return array_values(array_map('intval', is_array($ids) ? $ids : []));
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function dayKey(int $paymentId, string $ymd, string $outcome): string
    {
        return self::PREFIX . "day:{$paymentId}:{$ymd}:{$outcome}";
    }

    private static function bucketKey(int $paymentId, int $bucket, string $outcome): string
    {
        return self::PREFIX . "b:{$paymentId}:{$bucket}:{$outcome}";
    }

    private static function bump(string $key, int $ttl): void
    {
        Cache::add($key, 0, $ttl);
        Cache::increment($key);
    }
}
