<?php

namespace App\Services\Admin;

use App\Models\CommissionLog;
use App\Models\CommissionPeriodApplication;
use App\Models\CommissionWithdrawal;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\StatServer;
use App\Models\Ticket;
use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;

/**
 * 管理后台仪表盘的统计口径，集中在这里，前端只负责展示。
 *
 * 口径约定（与 xboard:statistics 写入 v2_stat 的口径一致）：
 * - 「收入」= 已支付订单（status 不是 待支付/已取消）的 total_amount 之和，按 paid_at 落日；
 *   total_amount 是实付金额（余额抵扣、优惠已扣掉），单位分。
 * - 「付费订单」只数实付 > 0 的订单；余额全额抵扣、0 元订单另计为 zero_count，
 *   客单价 = 收入 / 付费订单，避免被 0 元单摊薄。
 * - 「今日 / 本月」的对比一律跟「昨日同时段 / 上月同期」比，
 *   不拿半天跟昨天全天比（旧仪表盘的「较昨日 -60%」大多是这么来的）。
 * - 有效订阅 = 未封禁、有套餐、未到期（expired_at 为空视为长期有效），与 User::isActive() 相同。
 */
class DashboardStatService
{
    /** 节点多久没上报算离线，与 Server::isOnline 一致 */
    private const NODE_OFFLINE_AFTER = 300;

    /** 用户多久没上报算不在线，与旧仪表盘一致 */
    private const USER_ONLINE_WINDOW = 600;

    public function dashboard(): array
    {
        $now = now()->getTimestamp();
        $todayStart = strtotime('today', $now);
        $yesterdayStart = strtotime('-1 day', $todayStart);
        $yesterdaySameTime = min($yesterdayStart + ($now - $todayStart), $todayStart);
        $monthStart = strtotime(date('Y-m-01', $now));
        $lastMonthStart = strtotime('-1 month', $monthStart);
        $lastMonthSameTime = min($lastMonthStart + ($now - $monthStart), $monthStart);

        // 区间都是左闭右开；「今天 / 本月」右端取 now+1，把这一秒付的单也算进来
        $windows = [
            'today' => [$todayStart, $now + 1],
            'yesterday_same_time' => [$yesterdayStart, $yesterdaySameTime],
            'yesterday' => [$yesterdayStart, $todayStart],
            'month' => [$monthStart, $now + 1],
            'last_month_same_time' => [$lastMonthStart, $lastMonthSameTime],
            'last_month' => [$lastMonthStart, $monthStart],
        ];

        return [
            'generated_at' => $now,
            'income' => $this->incomeWindows($windows, $lastMonthStart, $now + 1),
            'users' => $this->userWindows($windows),
            'subscriptions' => $this->subscriptions($now),
            'nodes' => $this->nodes($now),
            'traffic' => $this->traffic($todayStart, $yesterdayStart, $monthStart, $lastMonthStart, $lastMonthSameTime),
            'todo' => $this->todo(),
        ];
    }

