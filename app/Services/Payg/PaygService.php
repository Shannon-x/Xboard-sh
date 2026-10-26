<?php

namespace App\Services\Payg;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\PaygLog;
use App\Models\Plan;
use App\Models\StatUser;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PlanCustomizationService;
use App\Services\TrafficResetService;
use App\Services\UserService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 上网按量钱包：开通、换包月时的折抵、月度保底、自动补充、面板摘要与扣费明细。
 *
 * 一个账本：钱包余额就是 v2_user.transfer_enable − u − d（字节，已按节点倍率扣），
 * ¥ = 剩余 GB × 单价，只在展示时换算。这里写的 v2_payg_log 只是事件记录（明细、首充判断、
 * 保底幂等），删了它余额也不会变。
 *
 * 下单 / 付款完全走现有流程：按量套餐是一次性周期套餐，充值 = 再买一次这个套餐。
 * 和普通一次性套餐的区别只有开通那一步（OrderService::buyByOneTime 转到 deliver）：
 *   - 已经在用这个按量套餐 → 流量叠加，不清零已用（普通一次性套餐会覆盖）；
 *   - 赠送：本单折前金额满档位送若干元（换成 GB），每个用户第一次充值另送 N GB。
 */
class PaygService
{
    public const LEDGER_LIMIT = 50;
    public const LEDGER_MONTHS = 3;
    /** 自动补充每人每天最多几次：流量统计出了异常也不会把账户余额一路扣光 */
    public const AUTO_TOPUP_DAILY_LIMIT = 10;
    /** 自动补充订单的回调号前缀：开通时据此把这一笔记成「自动补充」 */
    public const AUTO_TOPUP_CALLBACK_PREFIX = 'payg_auto_topup:';

    private const GB = PaygConfig::BYTES_PER_GB;

    /** 用户此刻是不是按量钱包用户：在按量套餐上、不限期。 */
    public static function isWallet(User $user, ?PaygConfig $config = null): bool
    {
        $config ??= PaygConfig::fromSettings();
        return $config->isPaygPlanId($user->plan_id) && $user->expired_at === null;
    }

    // ───────────────────────── 开通 ─────────────────────────

