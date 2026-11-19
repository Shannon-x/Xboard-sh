<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Services\Plugin\HookManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 提前重置流量（提前进入下一周期）。
 *
 * 按月重置（按到期日锚定）的套餐，语义与 wyx2685/v2board 的 newPeriod 一致：
 *   结束当前周期，从点击时刻起开一个完整的新周期。客户放弃的只是「本周期还没用完的时间」，
 *   也就是 到期日 -= (下次自然重置 - 现在)，新周期满一个月，之后每月按点击那天重置。
 *   每一份完整流量都正好消耗一个月有效期（本周期已用的 + 被扣掉的剩余部分 = 一个周期），
 *   剩余配额份数不变，没有套利空间。
 *
 *   旧实现（2026-05 至 2026-11）不论本周期还剩几天一律扣 30 天，下次重置又被新到期日封顶，
 *   客户平均多亏约 17 天、新周期常被截短（#5431、#5218）。不要改回固定扣 30 天。
 *
 *   为了不让重置锚点漂移（到期日的「日」决定以后每月哪天重置），新到期日不是用秒数直接相减，
 *   而是「现在 + 剩余完整周期数 个自然月」：剩余完整周期数 = 原下次重置到原到期日之间的整月数。
 *   一般情况下两种算法结果完全相同；只在月末夹日（31 号锚点遇到短月）时差 1~3 天。
 *   扣完后必须至少还剩一个完整周期，否则不给（新周期会被到期日截短）。
 *
 * 每月 1 号重置的套餐：保持原样，扣 30 天、下次重置仍是原定的下个 1 号（v2board 对这类套餐同样扣 30 天）。
 */
class AdvanceCycleService
{
    // 每月 1 号重置的套餐固定扣的天数
    private const CONSUME_DAYS = 30;
    private const CONSUME_SECONDS = self::CONSUME_DAYS * 86400;
    // 判断「原到期日是否足够再排一个整月」时的容差：到期时刻与重置锚点的分秒误差不算缺一个周期
    private const PERIOD_TOLERANCE_SECONDS = 86400;
    // 默认按流量耗尽 95% 即可提前重置；与节点端常见的软上限（~95%）语义对齐。
    private const DEFAULT_USED_RATIO = 0.95;
    private const MIN_USED_RATIO = 0.5;
    private const MAX_USED_RATIO = 1.0;

    public function preview(User $user): array
    {
        app(TrafficResetService::class)->checkAndReset($user, TrafficResetLog::SOURCE_USER_ACCESS);
        $user->refresh()->loadMissing('plan');

        return $this->evaluate($user);
    }