    /**
     * 收入趋势：按天的收入 / 订单数 / 佣金，含今天（今天还没过完，partial=true）。
     * 汇总里带上同长度的上一段，用来算环比。
     */
    public function income(string $startDate, string $endDate): array
    {
        $now = now()->getTimestamp();
        $start = strtotime($startDate);
        $rangeEnd = strtotime($endDate . ' +1 day');
        $end = min($rangeEnd, $now + 1);
        if ($end <= $start) {
            $end = $start + 86400;
        }
        $days = max(1, (int) round(($rangeEnd - $start) / 86400));
        $prevStart = strtotime("-{$days} day", $start);
        // 上一段只取与本段「已经过去」等长的时间：本段含今天（还没过完）时，上一段最后一天也只算到同一时刻，
        // 不拿 29 天半去跟整 30 天比
        $prevEnd = min($prevStart + ($end - $start), $start);
        $todayStart = strtotime('today', $now);

        $daily = [];
        for ($cursor = $start, $guard = 0; $cursor < $rangeEnd && $guard < 400; $cursor = strtotime('+1 day', $cursor), $guard++) {
            $key = date('Y-m-d', $cursor);
            $daily[$key] = [
                'date' => $key,
                'paid_total' => 0,
                'paid_count' => 0,
                'zero_count' => 0,
                'commission_total' => 0,
                'commission_count' => 0,
                'partial' => $cursor === $todayStart,
                'future' => $cursor > $now,
            ];
        }

        $current = $this->emptyPaid();
        $previous = $this->emptyPaid();
        $byType = [];
        $payingUsers = [];

        $orders = Order::query()
            ->whereNotIn('status', [Order::STATUS_PENDING, Order::STATUS_CANCELLED])
            ->where('paid_at', '>=', $prevStart)
            ->where('paid_at', '<', $end)
            ->toBase()
            ->select(['paid_at', 'total_amount', 'type', 'user_id'])
            ->cursor();

        foreach ($orders as $order) {
            $paidAt = (int) $order->paid_at;
            $amount = (int) $order->total_amount;
            if ($paidAt < $start) {
                if ($paidAt < $prevEnd) {
                    $this->addPaid($previous, $amount);
                }
                continue;
            }
            $this->addPaid($current, $amount);
            $key = date('Y-m-d', $paidAt);
            if (isset($daily[$key])) {
                if ($amount > 0) {
                    $daily[$key]['paid_total'] += $amount;
                    $daily[$key]['paid_count']++;
                } else {
                    $daily[$key]['zero_count']++;
                }
            }
            $type = (int) $order->type;
            $byType[$type] ??= ['type' => $type, 'label' => Order::$typeMap[$type] ?? (string) $type, 'count' => 0, 'amount' => 0, 'zero_count' => 0];
            if ($amount > 0) {
                $byType[$type]['count']++;
                $byType[$type]['amount'] += $amount;
                $payingUsers[(int) $order->user_id] = true;
            } else {
                $byType[$type]['zero_count']++;
            }
        }

        $commissionCurrent = ['total' => 0, 'count' => 0];
        $commissionPrevious = ['total' => 0, 'count' => 0];
        $logs = CommissionLog::query()
            ->where('created_at', '>=', $prevStart)
            ->where('created_at', '<', $end)
            ->toBase()
            ->select(['created_at', 'get_amount'])
            ->cursor();
        foreach ($logs as $log) {
            $createdAt = (int) $log->created_at;
            $amount = (int) $log->get_amount;
            if ($createdAt < $start) {
                if ($createdAt < $prevEnd) {
                    $commissionPrevious['total'] += $amount;
                    $commissionPrevious['count']++;
                }
                continue;
            }
            $commissionCurrent['total'] += $amount;
            $commissionCurrent['count']++;
            $key = date('Y-m-d', $createdAt);
            if (isset($daily[$key])) {
                $daily[$key]['commission_total'] += $amount;
                $daily[$key]['commission_count']++;
            }
        }

        ksort($byType);

        return [
            'range' => [
                'start_date' => date('Y-m-d', $start),
                'end_date' => $endDate,
                'days' => $days,
                'previous_start_date' => date('Y-m-d', $prevStart),
                'previous_end_date' => date('Y-m-d', strtotime('-1 day', $start)),
                // 上一段截止的时刻（不含）；本段含今天时，它落在上一段最后一天的同一时刻
                'previous_end_at' => $prevEnd,
                'includes_today' => $rangeEnd > $todayStart && $start <= $now,
            ],
            'summary' => $current + [
                'paying_users' => count($payingUsers),
                'avg_amount' => $current['paid_count'] > 0 ? (int) round($current['amount'] / $current['paid_count']) : 0,
                'commission_total' => $commissionCurrent['total'],
                'commission_count' => $commissionCurrent['count'],
            ],
            'previous' => $previous + [
                'avg_amount' => $previous['paid_count'] > 0 ? (int) round($previous['amount'] / $previous['paid_count']) : 0,
                'commission_total' => $commissionPrevious['total'],
                'commission_count' => $commissionPrevious['count'],
            ],
            'by_type' => array_values($byType),
            'list' => array_values($daily),
        ];
    }

    private function emptyPaid(): array
    {
        return ['amount' => 0, 'paid_count' => 0, 'zero_count' => 0];
    }

    private function addPaid(array &$bucket, int $amount): void
    {
        if ($amount > 0) {
            $bucket['amount'] += $amount;
            $bucket['paid_count']++;
        } else {
            $bucket['zero_count']++;
        }
    }

