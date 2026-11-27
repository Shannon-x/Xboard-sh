<?php

namespace App\Services\Mail;

use App\Models\MailLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * 后台仪表盘「邮件投递」卡片的数据：投递服务在不在、各通道交给谁发、积压、发送速度、24 小时额度、失败。
 * 投递服务的数字全来自它每 5 秒写一次的心跳（mail-relay/src/relay.rs housekeeping），这里不连它的 HTTP 端口；
 * 24 小时成功 / 失败按 v2_mail_log 算，面板直发和投递服务发的都在里面。
 */
final class MailRelayStatus
{
    public const LANES = [
        'instant' => '验证码',
        'transactional' => '收据与通知',
        'bulk' => '批量提醒',
    ];

    /** 后台读的入口 */
    public static function current(): array
    {
        $raw = null;
        try {
            $raw = Redis::get(MailRelay::key('heartbeat'));
        } catch (\Throwable $e) {
            Log::warning('[mail] 读投递服务心跳失败', ['error' => $e->getMessage()]);
        }
        $heartbeat = is_string($raw) ? json_decode($raw, true) : null;
        return self::build(is_array($heartbeat) ? $heartbeat : null, MailRelay::lanes(), time(), self::mailLog24h());
    }

    /**
     * @param array|null $hb 心跳（没有就是 null：投递服务没起来，或者还没拿到 SMTP 配置）
     * @param string[] $enabled .env 里开了投递服务的通道
     * @param array{sent: int, failed: int, by_category: array<string, int>} $log
     */
    public static function build(?array $hb, array $enabled, int $now, array $log): array
    {
        $at = (int) ($hb['at'] ?? 0);
        $age = $hb ? max(0, $now - $at) : null;
        $online = $hb !== null && !empty($hb['smtp']) && $age <= MailRelay::HEARTBEAT_MAX_AGE;

        $depths = is_array($hb['depths'] ?? null) ? $hb['depths'] : [];
        $counters = is_array($hb['counters'] ?? null) ? $hb['counters'] : [];
        $lanes = [];
        foreach (self::LANES as $name => $label) {
            $on = in_array($name, $enabled, true);
            $lanes[] = [
                'name' => $name,
                'label' => $label,
                // relay：投递服务在发；fallback：开了但投递服务不在，面板自己发；direct：没开，面板自己发
                'mode' => $on ? ($online ? 'relay' : 'fallback') : 'direct',
                'pending' => $online && isset($depths[$name]) ? (int) $depths[$name] : null,
                'sent' => (int) ($counters['sent'][$name] ?? 0),
            ];
        }

        $series = array_values(array_map('intval', is_array($hb['per_minute'] ?? null) ? $hb['per_minute'] : []));
        // 最后一格是还没走完的这一分钟，「每分钟发送」取上一整分钟
        $lastMinute = count($series) >= 2 ? $series[count($series) - 2] : 0;

        $limit = (int) ($hb['daily_limit'] ?? 0);
        $used = (int) ($hb['daily_used'] ?? 0);
        $bulkCap = (int) ($hb['daily_bulk_cap'] ?? $limit);
        $failed = is_array($counters['failed'] ?? null) ? $counters['failed'] : [];

        return [
            'online' => $online,
            'any_enabled' => $enabled !== [],
            'heartbeat_age' => $age,
            'version' => $hb['version'] ?? null,
            'started_at' => isset($hb['started_at']) ? (int) $hb['started_at'] : null,
            'connections' => isset($hb['connections']) ? (int) $hb['connections'] : null,
            'inflight' => (int) ($hb['inflight'] ?? 0),
            'delayed' => $online && isset($hb['delayed']) ? (int) $hb['delayed'] : null,
            // 小于 1 说明最近被服务商限流（455），批量在降速
            'bulk_factor' => isset($hb['bulk_factor']) ? round((float) $hb['bulk_factor'], 2) : 1.0,
            'lanes' => $lanes,
            'rate' => [
                'limit' => isset($hb['rate_per_minute']) ? (int) $hb['rate_per_minute'] : null,
                'last_minute' => $lastMinute,
                'last_hour' => array_sum($series),
                'per_minute' => $series,
            ],
            'daily' => [
                'used' => $used,
                'limit' => $limit,
                'bulk_cap' => $bulkCap,
                'bulk_paused' => $online && $limit > 0 && $used >= $bulkCap,
                'full' => $online && $limit > 0 && $used >= $limit,
            ],
            // 启动以来（重启清零）
            'since_start' => [
                'throttled' => (int) ($counters['throttle_events'] ?? 0),
                'retried' => (int) ($counters['retried'] ?? 0),
                'deferred' => (int) ($counters['deferred'] ?? 0),
                'expired' => (int) ($counters['expired'] ?? 0),
                'failed' => array_sum(array_map('intval', $failed)),
            ],
            'last_24h' => $log,
        ];
    }

    /** 近 24 小时的发信结果（v2_mail_log 有 status + created_at 联合索引） */
    private static function mailLog24h(): array
    {
        $base = MailLog::where('created_at', '>=', time() - 86400);
        return [
            'sent' => (clone $base)->where('status', 1)->count(),
            'failed' => (clone $base)->where('status', 0)->count(),
            'by_category' => (clone $base)->where('status', 0)->whereNotNull('category')
                ->selectRaw('category, count(*) as n')->groupBy('category')->pluck('n', 'category')
                ->map(fn ($n) => (int) $n)->all(),
        ];
    }
}