    public function advance(User $user, array $auditContext = []): array
    {
        try {
            return DB::transaction(function () use ($user, $auditContext) {
                $lockedUser = User::with('plan')->lockForUpdate()->find($user->id);
                if (!$lockedUser) {
                    return $this->ineligible('user_not_found', __('advance_cycle.user_not_found'));
                }

                if (app(TrafficResetService::class)->checkAndReset($lockedUser, TrafficResetLog::SOURCE_USER_ACCESS)) {
                    $lockedUser->refresh()->loadMissing('plan');
                    return array_merge(
                        $this->evaluate($lockedUser),
                        $this->ineligible('regular_reset_due', __('advance_cycle.regular_reset_due'))
                    );
                }

                $preview = $this->evaluate($lockedUser);
                if (!$preview['eligible']) {
                    return $preview;
                }

                $now = now()->getTimestamp();
                $oldUpload = (int) ($lockedUser->u ?? 0);
                $oldDownload = (int) ($lockedUser->d ?? 0);
                $oldTotal = $oldUpload + $oldDownload;
                $oldExpiredAt = (int) $lockedUser->expired_at;
                $oldNextResetAt = $this->timestamp($lockedUser->next_reset_at);
                $plan = $this->plan($lockedUser, $now);
                $newExpiredAt = $plan['new_expired_at'];
                $newNextResetAt = $plan['new_next_reset_at'];
                // 提前进入下一周期 = 本周期结束，本周期加购的流量一并收回。
                $expiredTopup = (int) ($lockedUser->transfer_topup ?? 0);

                $lockedUser->forceFill([
                    'u' => 0,
                    'd' => 0,
                    'expired_at' => $newExpiredAt,
                    'last_reset_at' => $now,
                    'reset_count' => ((int) $lockedUser->reset_count) + 1,
                    'next_reset_at' => $newNextResetAt,
                ] + ($expiredTopup > 0 ? [
                    'transfer_enable' => max(0, (int) ($lockedUser->transfer_enable ?? 0) - $expiredTopup),
                    'transfer_topup' => 0,
                ] : []))->save();

                $metadata = array_filter([
                    'expired_topup' => $expiredTopup > 0 ? $expiredTopup : null,
                    'old_expired_at' => $oldExpiredAt,
                    'new_expired_at' => $newExpiredAt,
                    'old_next_reset_at' => $oldNextResetAt,
                    'new_next_reset_at' => $newNextResetAt,
                    'consume_seconds' => $oldExpiredAt - $newExpiredAt,
                    'consume_days' => $plan['consume_days'],
                    'ip' => $auditContext['ip'] ?? null,
                    'user_agent' => isset($auditContext['user_agent'])
                        ? substr((string) $auditContext['user_agent'], 0, 200)
                        : null,
                ], fn ($value) => $value !== null && $value !== '');

                TrafficResetLog::create([
                    'user_id' => $lockedUser->id,
                    'reset_type' => TrafficResetLog::TYPE_ADVANCE_CYCLE,
                    'reset_time' => now(),
                    'old_upload' => $oldUpload,
                    'old_download' => $oldDownload,
                    'old_total' => $oldTotal,
                    'new_upload' => 0,
                    'new_download' => 0,
                    'new_total' => 0,
                    'trigger_source' => TrafficResetLog::SOURCE_ADVANCE_CYCLE,
                    'metadata' => $metadata,
                ]);

                $this->clearUserCache($lockedUser);
                HookManager::call('traffic.advance_cycle.after', $lockedUser);
                HookManager::call('traffic.reset.after', $lockedUser);

                return array_merge($preview, [
                    'consume_days' => $plan['consume_days'],
                    'consume_seconds' => $oldExpiredAt - $newExpiredAt,
                    'eligible' => true,
                    'advanced' => true,
                    'message' => __('advance_cycle.success'),
                    'new_expired_at' => $newExpiredAt,
                    'new_next_reset_at' => $newNextResetAt,
                    'remaining' => $lockedUser->transfer_enable ?? 0,
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('advance_cycle.failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->ineligible('server_error', __('advance_cycle.failed'));
        }
    }

    private function evaluate(User $user): array
    {
        $now = now()->getTimestamp();
        $used = (int) ($user->u ?? 0) + (int) ($user->d ?? 0);
        $transferEnable = (int) ($user->transfer_enable ?? 0);
        $expiredAt = $user->expired_at !== null ? (int) $user->expired_at : null;
        $oldNextResetAt = $this->timestamp($user->next_reset_at);
        $plan = $expiredAt ? $this->plan($user, $now) : null;
        $newExpiredAt = $plan['new_expired_at'] ?? null;
        $usedRatio = $this->resolveUsedRatio();
        $usedThreshold = $this->calculateUsedThreshold($transferEnable, $usedRatio);
        $base = [
            // 实际扣掉的有效期：按月重置的套餐 = 本周期剩余时间，每月 1 号的套餐 = 30 天
            'consume_days' => $plan['consume_days'] ?? null,
            'consume_seconds' => ($expiredAt && $newExpiredAt !== null) ? $expiredAt - $newExpiredAt : null,
            // 扣完后新周期的长度（秒），前端用来提示「新周期满一个月」
            'new_cycle_seconds' => $plan ? $plan['new_next_reset_at'] - $now : null,
            'reset_method' => $this->resolveResetMethod($user->plan),
            'used' => $used,
            'transfer_enable' => $transferEnable,
            'remaining' => max(0, $transferEnable - $used),
            'used_ratio' => $usedRatio,
            'used_threshold' => $usedThreshold,
            'old_expired_at' => $expiredAt,
            'new_expired_at' => $newExpiredAt,
            'old_next_reset_at' => $oldNextResetAt,
            'new_next_reset_at' => $plan['new_next_reset_at'] ?? null,
        ];

        if ($user->banned || !$user->plan_id || !$user->plan) {
            return array_merge($base, $this->ineligible('not_active', __('advance_cycle.not_active')));
        }

        if ($expiredAt === null || $expiredAt <= $now) {
            return array_merge($base, $this->ineligible('invalid_expiration', __('advance_cycle.invalid_expiration')));
        }

        if (!$this->supportsAdvanceCycle($user->plan)) {
            return array_merge($base, $this->ineligible('unsupported_reset_method', __('advance_cycle.unsupported_reset_method')));
        }

        if ($transferEnable <= 0) {
            return array_merge($base, $this->ineligible('no_traffic_quota', __('advance_cycle.no_traffic_quota')));
        }

        if ($used < $usedThreshold) {
            return array_merge($base, $this->ineligible('traffic_not_exhausted', __('advance_cycle.traffic_not_exhausted')));
        }

        if ($oldNextResetAt !== null && $oldNextResetAt <= $now) {
            return array_merge($base, $this->ineligible('regular_reset_due', __('advance_cycle.regular_reset_due')));
        }

        if (!$plan['eligible']) {
            return array_merge($base, $this->ineligible('insufficient_remaining_days', __('advance_cycle.insufficient_remaining_days')));
        }

        return array_merge($base, [
            'eligible' => true,
            'advanced' => false,
            'reason' => null,
            'message' => __('advance_cycle.available'),
        ]);
    }

    private function resolveUsedRatio(): float
    {
        $configured = admin_setting('advance_cycle_used_ratio', self::DEFAULT_USED_RATIO);
        if (!is_numeric($configured)) {
            return self::DEFAULT_USED_RATIO;
        }
        $ratio = (float) $configured;
        if (!is_finite($ratio)) {
            return self::DEFAULT_USED_RATIO;
        }
        return max(self::MIN_USED_RATIO, min(self::MAX_USED_RATIO, $ratio));
    }

    private function calculateUsedThreshold(int $transferEnable, float $ratio): int
    {
        if ($transferEnable <= 0) {
            return 0;
        }
        // 取上取整，确保即便存在浮点误差也不会少于配置的比例；并夹在 [0, transferEnable] 内。
        $threshold = (int) ceil($transferEnable * $ratio);
        return max(0, min($transferEnable, $threshold));
    }

    private function resolveResetMethod(?Plan $plan): ?int
    {
        if (!$plan) {
            return null;
        }

        $resetMethod = $plan->reset_traffic_method;

        if ($resetMethod === Plan::RESET_TRAFFIC_FOLLOW_SYSTEM) {
            $resetMethod = (int) admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY);
        }

        return $resetMethod === null ? null : (int) $resetMethod;
    }

    /**
     * 支持提前重置的重置方式：
     *  - 按月重置（按到期日锚定）：结束本周期、从现在起开新周期，扣掉的是本周期剩余时间；
     *  - 每月 1 号重置：立即清零，扣 30 天，下一次重置仍是原定的下个 1 号，周期不重排。
     * 两种整个订阅期内能拿到的整月配额份数都不变。
     */
    private function supportsAdvanceCycle(Plan $plan): bool
    {
        return in_array($this->resolveResetMethod($plan), [
            Plan::RESET_TRAFFIC_MONTHLY,
            Plan::RESET_TRAFFIC_FIRST_DAY_MONTH,
        ], true);
    }

    /**
     * 算出提前重置后的新到期日、下次重置和扣掉的天数。
     *
     * @return array{eligible: bool, new_expired_at: int, new_next_reset_at: int, consume_days: float}
     */
    private function plan(User $user, int $now): array
    {
        $oldExpiredAt = (int) $user->expired_at;

        if ($this->resolveResetMethod($user->plan) === Plan::RESET_TRAFFIC_FIRST_DAY_MONTH) {
            // 每月 1 号的套餐：扣 30 天，保留原定的下个 1 号，不因提前重置把重置日往后推。
            $newExpiredAt = $oldExpiredAt - self::CONSUME_SECONDS;
            $scheduled = $this->timestamp($user->next_reset_at);
            if ($scheduled === null || $scheduled <= $now) {
                $scheduled = now()->startOfMonth()->addMonthNoOverflow()->timestamp;
            }

            return [
                'eligible' => ($oldExpiredAt - $now) > self::CONSUME_SECONDS,
                'new_expired_at' => $newExpiredAt,
                'new_next_reset_at' => min($scheduled, $newExpiredAt),
                'consume_days' => (float) self::CONSUME_DAYS,
            ];
        }

        // 按月重置（按到期日锚定）
        $tz = config('app.timezone');
        $oldNextResetAt = $this->timestamp($user->next_reset_at);
        if ($oldNextResetAt === null || $oldNextResetAt <= $now) {
            // 排期缺失或已过：照原到期日的锚点现算一次，不改库
            $oldNextResetAt = app(TrafficResetService::class)->calculateNextResetTime($user)?->timestamp ?? $oldExpiredAt;
        }

        // 原下次重置之后，到原到期日为止还排得下几个完整周期
        $nextReset = Carbon::createFromTimestamp($oldNextResetAt, $tz);
        $fullPeriods = 0;
        while ($fullPeriods < 1200
            && $nextReset->copy()->addMonthsNoOverflow($fullPeriods + 1)->timestamp <= $oldExpiredAt + self::PERIOD_TOLERANCE_SECONDS) {
            $fullPeriods++;
        }

        $nowAt = Carbon::createFromTimestamp($now, $tz);
        $newNextResetAt = $nowAt->copy()->addMonthNoOverflow()->timestamp;
        // 新周期从现在起算；之后剩下的完整周期原样保留。永远不会把到期日往后推。
        $newExpiredAt = $fullPeriods >= 1
            ? min($oldExpiredAt, $nowAt->copy()->addMonthsNoOverflow($fullPeriods)->timestamp)
            : $oldExpiredAt - max(0, $oldNextResetAt - $now);
        $consumeSeconds = max(0, $oldExpiredAt - $newExpiredAt);

        return [
            // 扣完后至少还要有一个完整周期，否则新周期会被到期日截短
            'eligible' => $fullPeriods >= 1 && $newExpiredAt >= $newNextResetAt,
            'new_expired_at' => $newExpiredAt,
            'new_next_reset_at' => min($newNextResetAt, $newExpiredAt),
            'consume_days' => round($consumeSeconds / 86400, 1),
        ];
    }

    private function timestamp(mixed $value): ?int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        return $value !== null ? (int) $value : null;
    }

    private function ineligible(string $reason, string $message): array
    {
        return [
            'eligible' => false,
            'advanced' => false,
            'reason' => $reason,
            'message' => $message,
        ];
    }

    private function clearUserCache(User $user): void
    {
        $cacheKeys = [
            "user_traffic_{$user->id}",
            "user_reset_status_{$user->id}",
            "user_subscription_{$user->token}",
        ];

        foreach ($cacheKeys as $key) {
            Cache::forget($key);
        }
    }
}
