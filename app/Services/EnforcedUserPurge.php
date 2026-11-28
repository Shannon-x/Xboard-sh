<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentVerification;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * 付款异常自动封号（PaymentEnforcement）的用户，封号满 180 天后自动删号，不再等管理员确认（2026-11-28）。
 * payment:purge-banned 每天跑一次。
 *
 * 删法与后台「用户 → 删除」完全一致（UserDeletionService）。删之前：
 * - 在锁内复核：仍在封禁中（管理员解封了的就不删）、不是管理员 / 客服、最近一次自动处置确实满了 180 天、
 *   封号后没有新的付款订单（有的话多半人工恢复过，留给管理员）；
 * - 处置快照 enforce_snapshot 原样保留，另记 deleted：删前的邮箱、注册时间、单号、累计付款，
 *   用户和订单删了也能从核验记录追溯；user_deleted_at 记删号时间，每日邮件据此汇总。
 */
final class EnforcedUserPurge
{
    /** 封号满多少天自动删号 */
    public const DAYS = 180;

    public const SOURCE = 'payment_enforce_purge';

    /** 自动处置的时间：退款回查记 refunded_at，付款核验记 checked_at / alerted_at（与每日邮件一致） */
    private const ENFORCED_AT = 'COALESCE(refunded_at, alerted_at, checked_at)';

    private const PAID_STATUSES = [Order::STATUS_PROCESSING, Order::STATUS_COMPLETED, Order::STATUS_DISCOUNTED];

    public function __construct(private readonly UserDeletionService $deletion)
    {
    }

    /** 这次自动处置对应的删号时间 */
    public static function deleteAt(int $enforcedAt): int
    {
        return $enforcedAt + self::DAYS * 86400;
    }

    /**
     * 自动封号后仍在封禁中、还没删的用户（管理员解封了的不在其中），按删号时间从早到晚。
     *
     * @return array<int, int> user_id => 最近一次自动处置时间
     */
    public function pending(): array
    {
        $rows = PaymentVerification::query()
            ->whereNotNull('enforce_snapshot')
            ->whereNotNull('user_id')
            ->whereIn('user_id', User::where('banned', 1)->where('is_admin', 0)->where('is_staff', 0)->select('id'))
            ->groupBy('user_id')
            ->selectRaw('user_id, MAX(' . self::ENFORCED_AT . ') AS enforced_at')
            ->get();
        $pending = [];
        foreach ($rows as $row) {
            if ((int) $row->enforced_at > 0) {
                $pending[(int) $row->user_id] = (int) $row->enforced_at;
            }
        }
        asort($pending);
        return $pending;
    }

    /**
     * 到了删号时间的用户。
     *
     * @return array<int, int> user_id => 最近一次自动处置时间
     */
    public function due(int $now): array
    {
        return array_filter($this->pending(), static fn (int $at) => self::deleteAt($at) <= $now);
    }

    /**
     * 这个用户现在不该自动删的原因；该删返回 null。
     */
    public function blocker(User $user, int $now): ?string
    {
        if ((int) $user->banned !== 1) {
            return '已解封';
        }
        if ($user->is_admin || $user->is_staff) {
            return '管理员 / 客服账号';
        }
        $enforcedAt = $this->lastEnforcedAt((int) $user->id);
        if ($enforcedAt === null) {
            return '没有自动处置记录';
        }
        if (self::deleteAt($enforcedAt) > $now) {
            return '封号未满 ' . self::DAYS . ' 天';
        }
        $paidLater = Order::where('user_id', $user->id)
            ->whereIn('status', self::PAID_STATUSES)
            ->where('created_at', '>', $enforcedAt)
            ->exists();
        if ($paidLater) {
            return '封号后又有付款订单，可能人工恢复过，请确认后到后台手动删';
        }
        return null;
    }

    /**
     * 删掉到期的用户。
     *
     * @return array{deleted: list<int>, skipped: array<int, string>} skipped：user_id => 没删的原因
     */
    public function run(int $now, int $limit = 200, bool $dryRun = false): array
    {
        $deleted = [];
        $skipped = [];
        // 上限只数真删掉的：一直被拦下的老用户不会占住名额，让后面到期的永远轮不到
        foreach (array_keys($this->due($now)) as $userId) {
            if (count($deleted) >= max(1, $limit)) {
                break;
            }
            $user = User::find($userId);
            if (!$user) {
                continue;
            }
            // 先在锁外筛一遍，被拦下的不进删除流程（也就不触发插件的删除钩子）；锁内再复核一次
            $reason = $this->blocker($user, $now);
            if ($reason !== null) {
                $skipped[$userId] = $reason;
                continue;
            }
            if ($dryRun) {
                $deleted[] = $userId;
                continue;
            }
            try {
                $ok = $this->deletion->delete($user, null, function (User $locked) use ($now, &$reason) {
                    $reason = $this->blocker($locked, $now);
                    if ($reason !== null) {
                        return false;
                    }
                    $this->recordTrace($locked, $now);
                    return true;
                }, self::SOURCE);
            } catch (\Throwable $e) {
                Log::error('[payment-purge] 自动删号失败', ['user_id' => $userId, 'error' => $e->getMessage()]);
                $skipped[$userId] = '删除出错：' . mb_substr($e->getMessage(), 0, 120);
                continue;
            }
            if ($ok) {
                $deleted[] = $userId;
                Log::warning('[payment-purge] 封号满 ' . self::DAYS . ' 天，已自动删号', ['user_id' => $userId]);
            } else {
                $skipped[$userId] = $reason ?? '用户已不存在';
            }
        }
        return ['deleted' => $deleted, 'skipped' => $skipped];
    }

    private function lastEnforcedAt(int $userId): ?int
    {
        $at = PaymentVerification::whereNotNull('enforce_snapshot')
            ->where('user_id', $userId)
            ->selectRaw('MAX(' . self::ENFORCED_AT . ') AS enforced_at')
            ->value('enforced_at');
        return (int) $at > 0 ? (int) $at : null;
    }

    /** 删号前把用户信息写进这个用户的每条处置记录（须在删除事务里调用） */
    private function recordTrace(User $user, int $now): void
    {
        $paid = Order::where('user_id', $user->id)->whereIn('status', [Order::STATUS_COMPLETED, Order::STATUS_DISCOUNTED]);
        $info = [
            'at' => $now,
            'reason' => '封号满 ' . self::DAYS . ' 天自动删号',
            'email' => (string) $user->email,
            'registered_at' => (int) $user->created_at,
            'invite_user_id' => $user->invite_user_id ? (int) $user->invite_user_id : null,
            'balance' => (int) $user->balance,
            'commission_balance' => (int) $user->commission_balance,
            'paid_orders' => (clone $paid)->count(),
            'paid_total' => (int) (clone $paid)->sum('total_amount'),
        ];
        $rows = PaymentVerification::whereNotNull('enforce_snapshot')
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->get();
        foreach ($rows as $row) {
            $snapshot = (array) $row->enforce_snapshot;
            // 订单随用户一起删，单号先记下来
            $snapshot['deleted'] = $info + ['trade_no' => PaymentEnforcement::tradeNoOf($row)];
            $row->enforce_snapshot = $snapshot;
            $row->user_deleted_at = $now;
            $row->save();
        }
    }
}
