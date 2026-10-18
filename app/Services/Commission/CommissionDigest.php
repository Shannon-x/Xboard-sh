<?php

namespace App\Services\Commission;

use App\Models\CommissionLog;
use App\Models\User;

/**
 * 佣金入账的每日 / 每周汇总（commission_notify_frequency = daily / weekly）：
 * 读上次汇总之后落库的 CommissionLog，按邀请人分组，每人一封，交给 CommissionNotifier 发。
 *
 * 水位：上次汇总到的最大 CommissionLog id，存在 v2_settings（commission_notify_digest_last_id）。
 * 另有时间下限（周期 + 1 天）兜底：水位缺失（第一次跑）或陈旧（从 instant 切回来）时，
 * 最多回看一个周期，不会把几个月前的历史佣金一股脑发出去。
 */
final class CommissionDigest
{
    public const WATERMARK_KEY = 'commission_notify_digest_last_id';

    /** 最多回看多久：周期 + 1 天余量 */
    public static function lookbackSeconds(string $frequency): int
    {
        $days = $frequency === CommissionNotifier::FREQUENCY_DAILY ? 1 : 7;
        return ($days + 1) * 86400;
    }

    /**
     * @return array{inviters:int, sent:int, logs:int}
     */
    public function run(string $frequency, ?int $now = null): array
    {
        $now ??= now()->getTimestamp();
        $lastId = (int) admin_setting(self::WATERMARK_KEY, 0);
        $maxId = (int) CommissionLog::where('id', '>', $lastId)->max('id');
        if ($maxId <= $lastId) {
            return ['inviters' => 0, 'sent' => 0, 'logs' => 0];
        }
        // 总开关关着：水位照样推进，之后再打开不会补发积压
        if (!CommissionNotifier::enabled()) {
            admin_setting([self::WATERMARK_KEY => $maxId]);
            return ['inviters' => 0, 'sent' => 0, 'logs' => 0];
        }

        $since = $now - self::lookbackSeconds($frequency);
        $byInviter = [];
        $logs = 0;
        CommissionLog::where('id', '>', $lastId)
            ->where('id', '<=', $maxId)
            ->where('created_at', '>=', $since)
            ->select(['id', 'invite_user_id', 'user_id', 'trade_no', 'get_amount', 'created_at'])
            ->chunkById(1000, function ($rows) use (&$byInviter, &$logs) {
                foreach ($rows as $row) {
                    $byInviter[(int) $row->invite_user_id][] = [
                        'amount' => (int) $row->get_amount,
                        'user_id' => (int) $row->user_id,
                        'trade_no' => (string) $row->trade_no,
                        'level' => 1,
                        'time' => (int) $row->getRawOriginal('created_at'),
                    ];
                    $logs++;
                }
            });

        $this->fillLevels($byInviter);
        $sent = $byInviter === [] ? 0 : (new CommissionNotifier())->deliver($byInviter, $frequency);
        // 发完再推进：中途进程挂了下次会重发这一批，宁可重复也不漏
        admin_setting([self::WATERMARK_KEY => $maxId]);

        return ['inviters' => count($byInviter), 'sent' => $sent, 'logs' => $logs];
    }

    /**
     * CommissionLog 没存层级：开了三级分销时顺着被邀请人的邀请链往上找邀请人在第几级（找不到按 1 级）。
     * 没开分销的一律 1 级，不查库。
     */
    private function fillLevels(array &$byInviter): void
    {
        if (!(int) admin_setting('commission_distribution_enable', 0) || $byInviter === []) {
            return;
        }
        $parents = [];
        $buyerIds = [];
        foreach ($byInviter as $items) {
            foreach ($items as $item) {
                $buyerIds[$item['user_id']] = true;
            }
        }
        foreach (array_chunk(array_keys($buyerIds), 1000) as $ids) {
            $parents += User::whereIn('id', $ids)->pluck('invite_user_id', 'id')->map(fn ($v) => (int) $v)->all();
        }
        $parentOf = function (int $id) use (&$parents): int {
            if (!array_key_exists($id, $parents)) {
                $parents[$id] = (int) (User::where('id', $id)->value('invite_user_id') ?? 0);
            }
            return $parents[$id];
        };
        foreach ($byInviter as $inviterId => &$items) {
            foreach ($items as &$item) {
                $current = $item['user_id'];
                for ($level = 1; $level <= 3; $level++) {
                    $parent = $parentOf($current);
                    if ($parent === 0 || $parent === $current) {
                        break;
                    }
                    if ($parent === (int) $inviterId) {
                        $item['level'] = $level;
                        break;
                    }
                    $current = $parent;
                }
            }
            unset($item);
        }
        unset($items);
    }
}
