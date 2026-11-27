<?php

namespace App\Jobs\Middleware;

use App\Services\Mail\MailLane;
use Illuminate\Support\Facades\Redis;

/**
 * 批量邮件的全局限速（config mail_lanes.bulk_per_minute，0 = 不限）。只管 bulk 通道，即时和交易类永远不等。
 *
 * 用 Redis 令牌（所有 bulk worker 共享一个计数）：拿不到时最多阻塞等 BLOCK_SECONDS 秒，
 * 还拿不到才放回队列 RELEASE_SECONDS 秒后再试。阻塞而不是立刻放回，是为了少占重试次数：
 * 放回一次就算一次 attempt，SendBillingMailJob 在最后一次尝试时会把临时失败转去 Telegram。
 */
final class MailLaneThrottle
{
    public const KEY = 'mail-lane:bulk';
    public const BLOCK_SECONDS = 20;
    public const RELEASE_SECONDS = 10;

    public function handle(object $job, callable $next): void
    {
        $limit = (int) config('mail_lanes.bulk_per_minute', 0);
        if ($limit <= 0 || !MailLane::isBulk($job->queue ?? null)) {
            $next($job);
            return;
        }
        Redis::throttle(self::KEY)
            ->allow($limit)
            ->every(60)
            ->block(self::BLOCK_SECONDS)
            ->then(
                fn () => $next($job),
                fn () => $job->release(self::RELEASE_SECONDS)
            );
    }
}
