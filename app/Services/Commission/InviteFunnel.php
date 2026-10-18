<?php

namespace App\Services\Commission;

use App\Models\CommissionLog;
use App\Models\InviteCode;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * 邀请页的分渠道漏斗：每个活跃邀请码的 打开 → 注册 → 付费 → 佣金，
 * 外加「未知来源」（有邀请人但没记录用的码：本列上线前注册的老用户，或码已被删除）
 * 与最近 30 天按天的注册 / 付费 / 佣金序列。
 *
 * 注册按 v2_user.invite_code = 码统计；付费按这些用户里有「已完成且实付 > 0」订单的去重人数；
 * 佣金按 v2_commission_log.get_amount 求和（只算已发放的）。时间按站点时区分日。
 * 整块按用户缓存 120 秒：邀请页刷新频繁，而这里要跑七八条聚合。
 */
final class InviteFunnel
{
    public const DAYS = 30;
    public const CACHE_TTL = 120;

    public static function forUser(User $user): array
    {
        return Cache::remember('invite_funnel:' . $user->id, self::CACHE_TTL, fn () => self::build($user));
    }

    public static function forget(int $userId): void
    {
        Cache::forget('invite_funnel:' . $userId);
    }

    public static function build(User $user): array
    {
        $inviterId = (int) $user->id;

        $codes = InviteCode::where('user_id', $inviterId)
            ->where('status', InviteCode::STATUS_UNUSED)
            ->orderBy('id')
            ->get(['code', 'pv']);
        $activeCodes = $codes->pluck('code')->map(fn ($c) => (string) $c)->all();

        // 被邀请人：id → 注册用的码
        $invitees = User::where('invite_user_id', $inviterId)->pluck('invite_code', 'id');
        $paidUserIds = InviteeStats::paidOrders($inviterId)->distinct()->pluck('user_id')
            ->map(fn ($id) => (int) $id)->flip();
        $commissionByUser = CommissionLog::where('invite_user_id', $inviterId)
            ->selectRaw('user_id, SUM(get_amount) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $bucket = fn () => ['registered' => 0, 'paid' => 0, 'commission_total' => 0];
        $byCode = array_fill_keys($activeCodes, $bucket());
        $unknown = $bucket();
        foreach ($invitees as $id => $code) {
            $code = (string) $code;
            if ($code !== '' && isset($byCode[$code])) {
                $row = &$byCode[$code];
            } else {
                $row = &$unknown;
            }
            $row['registered']++;
            if (isset($paidUserIds[(int) $id])) {
                $row['paid']++;
            }
            $row['commission_total'] += (int) ($commissionByUser[$id] ?? 0);
            unset($row);
        }

        $codeRows = [];
        foreach ($codes as $code) {
            $key = (string) $code->code;
            $codeRows[] = [
                'code' => $key,
                'pv' => (int) $code->pv,
                'registered' => $byCode[$key]['registered'],
                'paid' => $byCode[$key]['paid'],
                'commission_total' => $byCode[$key]['commission_total'],
            ];
        }

        return [
            'codes' => $codeRows,
            'unknown' => $unknown,
            'daily' => self::daily($inviterId),
        ];
    }

    /** 最近 N 天（含今天），缺的天补 0；按站点时区分日 */
    private static function daily(int $inviterId): array
    {
        $tz = config('app.timezone', 'UTC');
        $today = Carbon::now($tz)->startOfDay();
        $start = $today->copy()->subDays(self::DAYS - 1);
        $since = $start->getTimestamp();

        $days = [];
        for ($i = 0; $i < self::DAYS; $i++) {
            $days[$start->copy()->addDays($i)->format('Y-m-d')] = ['registered' => 0, 'paid' => 0, 'commission' => 0];
        }
        $dayOf = fn ($ts) => Carbon::createFromTimestamp((int) $ts, $tz)->format('Y-m-d');

        foreach (User::where('invite_user_id', $inviterId)->where('created_at', '>=', $since)->pluck('created_at') as $ts) {
            $d = $dayOf($ts);
            if (isset($days[$d])) {
                $days[$d]['registered']++;
            }
        }
        $paidOrders = Order::where('invite_user_id', $inviterId)
            ->where('status', Order::STATUS_COMPLETED)
            ->where('total_amount', '>', 0)
            ->where('created_at', '>=', $since)
            ->get(['user_id', 'created_at']);
        $seen = [];
        foreach ($paidOrders as $order) {
            $d = $dayOf($order->created_at);
            // 同一人同一天多单算 1 人
            if (isset($days[$d]) && !isset($seen[$d . ':' . $order->user_id])) {
                $seen[$d . ':' . $order->user_id] = true;
                $days[$d]['paid']++;
            }
        }
        foreach (CommissionLog::where('invite_user_id', $inviterId)->where('created_at', '>=', $since)->get(['get_amount', 'created_at']) as $log) {
            $d = $dayOf($log->created_at);
            if (isset($days[$d])) {
                $days[$d]['commission'] += (int) $log->get_amount;
            }
        }

        $out = [];
        foreach ($days as $date => $row) {
            $out[] = ['date' => $date] + $row;
        }
        return $out;
    }
}
