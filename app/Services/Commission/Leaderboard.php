<?php

namespace App\Services\Commission;

use App\Models\CommissionLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * 邀请页月度榜单（B5，可开关）：本月（config('app.timezone')）CommissionLog.get_amount 求和取前 10。
 *
 *   paid_invitees     本月给该邀请人带来佣金的不同被邀请人数（CommissionLog.user_id 去重；周期返佣下续费的老用户也算）
 *   commission_total  本月入账佣金合计（分，与 stat 一致）
 *   me                当前用户自己的名次与数字；不在前 10 时 rank = null，数字照给
 *
 * 榜单全站缓存 300 秒（key 带月份，跨月自然换新）；「me」不缓存，按用户实时算。
 * 邮箱脱敏走 EmailMask（与邀请页明细、入账通知同一口径）。
 */
final class Leaderboard
{
    public const CACHE_TTL = 300;
    public const TOP = 10;

    public static function enabled(): bool
    {
        return (bool) (int) admin_setting('commission_leaderboard_enable', 0);
    }

    /** @return array{enable:bool}|array{enable:bool, month:string, items:list<array>, me:array} */
    public static function forUser(User $user): array
    {
        if (!self::enabled()) {
            return ['enable' => false];
        }
        [$month, $start, $end] = self::currentMonth();
        $rows = Cache::remember('commission_leaderboard:' . $month, self::CACHE_TTL, fn () => self::build($start, $end));

        $items = [];
        $myRank = null;
        foreach ($rows as $i => $row) {
            $isMe = (int) $row['user_id'] === (int) $user->id;
            if ($isMe) {
                $myRank = $i + 1;
            }
            $items[] = [
                'rank' => $i + 1,
                'email' => EmailMask::mask($row['email']),
                'paid_invitees' => (int) $row['paid_invitees'],
                'commission_total' => (int) $row['commission_total'],
                'is_me' => $isMe,
            ];
        }

        $mine = CommissionLog::where('invite_user_id', $user->id)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->selectRaw('COALESCE(SUM(get_amount), 0) as commission_total, COUNT(DISTINCT user_id) as paid_invitees')
            ->first();

        return [
            'enable' => true,
            'month' => $month,
            'items' => $items,
            'me' => [
                'rank' => $myRank,
                'paid_invitees' => (int) ($mine->paid_invitees ?? 0),
                'commission_total' => (int) ($mine->commission_total ?? 0),
            ],
        ];
    }

    public static function forget(): void
    {
        Cache::forget('commission_leaderboard:' . self::currentMonth()[0]);
    }

    /** @return array{0:string,1:int,2:int} [YYYY-MM, 月初时间戳, 下月初时间戳] */
    private static function currentMonth(): array
    {
        $now = Carbon::now(config('app.timezone'));
        return [$now->format('Y-m'), $now->copy()->startOfMonth()->getTimestamp(), $now->copy()->addMonthNoOverflow()->startOfMonth()->getTimestamp()];
    }

    /** @return list<array{user_id:int, email:?string, paid_invitees:int, commission_total:int}> */
    private static function build(int $start, int $end): array
    {
        $rows = CommissionLog::where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->groupBy('invite_user_id')
            ->selectRaw('invite_user_id, COALESCE(SUM(get_amount), 0) as commission_total, COUNT(DISTINCT user_id) as paid_invitees')
            ->orderByDesc('commission_total')
            ->orderBy('invite_user_id')
            ->limit(self::TOP)
            ->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $emails = User::whereIn('id', $rows->pluck('invite_user_id'))->pluck('email', 'id');
        return $rows->map(fn ($row) => [
            'user_id' => (int) $row->invite_user_id,
            'email' => $emails[(int) $row->invite_user_id] ?? null,
            'paid_invitees' => (int) $row->paid_invitees,
            'commission_total' => (int) $row->commission_total,
        ])->all();
    }
}