    /**
     * 按量套餐订单开通（在 OrderService::open 的事务里、对已锁的用户行调用，不 save，由 open 统一保存）。
     *
     * @param Plan $plan 已按订单快照回填过规格的套餐（transfer_enable = 本单买的 GB）
     */
    public function deliver(User $user, Order $order, Plan $plan, PaygConfig $config): void
    {
        $bytes = max(0, (int) $plan->transfer_enable) * self::GB;
        $stack = (int) $user->plan_id === (int) $plan->id && $user->expired_at === null;
        if ($stack) {
            // 再充值：已用不清零，余额 = 原余额（可能已被用成负数）+ 本单
            $user->transfer_enable = (int) ($user->transfer_enable ?? 0) + $bytes;
        } else {
            // 开新钱包：与普通一次性套餐完全相同 —— 清零已用，发这一单的流量
            app(TrafficResetService::class)->performReset($user, TrafficResetLog::SOURCE_ORDER);
            $user->transfer_enable = $bytes;
        }
        $user->plan_id = $plan->id;
        $user->group_id = $plan->group_id;
        $user->expired_at = null;

        // 单价按后台此刻的套餐算（$plan 的 transfer_enable 已被快照改成本单 GB，不能拿来除）
        $base = Plan::find($plan->id);
        $unit = $base ? PaygConfig::unitPrice($base) : null;
        $listAmount = (int) ($order->plan_snapshot['amount'] ?? ($base ? PaygConfig::baseAmount($base) : 0));
        $bonusBytes = $unit ? PaygConfig::bytesFor($unit, $config->bonusFor($listAmount)) : 0;
        $firstBonusBytes = $config->firstBonusGb > 0 && !self::hasFirstBonus((int) $user->id)
            ? $config->firstBonusGb * self::GB : 0;
        $user->transfer_enable = (int) $user->transfer_enable + $bonusBytes + $firstBonusBytes;

        PaygLog::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'order_id' => $order->id,
            'type' => str_starts_with((string) $order->callback_no, self::AUTO_TOPUP_CALLBACK_PREFIX)
                ? PaygLog::TYPE_AUTO_TOPUP : PaygLog::TYPE_RECHARGE,
            'opening' => $stack ? 0 : 1,
            'amount' => max(0, (int) $order->total_amount) + max(0, (int) ($order->balance_amount ?? 0)),
            'bytes' => $bytes,
            'bonus_bytes' => $bonusBytes,
            'first_bonus_bytes' => $firstBonusBytes,
        ]);
    }

    public static function hasFirstBonus(int $userId): bool
    {
        return PaygLog::where('user_id', $userId)->where('first_bonus_bytes', '>', 0)->exists();
    }

    /** 报价页预览：这一单开通时会送多少（档位 + 首充）。 */
    public function quoteBonus(Plan $plan, int $listAmount, ?User $user, ?PaygConfig $config = null): array
    {
        $config ??= PaygConfig::fromSettings();
        $unit = PaygConfig::unitPrice($plan);
        return [
            'bonus_bytes' => $unit ? PaygConfig::bytesFor($unit, $config->bonusFor($listAmount)) : 0,
            'first_bonus_bytes' => $config->firstBonusGb > 0 && (!$user || !self::hasFirstBonus((int) $user->id))
                ? $config->firstBonusGb * self::GB : 0,
        ];
    }

    /** 套餐列表里按量套餐的 payg 字段；其余套餐 null。 */
    public function planPayload(Plan $plan, ?User $user, ?PaygConfig $config = null): ?array
    {
        $config ??= PaygConfig::fromSettings();
        if (!$config->isPaygPlanId($plan->id)) {
            return null;
        }
        $unit = PaygConfig::unitPrice($plan);
        if ($unit === null) {
            return null;
        }
        return [
            'unit_price' => self::number($unit),
            'min_amount' => PaygConfig::baseAmount($plan),
            'monthly_floor' => $config->monthlyFloor,
            'first_bonus_gb' => $config->firstBonusGb,
            'first_bonus_eligible' => $config->firstBonusGb > 0 && (!$user || !self::hasFirstBonus((int) $user->id)),
            'bonus_tiers' => $config->tiers,
            'auto_topup' => [
                'site_enabled' => $config->autoTopupEnable,
                'threshold' => $config->autoTopupThreshold,
                'amount' => PaygConfig::baseAmount($plan),
            ],
        ];
    }

    // ───────────────────────── 换包月：折抵 ─────────────────────────

    /**
     * 从按量钱包换到别的套餐时的折抵额：按这个钱包实付过的钱 × 剩余流量占发放总量的比例。
     * 发放总量含赠送和已被保底扣掉的部分，所以赠送的流量不能折成钱、保底扣掉的也不会再退一次。
     * 原一次性套餐的算法只看最后一单，钱包叠加之后会把前面几单的钱漏掉，这里按整个钱包算。
     */
    public function applySurplus(User $user, Order $order): void
    {
        $opening = PaygLog::where('user_id', $user->id)->where('plan_id', $user->plan_id)
            ->where('opening', 1)->orderByDesc('id')->first();
        $paid = (int) Order::where('user_id', $user->id)->where('plan_id', $user->plan_id)
            ->whereIn('period', [Plan::PERIOD_ONETIME, Plan::PERIOD_TRAFFIC_TOPUP])
            ->where('status', Order::STATUS_COMPLETED)
            ->when($opening && $opening->order_id, fn ($q) => $q->where('id', '>=', $opening->order_id))
            ->get(['total_amount', 'balance_amount', 'surplus_amount'])
            ->sum(fn (Order $o) => max(0, (int) $o->total_amount) + max(0, (int) ($o->balance_amount ?? 0)) + max(0, (int) ($o->surplus_amount ?? 0)));
        if ($paid <= 0) {
            return;
        }
        $deducted = (int) PaygLog::where('user_id', $user->id)->where('type', PaygLog::TYPE_FLOOR)
            ->when($opening, fn ($q) => $q->where('id', '>', $opening->id))
            ->sum('bytes');
        $granted = (int) $user->transfer_enable + $deducted;
        $remaining = (int) $user->transfer_enable - (int) ($user->u ?? 0) - (int) ($user->d ?? 0);
        if ($granted <= 0 || $remaining <= 0) {
            return;
        }
        $order->surplus_amount = (int) floor($paid * min(1, $remaining / $granted));
        $order->surplus_order_ids = Order::where('user_id', $user->id)
            ->where('period', '!=', Plan::PERIOD_RESET_TRAFFIC)
            ->where('status', Order::STATUS_COMPLETED)
            ->pluck('id')
            ->all();
    }

    // ───────────────────────── 用量 ─────────────────────────

    /** 当前钱包从什么时候开始（最近一次开新钱包的时刻）；没有记录返回 null（不设下限）。 */
    private static function walletStartedAt(User $user): ?int
    {
        $opening = PaygLog::where('user_id', $user->id)->where('plan_id', $user->plan_id)
            ->where('opening', 1)->orderByDesc('id')->first();
        return $opening ? (int) $opening->created_at : null;
    }

    /**
     * 计费用量（字节，已按倍率）：v2_stat_user 的日记录，[from, to)。
     * 钱包开在区间中间时只算开钱包那天起的用量，换过来之前用的是别的套餐的流量。
     *
     * @return array{bytes: int, by_rate: list<array{rate: int|float, bytes: int}>}
     */
    public static function usage(User $user, int $from, int $to, bool $byRate = false): array
    {
        $startedAt = self::walletStartedAt($user);
        if ($startedAt !== null) {
            // 与 StatUserJob 的 record_at 同一口径：本地时区当天 0 点
            $from = max($from, (int) strtotime(date('Y-m-d', $startedAt)));
        }
        if ($from >= $to) {
            return ['bytes' => 0, 'by_rate' => []];
        }
        $query = StatUser::query()->where('user_id', $user->id)->where('record_type', 'd')
            ->where('record_at', '>=', $from)->where('record_at', '<', $to);
        if (!$byRate) {
            return ['bytes' => (int) $query->sum(DB::raw('u + d')), 'by_rate' => []];
        }
        $rows = $query->groupBy('server_rate')->selectRaw('server_rate, SUM(u + d) AS total')->get();
        $byRateList = [];
        $total = 0;
        foreach ($rows as $row) {
            $bytes = (int) $row->total;
            $total += $bytes;
            $byRateList[] = ['rate' => self::number((float) $row->server_rate), 'bytes' => $bytes];
        }
        usort($byRateList, fn ($a, $b) => $a['rate'] <=> $b['rate']);
        return ['bytes' => $total, 'by_rate' => $byRateList];
    }

    // ───────────────────────── 月度保底 ─────────────────────────

    /**
     * 结算某个月的保底：这个月有用量、但按单价折算不足保底的，从钱包里补扣差额（余额不够就扣到 0 为止，不欠费）。
     * 没用的月份不扣。每人每月只结算一次（唯一键兜底并发）。
     *
     * @return array{status: string, amount?: int, bytes?: int}
     */
    public function settleFloor(User $user, Carbon $month, bool $dryRun = false, ?PaygConfig $config = null): array
    {
        $config ??= PaygConfig::fromSettings();
        if ($config->monthlyFloor <= 0) return ['status' => 'floor_off'];
        if (!self::isWallet($user, $config)) return ['status' => 'not_wallet'];
        $plan = Plan::find($user->plan_id);
        $unit = $plan ? PaygConfig::unitPrice($plan) : null;
        if ($unit === null) return ['status' => 'no_unit_price'];

        $monthKey = $month->format('Y-m');
        $from = (int) $month->copy()->startOfMonth()->timestamp;
        $to = (int) $month->copy()->startOfMonth()->addMonth()->timestamp;
        if ($to > time()) return ['status' => 'month_open'];
        if (PaygLog::where('user_id', $user->id)->where('type', PaygLog::TYPE_FLOOR)->where('month', $monthKey)->exists()) {
            return ['status' => 'settled'];
        }
        $usage = self::usage($user, $from, $to)['bytes'];
        if ($usage <= 0) return ['status' => 'unused'];
        $usedCents = PaygConfig::centsFor($unit, $usage);
        $shortfall = $config->monthlyFloor - $usedCents;
        if ($shortfall <= 0) return ['status' => 'above_floor'];
        $want = PaygConfig::bytesFor($unit, $shortfall);
        if ($dryRun) return ['status' => 'would_charge', 'amount' => $shortfall, 'bytes' => $want];

        try {
            return DB::transaction(function () use ($user, $plan, $unit, $monthKey, $usage, $shortfall, $want, $config) {
                $locked = User::lockForUpdate()->find($user->id);
                if (!$locked || !self::isWallet($locked, $config) || (int) $locked->plan_id !== (int) $plan->id) {
                    return ['status' => 'not_wallet'];
                }
                $remaining = max(0, (int) $locked->transfer_enable - (int) ($locked->u ?? 0) - (int) ($locked->d ?? 0));
                $take = min($want, $remaining);
                $charged = $take === $want ? $shortfall : PaygConfig::centsFor($unit, $take);
                if ($take > 0) {
                    $locked->transfer_enable = (int) $locked->transfer_enable - $take;
                    if (!$locked->save()) {
                        throw new \RuntimeException('用户信息保存失败');
                    }
                }
                // 余额已经是 0 也记一行（金额 0）：这个月算结算过了，明细里看得到原因
                PaygLog::create([
                    'user_id' => $locked->id, 'plan_id' => $plan->id, 'type' => PaygLog::TYPE_FLOOR,
                    'month' => $monthKey, 'amount' => $charged, 'bytes' => $take, 'usage_bytes' => $usage,
                ]);
                return ['status' => 'charged', 'amount' => $charged, 'bytes' => $take];
            });
        } catch (QueryException $e) {
            // 唯一键冲突 = 另一个进程刚结算了同一个月：事务已回滚，不会扣两次
            if (PaygLog::where('user_id', $user->id)->where('type', PaygLog::TYPE_FLOOR)->where('month', $monthKey)->exists()) {
                return ['status' => 'settled'];
            }
            throw $e;
        }
    }

    // ───────────────────────── 自动补充 ─────────────────────────

    /**
     * 自动补充：余额低于门槛、开了开关、账户余额够一份时，按套餐基础规格（一次性价）下单，
     * 全额用账户余额抵扣，走与收银台 0 元订单相同的 paid() → 开通链路。
     * 与自动续费同一条硬规则：余额不够整份就不下单，下单后金额没归零就整体回滚，不留待付订单。
     *
     * @return array{status: string, reason?: string, trade_no?: string}
     */
    public function attemptAutoTopup(User $user, bool $dryRun = false, ?PaygConfig $config = null): array
    {
        $config ??= PaygConfig::fromSettings();
        $user = User::find($user->id);
        if (!$config->autoTopupEnable) return ['status' => 'skipped', 'reason' => 'site_disabled'];
        if (!$user || !$user->payg_auto_topup || $user->banned || !self::isWallet($user, $config)) {
            return ['status' => 'skipped', 'reason' => 'not_eligible'];
        }
        $plan = Plan::find($user->plan_id);
        $unit = $plan ? PaygConfig::unitPrice($plan) : null;
        if ($unit === null) return ['status' => 'skipped', 'reason' => 'no_unit_price'];
        $remaining = (int) $user->transfer_enable - (int) ($user->u ?? 0) - (int) ($user->d ?? 0);
        if ($remaining >= PaygConfig::bytesFor($unit, $config->autoTopupThreshold)) {
            return ['status' => 'skipped', 'reason' => 'above_threshold'];
        }
        if (app(UserService::class)->isNotCompleteOrderByUserId($user->id)) {
            return ['status' => 'skipped', 'reason' => 'pending_order'];
        }
        $today = (int) Carbon::today()->timestamp;
        if (PaygLog::where('user_id', $user->id)->where('type', PaygLog::TYPE_AUTO_TOPUP)
            ->where('created_at', '>=', $today)->count() >= self::AUTO_TOPUP_DAILY_LIMIT) {
            return ['status' => 'skipped', 'reason' => 'daily_limit'];
        }
        $customizer = app(PlanCustomizationService::class);
        $options = $customizer->baseOptions($plan);
        try {
            $quote = $customizer->quote($plan, Plan::PERIOD_ONETIME, $options, $user);
        } catch (ApiException $e) {
            return ['status' => 'unavailable', 'reason' => $e->getMessage()];
        }
        $discount = max(0, min(100, (int) ($user->discount ?? 0)));
        $amount = max(0, (int) $quote['amount'] - (int) round((int) $quote['amount'] * $discount / 100));
        if ((int) ($user->balance ?? 0) < $amount) {
            return ['status' => 'insufficient', 'reason' => 'balance_short'];
        }
        if ($dryRun) return ['status' => 'would_topup'];

        try {
            $order = DB::transaction(function () use ($user, $plan, $quote) {
                $order = OrderService::createFromRequest($user, $plan, Plan::PERIOD_ONETIME, null, $quote['options'], (int) $quote['amount']);
                // createFromRequest 已按余额抵扣；报价之后余额被别处花掉了就整体回滚，不留待付单
                if ((int) $order->total_amount !== 0) {
                    throw new \RuntimeException('balance_short');
                }
                return $order;
            });
        } catch (\Throwable $e) {
            if ($e->getMessage() === 'balance_short') {
                return ['status' => 'insufficient', 'reason' => 'balance_short'];
            }
            Log::error('payg.auto_topup.create_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            return ['status' => 'error', 'reason' => $e->getMessage()];
        }
        if (!(new OrderService($order))->paid(self::AUTO_TOPUP_CALLBACK_PREFIX . $order->trade_no)) {
            // 钱已扣、单已建、没能开通：单子仍是 0 元待付，用户在收银台点一下即可完成
            Log::error('payg.auto_topup.paid_failed', ['user_id' => $user->id, 'trade_no' => $order->trade_no]);
            return ['status' => 'error', 'reason' => 'paid_failed', 'trade_no' => $order->trade_no];
        }
        return ['status' => 'topped_up', 'trade_no' => $order->trade_no];
    }

    // ───────────────────────── 面板与明细 ─────────────────────────

    /** getSubscribe 的 payg：当前是按量钱包时给摘要，否则 null。 */
    public function status(User $user, ?PaygConfig $config = null): ?array
    {
        $config ??= PaygConfig::fromSettings();
        if (!self::isWallet($user, $config)) return null;
        $plan = Plan::find($user->plan_id);
        $unit = $plan ? PaygConfig::unitPrice($plan) : null;
        if ($unit === null) return null;

        $now = Carbon::now();
        $thisMonth = self::usage($user, (int) $now->copy()->startOfMonth()->timestamp, time() + 1);
        $last = $this->monthSummary($user, $now->copy()->startOfMonth()->subMonth(), $unit, $config, false);
        $amount = PaygConfig::baseAmount($plan);
        $balance = (int) ($user->balance ?? 0);
        return [
            'plan_id' => (int) $plan->id,
            'balance_bytes' => max(0, (int) $user->transfer_enable - (int) ($user->u ?? 0) - (int) ($user->d ?? 0)),
            'unit_price' => self::number($unit),
            'month_used' => PaygConfig::centsFor($unit, $thisMonth['bytes']),
            'month_used_bytes' => $thisMonth['bytes'],
            'monthly_floor' => $config->monthlyFloor,
            'last_month' => [
                'month' => $last['month'],
                'used' => $last['used'],
                'floor_charged' => $last['floor_charged'],
                'settled' => $last['settled'],
            ],
            'auto_topup' => [
                'enabled' => (bool) $user->payg_auto_topup,
                'site_enabled' => $config->autoTopupEnable,
                'threshold' => $config->autoTopupThreshold,
                'amount' => $amount,
                'balance_enough' => $balance >= $amount,
            ],
        ];
    }

    /** /user/payg/ledger：近三个月的用量与保底、最近 50 笔充值 / 自动补充 / 保底。 */
    public function ledger(User $user, ?PaygConfig $config = null): array
    {
        $config ??= PaygConfig::fromSettings();
        if (!self::isWallet($user, $config)) {
            throw new ApiException('当前不是按量套餐');
        }
        $plan = Plan::find($user->plan_id);
        $unit = $plan ? PaygConfig::unitPrice($plan) : null;
        if ($unit === null) {
            throw new ApiException('当前不是按量套餐');
        }
        $months = [];
        $cursor = Carbon::now()->startOfMonth();
        for ($i = 0; $i < self::LEDGER_MONTHS; $i++) {
            $months[] = $this->monthSummary($user, $cursor->copy(), $unit, $config, true);
            $cursor->subMonth();
        }
        $orders = [];
        $logs = PaygLog::where('user_id', $user->id)->orderByDesc('id')->limit(self::LEDGER_LIMIT)->get();
        $tradeNos = Order::whereIn('id', $logs->pluck('order_id')->filter()->all())->pluck('trade_no', 'id')->all();
        foreach ($logs as $log) {
            $orders[] = [
                'type' => $log->type,
                'at' => (int) $log->created_at,
                'trade_no' => $log->order_id ? ($tradeNos[$log->order_id] ?? null) : null,
                'amount' => (int) $log->amount,
                'bytes' => (int) $log->bytes,
                'bonus_bytes' => (int) $log->bonus_bytes,
                'first_bonus_bytes' => (int) $log->first_bonus_bytes,
            ];
        }
        return [
            'unit_price' => self::number($unit),
            'monthly_floor' => $config->monthlyFloor,
            'months' => $months,
            'entries' => $orders,
        ];
    }

    private function monthSummary(User $user, Carbon $month, float $unit, PaygConfig $config, bool $byRate): array
    {
        $from = (int) $month->copy()->startOfMonth()->timestamp;
        $to = (int) $month->copy()->startOfMonth()->addMonth()->timestamp;
        $usage = self::usage($user, $from, min($to, time() + 1), $byRate);
        $used = PaygConfig::centsFor($unit, $usage['bytes']);
        $floor = PaygLog::where('user_id', $user->id)->where('type', PaygLog::TYPE_FLOOR)
            ->where('month', $month->format('Y-m'))->first();
        $due = $config->monthlyFloor > 0 && $usage['bytes'] > 0 && $used < $config->monthlyFloor;
        return [
            'month' => $month->format('Y-m'),
            'used' => $used,
            'used_bytes' => $usage['bytes'],
            ...($byRate ? ['by_rate' => $usage['by_rate']] : []),
            'floor_charged' => $floor ? (int) $floor->amount : 0,
            'floor_bytes' => $floor ? (int) $floor->bytes : 0,
            // 本月恒为 false；过去的月份：结算过，或者本来就不用补（没用 / 已过保底）
            'settled' => $to <= time() && ($floor !== null || !$due),
        ];
    }

    /** 15.0 → 15：JSON 里整数单价不带小数点 */
    private static function number(float $value): int|float
    {
        return $value == (int) $value ? (int) $value : $value;
    }
}
