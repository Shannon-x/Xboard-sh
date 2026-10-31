<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\OrderService;
use App\Utils\Helper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 套餐变更折抵：剩余时间要按「哪段时间是哪张单付的钱」逐张计值，不能把几张单揉成一个均价。
 * 续费叠在旧周期后面——旧单覆盖眼前这段、新单覆盖后面那段。
 */
class SurplusPerOrderValuationTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1073741824;
    private const DAY = 86400;
    private const MONTH = 30 * 86400;

    private ServerGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->group = new ServerGroup();
        $this->group->name = 'base';
        $this->group->save();
    }

    private function plan(int $monthlyYuan, array $extraPrices = []): Plan
    {
        return Plan::create([
            'name' => 'P' . $monthlyYuan . Str::random(4), 'capacity_limit' => null, 'group_id' => $this->group->id,
            'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => 100,
            'reset_traffic_method' => 1,
            'prices' => ['monthly' => $monthlyYuan] + $extraPrices,
        ]);
    }

    private function user(Plan $plan, int $expiredAt, int $nextResetAt, int $lastResetAt, int $usedGb = 0): User
    {
        return User::create([
            'email' => Str::random(12) . '@example.test', 'password' => 'test',
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'plan_id' => $plan->id, 'group_id' => $plan->group_id, 'balance' => 0,
            'transfer_enable' => 100 * self::GB, 'u' => $usedGb * self::GB, 'd' => 0,
            'device_limit' => 2, 'speed_limit' => 100,
            'expired_at' => $expiredAt,
            'next_reset_at' => $nextResetAt,
            'last_reset_at' => $lastResetAt,
        ]);
    }

    private function paidOrder(User $user, Plan $plan, string $period, int $cents, int $createdAt): Order
    {
        $order = Order::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'period' => $period, 'type' => Order::TYPE_RENEWAL,
            'trade_no' => Helper::generateOrderNo(), 'total_amount' => $cents, 'status' => Order::STATUS_COMPLETED,
        ]);
        $order->created_at = $createdAt;
        $order->save();
        return $order;
    }

    private function changeTo(User $user, Plan $target, string $period = 'monthly'): Order
    {
        $order = new Order([
            'user_id' => $user->id, 'plan_id' => $target->id, 'period' => $period,
            'total_amount' => (int) round($target->prices[$period] * 100),
        ]);
        (new OrderService($order))->setOrderType($user->fresh());
        return $order;
    }

    /** 折抵总值 = 抵掉的 + 退回余额的。 */
    private function credit(Order $order): int
    {
        return (int) $order->surplus_amount + (int) $order->refund_amount;
    }

    /** 与 OrderService 一样按站点时区做日历运算（Carbon 3 的 createFromTimestamp 默认是 UTC）。 */
    private function at(int $ts): Carbon
    {
        return Carbon::createFromTimestamp($ts, config('app.timezone'));
    }

    private function addMonth(int $ts, int $months = 1): int
    {
        return $this->at($ts)->addMonths($months)->timestamp;
    }

    public function test_pricier_stacked_renewal_is_valued_at_its_own_price(): void
    {
        // #3440 原样：¥50 旧单再过 4 分钟就到期，刚续费了带增值线路的 ¥96 月付（叠在旧到期日之后）。
        // 旧均价公式：(50+96)/2=¥73 × 1.03 ≈ ¥75.5；应当是 ¥96 + 旧单那 4 分钟。
        $now = time();
        $oldEnd = $now + 240;
        $plan = $this->plan(50);
        $user = $this->user($plan, $this->addMonth($oldEnd), $oldEnd, $oldEnd - self::MONTH);
        $this->paidOrder($user, $plan, 'monthly', 5000, $this->at($oldEnd)->subMonth()->timestamp);
        $this->paidOrder($user, $plan, 'monthly', 9600, $now - 60);

        $order = $this->changeTo($user, $this->plan(50));

        // 旧到期日落在 29–31 号时 addMonth 会溢出（10-31 + 1 月 = 12-01），实现按 subMonthsNoOverflow 反推
        // 新单覆盖期（11-01 起），中间多出的 1–3 天是用户实打实剩下的时间，按旧单单价算给旧单。
        // 上限把这几天加进去，让断言与运行日期无关；平常日子里这一项是 0。
        $newCoverStart = $this->at((int) $user->expired_at)->subMonthNoOverflow()->timestamp;
        $overflowValue = (int) ceil(5000 * max(0, $newCoverStart - $oldEnd) / self::MONTH);

        $this->assertSame(Order::TYPE_UPGRADE, (int) $order->type);
        $this->assertGreaterThanOrEqual(9600, $this->credit($order), '一天没用的 ¥96 续费单必须按 ¥96 折抵');
        $this->assertLessThanOrEqual(9600 + 5 + $overflowValue, $this->credit($order), '旧单只剩 4 分钟，只值几分钱');
    }

    public function test_cheaper_stacked_renewal_is_not_overvalued(): void
    {
        // 反向：¥96 的单还剩半个月（流量没用），后面叠了一张 ¥50 续费。应 ≈ ¥50 + ¥96×0.5 = ¥98；
        // 旧均价公式会给 ¥73 × 1.5 ≈ ¥110，多折约 ¥12。
        $now = time();
        // 同 test_equal_priced_stacked_orders_match_previous_formula：旧到期日压回 28 号以内，避开 addMonth 溢出。
        $oldEndAt = $this->at($now + 15 * self::DAY);
        if ($oldEndAt->day > 28) {
            $oldEndAt->setDay(28);
        }
        $oldEnd = $oldEndAt->timestamp;
        $plan = $this->plan(96);
        $user = $this->user($plan, $this->addMonth($oldEnd), $oldEnd, $oldEnd - self::MONTH);
        $this->paidOrder($user, $plan, 'monthly', 9600, $this->at($oldEnd)->subMonth()->timestamp);
        $this->paidOrder($user, $plan, 'monthly', 5000, $now - 60);

        $order = $this->changeTo($user, $this->plan(30));

        $this->assertEqualsWithDelta(5000 + 9600 * (($oldEnd - $now) / self::MONTH), $this->credit($order), 10);
    }

    public function test_single_order_value_matches_previous_formula_exactly(): void
    {
        // 只有一张单（绝大多数用户）：结果必须和原来的均价公式逐分一致。季付 ¥114，第 20 天，用了 30% 流量。
        $now = time();
        $created = $now - 20 * self::DAY;
        $cycleEnd = $this->addMonth($created);
        $expiredAt = $this->addMonth($created, 3);
        $plan = $this->plan(40, ['quarterly' => 114]);
        $user = $this->user($plan, $expiredAt, $cycleEnd, $created, 30);
        // 保存时流量重置日会按重置规则重算（不溢出：1-29 起的周期在 2-28 重置，而不是 addMonth 的 3-01），
        // 期望值要用库里实际的重置日，否则在每月 29–31 号起算的周期上差几分钱。
        $cycleEnd = (int) $user->fresh()->next_reset_at;
        $this->paidOrder($user, $plan, 'quarterly', 11400, $created);

        $order = $this->changeTo($user, $this->plan(10));

        $monthly = 11400 / 3;
        $timeRatio = min(1, ($cycleEnd - $now) / max(28 * self::DAY, $cycleEnd - $created));
        $expected = (int) min(11400, $monthly * min($timeRatio, 0.7) + $monthly * (($expiredAt - $cycleEnd) / self::MONTH));
        $this->assertEqualsWithDelta($expected, $this->credit($order), 2, '单张订单不应受本次修改影响');
    }

    public function test_equal_priced_stacked_orders_match_previous_formula(): void
    {
        // 两张同价月付叠加：均价 = 单价，逐张计值与原公式相同。
        $now = time();
        // 旧到期日落在 29–31 号时 addMonth 会溢出（10-31 + 1 月 = 12-01），而实现按 subMonthsNoOverflow
        // 反推新单覆盖期（11-01 起），中间多出的一天会算给旧单，断言在每月 19–21 号必挂。
        // 把到期日压回 28 号以内，让这条测试与运行日期无关；期望值按实际剩余秒数计算。
        $oldEndAt = $this->at($now + 10 * self::DAY);
        if ($oldEndAt->day > 28) {
            $oldEndAt->setDay(28);
        }
        $oldEnd = $oldEndAt->timestamp;
        $expiredAt = $this->addMonth($oldEnd);
        $plan = $this->plan(40);
        $user = $this->user($plan, $expiredAt, $oldEnd, $oldEnd - self::MONTH);
        $this->paidOrder($user, $plan, 'monthly', 4000, $this->at($oldEnd)->subMonth()->timestamp);
        $this->paidOrder($user, $plan, 'monthly', 4000, $now - 3600);

        $order = $this->changeTo($user, $this->plan(10));

        // 旧单剩 (oldEnd-now)/30 天 个周期（通常 10/30）；新单一天没用，按它实际覆盖的日历月折算 = 实付 ¥40
        //（原公式按 30 天口径，31 天的月份会算成 ¥41.33、2 月算成 ¥37.33）。
        $this->assertEqualsWithDelta(4000 * (($oldEnd - $now) / self::MONTH) + 4000, $this->credit($order), 3);
    }

    public function test_restarted_renewal_gives_no_value_to_the_exhausted_old_order(): void
    {
        // 流量用尽后续费重开：周期从付款时刻重算，旧单剩下的"零流量空壳"已被放弃。
        // 剩余时间全部属于 ¥50 的新单；旧均价公式会把 ¥30 旧单掺进来，只折 ¥40×0.97。
        $now = time();
        // 重开日压回 28 号以内（最多再往前挪 3 天）：1-30 重开的月付到 3-02，实现反推新单覆盖期从 2-02 起，
        // 中间两天会按旧单单价计值，这条断言在每年 1-29～1-31 必挂。理由同 test_equal_priced_stacked_orders_match_previous_formula。
        $restartAtDate = $this->at($now - self::DAY);
        if ($restartAtDate->day > 28) {
            $restartAtDate->setDay(28);
        }
        $restartAt = $restartAtDate->timestamp;
        $expiredAt = $this->addMonth($restartAt);
        $plan = $this->plan(30);
        $user = $this->user($plan, $expiredAt, $expiredAt, $restartAt);
        $this->paidOrder($user, $plan, 'monthly', 3000, $now - 10 * self::DAY);
        $this->paidOrder($user, $plan, 'monthly', 5000, $restartAt);

        $order = $this->changeTo($user, $this->plan(10));

        $expected = 5000 * min(1, ($expiredAt - $now) / max(28 * self::DAY, $expiredAt - $restartAt));
        $this->assertEqualsWithDelta($expected, $this->credit($order), 5);
    }

    public function test_compensation_days_never_push_value_above_what_was_paid(): void
    {
        // 两张叠加单 + 末尾 20 天补偿：补偿天数照旧可以算进折抵，但每张单都不超过自己的实付，总额不超过两单实付合计。
        $now = time();
        $oldEnd = $now + 5 * self::DAY;
        $expiredAt = $this->addMonth($oldEnd) + 20 * self::DAY;
        $plan = $this->plan(40);
        $user = $this->user($plan, $expiredAt, $oldEnd, $oldEnd - self::MONTH);
        $this->paidOrder($user, $plan, 'monthly', 4000, $this->at($oldEnd)->subMonth()->timestamp);
        $this->paidOrder($user, $plan, 'monthly', 6000, $now - 3600);

        $order = $this->changeTo($user, $this->plan(10));

        $this->assertGreaterThanOrEqual(6000, $this->credit($order), '新单一天没用，至少值它的实付');
        $this->assertLessThanOrEqual(10000, $this->credit($order), '总额不超过两单实付合计');
    }

    public function test_preview_reports_the_same_surplus_as_the_real_order(): void
    {
        // 续费助手的预演与真实下单走同一段逻辑。
        $now = time();
        $oldEnd = $now + 240;
        $plan = $this->plan(50);
        $user = $this->user($plan, $this->addMonth($oldEnd), $oldEnd, $oldEnd - self::MONTH);
        $this->paidOrder($user, $plan, 'monthly', 5000, $this->at($oldEnd)->subMonth()->timestamp);
        $this->paidOrder($user, $plan, 'monthly', 9600, $now - 60);
        $target = $this->plan(50);

        $preview = OrderService::previewOrderType($user->fresh(), $target, 'monthly', ['amount' => 5000]);
        $order = $this->changeTo($user, $target);

        $this->assertSame((int) $order->surplus_amount, $preview['surplus_amount']);
        $this->assertSame(0, $preview['payable']);
    }
}
