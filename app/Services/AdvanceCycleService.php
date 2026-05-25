<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Services\Plugin\HookManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdvanceCycleService
{
    private const CONSUME_DAYS = 30;
    private const CONSUME_SECONDS = self::CONSUME_DAYS * 86400;

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

                $now = time();
                $oldUpload = (int) ($lockedUser->u ?? 0);
                $oldDownload = (int) ($lockedUser->d ?? 0);
                $oldTotal = $oldUpload + $oldDownload;
                $oldExpiredAt = (int) $lockedUser->expired_at;
                $oldNextResetAt = $lockedUser->next_reset_at;
                $newExpiredAt = $oldExpiredAt - self::CONSUME_SECONDS;
                $newNextResetAt = $this->calculateAdvanceNextResetAt($lockedUser, $newExpiredAt);

                $lockedUser->forceFill([
                    'u' => 0,
                    'd' => 0,
                    'expired_at' => $newExpiredAt,
                    'last_reset_at' => $now,
                    'reset_count' => ((int) $lockedUser->reset_count) + 1,
                    'next_reset_at' => $newNextResetAt,
                ])->save();

                $metadata = array_filter([
                    'old_expired_at' => $oldExpiredAt,
                    'new_expired_at' => $newExpiredAt,
                    'old_next_reset_at' => $oldNextResetAt,
                    'new_next_reset_at' => $newNextResetAt,
                    'consume_days' => self::CONSUME_DAYS,
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
        $now = time();
        $used = (int) ($user->u ?? 0) + (int) ($user->d ?? 0);
        $transferEnable = (int) ($user->transfer_enable ?? 0);
        $expiredAt = $user->expired_at !== null ? (int) $user->expired_at : null;
        $oldNextResetAt = $user->next_reset_at;
        $newExpiredAt = $expiredAt ? $expiredAt - self::CONSUME_SECONDS : null;
        $base = [
            'consume_days' => self::CONSUME_DAYS,
            'consume_seconds' => self::CONSUME_SECONDS,
            'used' => $used,
            'transfer_enable' => $transferEnable,
            'remaining' => max(0, $transferEnable - $used),
            'old_expired_at' => $expiredAt,
            'new_expired_at' => $newExpiredAt,
            'old_next_reset_at' => $oldNextResetAt,
            'new_next_reset_at' => $newExpiredAt ? $this->calculateAdvanceNextResetAt($user, $newExpiredAt) : null,
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

        if ($used < $transferEnable) {
            return array_merge($base, $this->ineligible('traffic_not_exhausted', __('advance_cycle.traffic_not_exhausted')));
        }

        if (($expiredAt - $now) <= self::CONSUME_SECONDS) {
            return array_merge($base, $this->ineligible('insufficient_remaining_days', __('advance_cycle.insufficient_remaining_days')));
        }

        if ($oldNextResetAt !== null && $oldNextResetAt <= $now) {
            return array_merge($base, $this->ineligible('regular_reset_due', __('advance_cycle.regular_reset_due')));
        }

        return array_merge($base, [
            'eligible' => true,
            'advanced' => false,
            'reason' => null,
            'message' => __('advance_cycle.available'),
        ]);
    }

    private function supportsAdvanceCycle(Plan $plan): bool
    {
        $resetMethod = $plan->reset_traffic_method;

        if ($resetMethod === Plan::RESET_TRAFFIC_FOLLOW_SYSTEM) {
            $resetMethod = (int) admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY);
        }

        return $resetMethod === Plan::RESET_TRAFFIC_MONTHLY;
    }

    private function calculateAdvanceNextResetAt(User $user, int $newExpiredAt): ?int
    {
        return min(time() + self::CONSUME_SECONDS, $newExpiredAt);
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
