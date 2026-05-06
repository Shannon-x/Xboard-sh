<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * 轻量级支付/账号事件计数与告警。
 *
 * 设计目标：
 *   - 灰度期间在生产打基线（e.g. 重复 webhook、金额不匹配次数）
 *   - 不引入额外依赖；用现成 Redis（项目已强制 redis）
 *   - 失败必须不影响业务路径 — 任何 Redis 故障都吞掉，仅写日志
 *
 * 数据结构：
 *   payment_metrics:{event}:{yyyymmddHH}      hash, field=label/total → counter
 *   一小时一桶；7 天后过期。后续可挂 Grafana / Prometheus exporter，本期先满足
 *   "出问题时能 redis-cli 自查" 的需求。
 */
class PaymentMetrics
{
    private const PREFIX = 'payment_metrics:';
    private const TTL_SECONDS = 86400 * 7; // 7 天

    /**
     * 累加一次事件。
     *
     * @param string $event   事件名，蛇形命名，例如 order.paid.duplicate
     * @param array  $labels  附加维度标签（保留少量、低基数键，例：['gateway' => 'epay']）
     */
    public static function inc(string $event, array $labels = []): void
    {
        try {
            $bucket = self::PREFIX . $event . ':' . date('YmdH');
            $field = self::flattenLabels($labels);

            Redis::hincrby($bucket, $field, 1);
            Redis::hincrby($bucket, '_total', 1);
            Redis::expire($bucket, self::TTL_SECONDS);
        } catch (\Throwable $e) {
            // metrics 不能影响业务；仅记一次结构化日志便于排查
            Log::warning('PaymentMetrics.inc failed', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 同时打 metrics + 结构化日志（warn 级别）。
     * 适合"灰度 warn 模式"下的可疑事件 — 既能计数也能查到上下文。
     */
    public static function warn(string $event, array $context = []): void
    {
        self::inc($event);
        Log::warning("payment.{$event}", $context);
    }

    /** 读最近 N 小时某事件的累计值（用于本地 dashboard 或快速排查）。 */
    public static function readRecent(string $event, int $hours = 24): array
    {
        $out = [];

        try {
            for ($i = 0; $i < $hours; $i++) {
                $hour = date('YmdH', time() - $i * 3600);
                $key = self::PREFIX . $event . ':' . $hour;
                $total = Redis::hget($key, '_total');
                $out[$hour] = (int) ($total ?? 0);
            }
        } catch (\Throwable $e) {
            Log::warning('PaymentMetrics.readRecent failed', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }

        return $out;
    }

    private static function flattenLabels(array $labels): string
    {
        if (empty($labels)) {
            return '_';
        }

        ksort($labels);
        $parts = [];
        foreach ($labels as $k => $v) {
            $parts[] = $k . '=' . (is_scalar($v) ? (string) $v : json_encode($v));
        }

        return implode('|', $parts);
    }
}