    /** 一次取出上月初到现在的已付订单，在 PHP 里分桶，避免对没有 paid_at 索引的表扫多遍 */
    private function incomeWindows(array $windows, int $from, int $to): array
    {
        $result = [];
        foreach ($windows as $name => $_) {
            $result[$name] = $this->emptyPaid();
        }
        $todayByType = [];

        $orders = Order::query()
            ->whereNotIn('status', [Order::STATUS_PENDING, Order::STATUS_CANCELLED])
            ->where('paid_at', '>=', $from)
            ->where('paid_at', '<', $to)
            ->toBase()
            ->select(['paid_at', 'total_amount', 'type'])
            ->cursor();

        foreach ($orders as $order) {
            $paidAt = (int) $order->paid_at;
            $amount = (int) $order->total_amount;
            foreach ($windows as $name => [$start, $end]) {
                if ($paidAt >= $start && $paidAt < $end) {
                    $this->addPaid($result[$name], $amount);
                }
            }
            if ($paidAt >= $windows['today'][0]) {
                $type = (int) $order->type;
                $todayByType[$type] ??= ['type' => $type, 'label' => Order::$typeMap[$type] ?? (string) $type, 'count' => 0, 'amount' => 0];
                if ($amount > 0) {
                    $todayByType[$type]['count']++;
                    $todayByType[$type]['amount'] += $amount;
                }
            }
        }
        ksort($todayByType);
        $result['today_by_type'] = array_values($todayByType);

        $commission = function (int $start, int $end) {
            return (int) CommissionLog::where('created_at', '>=', $start)->where('created_at', '<', $end)->sum('get_amount');
        };
        $result['commission'] = [
            'month' => $commission(...$windows['month']),
            'last_month_same_time' => $commission(...$windows['last_month_same_time']),
            'last_month' => $commission(...$windows['last_month']),
        ];

        return $result;
    }

    private function userWindows(array $windows): array
    {
        $count = function (int $start, int $end) {
            return User::where('created_at', '>=', $start)->where('created_at', '<', $end)->count();
        };
        $onlineSince = now()->getTimestamp() - self::USER_ONLINE_WINDOW;

        return [
            'total' => User::count(),
            'new_today' => $count(...$windows['today']),
            'new_yesterday_same_time' => $count(...$windows['yesterday_same_time']),
            'new_yesterday' => $count(...$windows['yesterday']),
            'new_month' => $count(...$windows['month']),
            'new_last_month_same_time' => $count(...$windows['last_month_same_time']),
            'new_last_month' => $count(...$windows['last_month']),
            'online' => User::where('t', '>=', $onlineSince)->count(),
            'online_devices' => (int) User::where('t', '>=', $onlineSince)->sum('online_count'),
        ];
    }

    private function activeUsers(int $now)
    {
        return User::query()
            ->where('banned', 0)
            ->whereNotNull('plan_id')
            ->where(function ($query) use ($now) {
                $query->whereNull('expired_at')->orWhere('expired_at', '>', $now);
            });
    }

    private function subscriptions(int $now): array
    {
        $week = $now + 7 * 86400;

        $byPlan = $this->activeUsers($now)
            ->toBase()
            ->selectRaw('plan_id, COUNT(*) as total')
            ->groupBy('plan_id')
            ->orderByDesc('total')
            ->get();
        $planNames = Plan::whereIn('id', $byPlan->pluck('plan_id'))->pluck('name', 'id');

        return [
            'active' => (int) $byPlan->sum('total'),
            'auto_renew' => $this->activeUsers($now)->where('auto_renew', 1)->count(),
            'expiring_today' => $this->activeUsers($now)->where('expired_at', '<=', strtotime('tomorrow', $now))->count(),
            'expiring_7d' => $this->activeUsers($now)->where('expired_at', '<=', $week)->count(),
            // 近 7 天到期且到现在还没续上的人：召回的目标人群
            'lapsed_7d' => User::where('banned', 0)
                ->whereNotNull('plan_id')
                ->where('expired_at', '>', $now - 7 * 86400)
                ->where('expired_at', '<=', $now)
                ->count(),
            'by_plan' => $byPlan->map(function ($row) use ($planNames) {
                return [
                    'plan_id' => (int) $row->plan_id,
                    'name' => $planNames[$row->plan_id] ?? ('#' . $row->plan_id),
                    'total' => (int) $row->total,
                ];
            })->values()->all(),
        ];
    }

    /**
     * 节点在线：子节点看父节点的上报时间（与节点列表的 is_online 一致）。
     * 旧的 ServerService::getOnlineServerCount 用 Cache::many 按 key 去重，
     * 子节点和父节点只算一次，所以「在线节点」比节点列表里亮绿灯的少。
     */
    private function nodes(int $now): array
    {
        $servers = Server::select(['id', 'parent_id', 'type', 'show', 'name'])->get();
        $keys = [];
        foreach ($servers as $server) {
            $keys[$server->id] = CacheKey::get('SERVER_' . strtoupper((string) $server->type) . '_LAST_CHECK_AT', $server->parent_id ?: $server->id);
        }
        $checkAts = $keys ? Cache::many(array_values(array_unique($keys))) : [];
        $threshold = $now - self::NODE_OFFLINE_AFTER;

        $shown = 0;
        $online = 0;
        $offline = [];
        foreach ($servers as $server) {
            if (!$server->show) {
                continue;
            }
            $shown++;
            $lastCheck = (int) ($checkAts[$keys[$server->id]] ?? 0);
            if ($lastCheck > $threshold) {
                $online++;
            } else {
                $offline[] = [
                    'id' => (int) $server->id,
                    'name' => (string) $server->name,
                    'last_check_at' => $lastCheck ?: null,
                ];
            }
        }
        usort($offline, fn ($a, $b) => ($b['last_check_at'] ?? 0) <=> ($a['last_check_at'] ?? 0));

        return [
            'total' => $servers->count(),
            'shown' => $shown,
            'hidden' => $servers->count() - $shown,
            'online' => $online,
            'offline' => count($offline),
            'offline_list' => array_slice($offline, 0, 8),
        ];
    }

    private function traffic(int $todayStart, int $yesterdayStart, int $monthStart, int $lastMonthStart, int $lastMonthSameTime): array
    {
        $sum = function (int $start, int $end) {
            $row = StatServer::where('record_type', 'd')
                ->where('record_at', '>=', $start)
                ->where('record_at', '<', $end)
                ->selectRaw('COALESCE(SUM(u), 0) as u, COALESCE(SUM(d), 0) as d')
                ->toBase()
                ->first();
            $u = (int) ($row->u ?? 0);
            $d = (int) ($row->d ?? 0);
            return ['upload' => $u, 'download' => $d, 'total' => $u + $d];
        };
        // stat_server 按天记一行（record_at = 当天 0 点），没有「昨日同时段」，只能给昨日全天
        $lastMonthDays = max(1, (int) round(($lastMonthSameTime - $lastMonthStart) / 86400));

        return [
            'today' => $sum($todayStart, $todayStart + 86400),
            'yesterday' => $sum($yesterdayStart, $todayStart),
            'month' => $sum($monthStart, $todayStart + 86400),
            'last_month_same_time' => $sum($lastMonthStart, $lastMonthStart + $lastMonthDays * 86400),
        ];
    }

    private function todo(): array
    {
        $withdrawals = CommissionWithdrawal::where('status', CommissionWithdrawal::STATUS_PENDING)
            ->toBase()
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(amount), 0) as amount')
            ->first();

        return [
            // 只数「等客服回复」的开启工单；已回复、等用户回的不算待办
            'tickets_waiting' => Ticket::where('status', Ticket::STATUS_OPENING)
                ->where('reply_status', Ticket::REPLY_STATUS_PENDING)
                ->count(),
            'tickets_open' => Ticket::where('status', Ticket::STATUS_OPENING)->count(),
            'withdrawals_pending' => (int) ($withdrawals->total ?? 0),
            'withdrawals_amount' => (int) ($withdrawals->amount ?? 0),
            'period_commission_pending' => CommissionPeriodApplication::where('status', CommissionPeriodApplication::STATUS_PENDING)->count(),
            'commission_unconfirmed' => Order::where('commission_status', 0)
                ->whereNotNull('invite_user_id')
                ->where('status', Order::STATUS_COMPLETED)
                ->where('commission_balance', '>', 0)
                ->count(),
            'mail_suppressed' => User::whereNotNull('mail_suppressed_at')->count(),
        ];
    }
}
