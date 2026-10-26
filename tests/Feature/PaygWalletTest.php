<?php

namespace Tests\Feature;

use App\Jobs\SendBillingMailJob;
use App\Jobs\SendEmailJob;
use App\Models\Order;
use App\Models\PaygLog;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\StatUser;
use App\Models\User;
use App\Services\OrderService;
use App\Services\Payg\PaygConfig;
use App\Services\Payg\PaygService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 上网按量钱包：按量套餐是一次性周期的普通套餐，钱包余额 = transfer_enable − u − d（一个账本）。
 *
 *   - 同一按量套餐再买 = 续费（不折抵），流量叠加、已用不清零；没配置 payg_plan_ids 时与原一次性套餐逐字相同（覆盖）。
 *   - 赠送在开通时加：本单折前金额满档位送若干元（换成 GB），每个用户第一次充值另送 N GB。
 *   - 换包月：按整个钱包实付 × 剩余 / 发放总量折抵（赠送不折成钱）。
 *   - 月度保底：有使用但不足 ¥5 的月份从余额补扣差额，余额不够扣到 0；每人每月一次。
 *   - 自动补充：低于 ¥3 且开了开关，用账户余额买一份；余额不够不下单。
 */
class PaygWalletTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1073741824;

    private ServerGroup $base;
    private ServerGroup $sanwang;
    /** 同一测试里的多个钱包用同一个按量套餐（paygPlan() 每次都会把 payg_plan_ids 改成新建的那个） */
    private ?Plan $walletPlan = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['v2board.email_verify_nudge_enable' => 0]);
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        app()->forgetScopedInstances();
        Bus::fake([SendEmailJob::class, SendBillingMailJob::class]);
        $this->base = $this->group('不限时套餐中');
        $this->sanwang = $this->group('三网优化 10x');
    }

    private function group(string $name): ServerGroup
    {
        $g = new ServerGroup();
        $g->name = $name;
        $g->save();
        return $g;
    }

    /** ¥15 / 100 GB 起充，按 GB 加量 15 分/GB（与单价同价），三网组随套餐包含。 */
    private function paygPlan(bool $configure = true): Plan
    {
        $plan = Plan::create([
            'name' => '上网按量', 'capacity_limit' => null, 'group_id' => $this->base->id,
            'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 100, 'device_limit' => 3, 'speed_limit' => null,
            'reset_traffic_method' => Plan::RESET_TRAFFIC_NEVER,
            'prices' => ['onetime' => 15],
            'customization' => [
                'transfer_enable' => ['mode' => 'range', 'max' => 10000, 'step' => 1, 'price_per_step' => 15],
                'device_limit' => ['mode' => 'fixed'], 'speed_limit' => ['mode' => 'fixed'],
                'addon_groups' => [(string) $this->sanwang->id => ['mode' => 'included']],
            ],
        ]);
        if ($configure) {
            config(['v2board.payg_plan_ids' => [$plan->id]]);
        }
        return $plan;
    }

    private function monthlyPlan(): Plan
    {
        return Plan::create([
            'name' => '上网轻享', 'capacity_limit' => null, 'group_id' => $this->base->id,
            'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 150, 'device_limit' => 3, 'speed_limit' => null,
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'prices' => ['monthly' => 10],
        ]);
    }

    private function user(array $attributes = []): User
    {
        return User::create($attributes + [
            'email' => Str::random(12) . '@example.test', 'password' => 'test',
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'plan_id' => null, 'group_id' => null, 'balance' => 0,
            'transfer_enable' => 0, 'u' => 0, 'd' => 0, 'device_limit' => null, 'speed_limit' => null,
            'expired_at' => null,
        ]);
    }

    private function gbOptions(int $gb): array
    {
        return ['transfer_enable' => $gb, 'device_limit' => 3, 'speed_limit' => null];
    }

    private function buy(User $user, Plan $plan, int $gb): Order
    {
        $order = OrderService::createFromRequest($user->fresh(), $plan, 'onetime', null, $this->gbOptions($gb));
        return $this->open($order);
    }

    private function open(Order $order): Order
    {
        $order->status = Order::STATUS_PROCESSING;
        $order->callback_no = 'test:' . $order->trade_no;
        $order->save();
        (new OrderService($order))->open();
        return $order->fresh();
    }

    private function bonusBytes(int $cents): int
    {
        return PaygConfig::bytesFor(15, $cents);
    }

    // ───────────────────────── 开通：叠加与赠送 ─────────────────────────

    public function test_first_purchase_opens_a_wallet_with_the_first_recharge_bonus(): void
    {
        $plan = $this->paygPlan();
        $user = $this->user(['u' => 3 * self::GB, 'd' => 4 * self::GB]);   // 以前用剩的流量统计

        $order = $this->buy($user, $plan, 100);
        $user->refresh();
        $this->assertSame(Order::TYPE_NEW_PURCHASE, (int) $order->type);
        $this->assertSame(1500, (int) $order->total_amount);
        $this->assertSame((100 + 10) * self::GB, (int) $user->transfer_enable, '100 GB + 首充 10 GB');
        $this->assertSame(0, (int) $user->u + (int) $user->d, '开新钱包：已用清零（与原一次性套餐相同）');
        $this->assertNull($user->expired_at);
        $this->assertSame($plan->id, (int) $user->plan_id);

        $log = PaygLog::sole();
        $this->assertSame([PaygLog::TYPE_RECHARGE, 1, 1500, 100 * self::GB, 0, 10 * self::GB],
            [$log->type, $log->opening, $log->amount, $log->bytes, $log->bonus_bytes, $log->first_bonus_bytes]);
    }

    public function test_rebuying_stacks_traffic_keeps_usage_and_adds_tier_bonuses(): void
    {
        $plan = $this->paygPlan();
        $user = $this->user();
        $this->buy($user, $plan, 100);
        User::where('id', $user->id)->update(['u' => 2 * self::GB, 'd' => 8 * self::GB]);

        // ¥105（700 GB）过了 ¥100 档：送 ¥10 = 66.67 GB
        $order = $this->buy($user, $plan, 700);
        $user->refresh();
        $this->assertSame(Order::TYPE_RENEWAL, (int) $order->type, '规格不同也是续费：不能走套餐变更把钱包折抵掉');
        $this->assertSame(0, (int) $order->surplus_amount);
        $this->assertSame(10500, (int) $order->total_amount);
        $this->assertSame(110 * self::GB + 700 * self::GB + $this->bonusBytes(1000), (int) $user->transfer_enable);
        $this->assertSame(10 * self::GB, (int) $user->u + (int) $user->d, '已用不清零');

        // ¥300（2000 GB）：两档都满足，取赠送多的 ¥45 = 300 GB；首充只送一次
        $before = (int) $user->transfer_enable;
        $this->buy($user, $plan, 2000);
        $this->assertSame($before + 2000 * self::GB + $this->bonusBytes(4500), (int) $user->fresh()->transfer_enable);
        $this->assertSame(300 * self::GB, $this->bonusBytes(4500));

        $logs = PaygLog::orderBy('id')->get();
        $this->assertSame([1, 0, 0], $logs->pluck('opening')->all());
        $this->assertSame([10 * self::GB, 0, 0], $logs->pluck('first_bonus_bytes')->all());
        $this->assertSame([0, $this->bonusBytes(1000), $this->bonusBytes(4500)], $logs->pluck('bonus_bytes')->all());
    }

    public function test_without_payg_config_onetime_plans_behave_exactly_as_before(): void
    {
        $plan = $this->paygPlan(configure: false);
        $user = $this->user();
        $this->buy($user, $plan, 100);
        $this->assertSame(100 * self::GB, (int) $user->fresh()->transfer_enable, '没认定为按量套餐：不送首充');
        User::where('id', $user->id)->update(['u' => self::GB]);

        $order = $this->buy($user, $plan, 700);
        $user->refresh();
        $this->assertSame(Order::TYPE_UPGRADE, (int) $order->type, '原逻辑：改了流量规格 = 套餐变更');
        $this->assertSame(700 * self::GB, (int) $user->transfer_enable, '原逻辑：覆盖');
        $this->assertSame(0, (int) $user->u + (int) $user->d);
        $this->assertSame(0, PaygLog::count());
    }

    public function test_rebuy_ignores_the_open_event_that_would_reset_usage(): void
    {
        config(['v2board.renew_order_event_id' => 1, 'v2board.new_order_event_id' => 1]);
        $plan = $this->paygPlan();
        $user = $this->user();
        $this->buy($user, $plan, 100);
        User::where('id', $user->id)->update(['d' => 50 * self::GB]);
        $this->buy($user, $plan, 100);
        $this->assertSame(50 * self::GB, (int) $user->fresh()->d, '「开通后重置流量」会把钱包已用清零 = 白送，按量套餐不触发');
    }

    public function test_switching_to_a_monthly_plan_credits_the_whole_wallet_but_not_the_bonuses(): void
    {
        $plan = $this->paygPlan();
        $monthly = $this->monthlyPlan();
        $user = $this->user();
        $this->buy($user, $plan, 100);   // ¥15 → 110 GB（含首充 10 GB）
        $this->buy($user, $plan, 200);   // ¥30 → 200 GB
        User::where('id', $user->id)->update(['u' => 10 * self::GB]);

        $order = OrderService::createFromRequest($user->fresh(), $monthly, 'monthly');
        $this->assertSame(Order::TYPE_UPGRADE, (int) $order->type);
        // 实付 ¥45 × 剩余 300 / 发放 310 = ¥43.54；新单 ¥10 全抵，多出的 ¥33.54 开通时退回余额
        $this->assertSame(1000, (int) $order->surplus_amount);
        $this->assertSame(4354 - 1000, (int) $order->refund_amount);
        $this->assertSame(0, (int) $order->total_amount);
    }

    // ───────────────────────── 月度保底 ─────────────────────────

    private function walletFromLastMonth(int $remainingGb = 100): User
    {
        $plan = $this->walletPlan ??= $this->paygPlan();
        $user = $this->user();
        $this->buy($user, $plan, 100);
        // 钱包是上上个月开的
        PaygLog::where('user_id', $user->id)->update(['created_at' => Carbon::now()->startOfMonth()->subMonths(2)->timestamp]);
        User::where('id', $user->id)->update(['transfer_enable' => $remainingGb * self::GB, 'u' => 0, 'd' => 0]);
        return $user->fresh();
    }

    private function usage(User $user, Carbon $day, int $bytes, float $rate = 1): void
    {
        StatUser::create(['user_id' => $user->id, 'server_rate' => $rate, 'u' => 0, 'd' => $bytes,
            'record_type' => 'd', 'record_at' => strtotime($day->format('Y-m-d'))]);
    }

    public function test_floor_tops_up_a_used_month_to_five_yuan_once(): void
    {
        $user = $this->walletFromLastMonth();
        $last = Carbon::now()->startOfMonth()->subMonth();
        $this->usage($user, $last->copy()->addDays(3), 4 * self::GB);
        $this->usage($user, $last->copy()->addDays(9), 2 * self::GB, 3);   // 中转：库里已是 ×3 后的量

        // 6 GB × ¥0.15 = ¥0.90，补扣 ¥4.10
        $this->artisan('payg:settle-floor')->assertSuccessful();
        $user->refresh();
        $charged = PaygConfig::bytesFor(15, 410);
        $this->assertSame(100 * self::GB - $charged, (int) $user->transfer_enable);
        $log = PaygLog::where('type', PaygLog::TYPE_FLOOR)->sole();
        $this->assertSame([$last->format('Y-m'), 410, $charged, 6 * self::GB], [$log->month, $log->amount, $log->bytes, $log->usage_bytes]);

        $this->artisan('payg:settle-floor')->assertSuccessful();
        $this->assertSame(100 * self::GB - $charged, (int) $user->fresh()->transfer_enable, '每人每月只结算一次');
        $this->assertSame(1, PaygLog::where('type', PaygLog::TYPE_FLOOR)->count());
    }

    public function test_floor_skips_unused_months_and_months_above_the_floor(): void
    {
        $idle = $this->walletFromLastMonth();
        $heavy = $this->walletFromLastMonth();
        $last = Carbon::now()->startOfMonth()->subMonth();
        $this->usage($heavy, $last->copy()->addDays(1), 40 * self::GB);   // ¥6 ≥ ¥5
        $this->usage($idle, Carbon::now()->startOfMonth(), 1 * self::GB);   // 本月的用量不算上个月

        $service = app(PaygService::class);
        $this->assertSame('unused', $service->settleFloor($idle, $last)['status']);
        $this->assertSame('above_floor', $service->settleFloor($heavy, $last)['status']);
        $this->assertSame('month_open', $service->settleFloor($idle, Carbon::now()->startOfMonth())['status']);
        $this->assertSame(0, PaygLog::where('type', PaygLog::TYPE_FLOOR)->count());
        $this->assertSame(100 * self::GB, (int) $idle->fresh()->transfer_enable);
    }

    public function test_floor_never_drives_the_wallet_negative_and_ignores_usage_before_the_wallet(): void
    {
        $user = $this->walletFromLastMonth(remainingGb: 5);
        $last = Carbon::now()->startOfMonth()->subMonth();
        $this->usage($user, $last->copy()->addDays(2), 1 * self::GB);
        $result = app(PaygService::class)->settleFloor($user, $last);
        // 差 ¥4.85 = 32.3 GB，余额只剩 5 GB：扣到 0，按扣掉的 5 GB 记 ¥0.75
        $this->assertSame(['status' => 'charged', 'amount' => 75, 'bytes' => 5 * self::GB], $result);
        $this->assertSame(0, (int) $user->fresh()->transfer_enable);

        // 钱包这个月中旬才开：开之前那几天（还在别的套餐上）用的不算
        $late = $this->walletFromLastMonth();
        PaygLog::where('user_id', $late->id)->update(['created_at' => $last->copy()->addDays(20)->timestamp]);
        $this->usage($late, $last->copy()->addDays(5), 30 * self::GB);
        $this->usage($late, $last->copy()->addDays(25), 1 * self::GB);
        $result = app(PaygService::class)->settleFloor($late->fresh(), $last);
        $this->assertSame(['status' => 'charged', 'amount' => 485, 'bytes' => PaygConfig::bytesFor(15, 485)], $result);
    }

    // ───────────────────────── 自动补充 ─────────────────────────

    public function test_auto_topup_buys_one_pack_from_balance_below_the_threshold(): void
    {
        $plan = $this->paygPlan();
        $user = $this->user(['balance' => 2000, 'payg_auto_topup' => 1]);
        $this->buy($user, $plan, 100);   // ¥15 从余额扣，剩 ¥5
        User::where('id', $user->id)->update(['balance' => 2000, 'transfer_enable' => 110 * self::GB, 'd' => 95 * self::GB]);

        $this->artisan('payg:auto-topup')->assertSuccessful();
        $user->refresh();
        $this->assertSame(500, (int) $user->balance, '余额 ¥20 − 一份 ¥15');
        $this->assertSame(210 * self::GB, (int) $user->transfer_enable, '叠加 100 GB，首充已经送过');
        $this->assertSame(95 * self::GB, (int) $user->d);
        $order = Order::orderByDesc('id')->first();
        $this->assertSame([Order::STATUS_COMPLETED, Order::TYPE_RENEWAL, 0, 1500], [(int) $order->status, (int) $order->type, (int) $order->total_amount, (int) $order->balance_amount]);
        $this->assertSame(PaygLog::TYPE_AUTO_TOPUP, PaygLog::orderByDesc('id')->value('type'));

        // 余额高于门槛：不再买
        $this->artisan('payg:auto-topup')->assertSuccessful();
        $this->assertSame(2, Order::count());
    }

    public function test_auto_topup_never_leaves_a_pending_order_and_respects_switches(): void
    {
        $plan = $this->paygPlan();
        $poor = $this->user(['balance' => 1499, 'payg_auto_topup' => 1]);
        $off = $this->user(['balance' => 5000, 'payg_auto_topup' => 0]);
        foreach ([$poor, $off] as $user) {
            Order::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'period' => 'onetime', 'type' => 1,
                'trade_no' => (string) Str::uuid(), 'total_amount' => 1500, 'status' => Order::STATUS_COMPLETED]);
            User::where('id', $user->id)->update(['plan_id' => $plan->id, 'transfer_enable' => self::GB]);
        }

        $this->artisan('payg:auto-topup')->assertSuccessful();
        $this->assertSame(2, Order::count(), '余额不够一份不下单；没开开关不碰');
        $this->assertSame(1499, (int) $poor->fresh()->balance);

        config(['v2board.payg_auto_topup_enable' => 0]);
        User::where('id', $poor->id)->update(['balance' => 5000]);
        $this->artisan('payg:auto-topup')->assertSuccessful();
        $this->assertSame(2, Order::count(), '站点关了自动补充');

        config(['v2board.payg_auto_topup_enable' => 1]);
        for ($i = 0; $i < PaygService::AUTO_TOPUP_DAILY_LIMIT; $i++) {
            PaygLog::create(['user_id' => $poor->id, 'plan_id' => $plan->id, 'type' => PaygLog::TYPE_AUTO_TOPUP]);
        }
        $this->assertSame('daily_limit', app(PaygService::class)->attemptAutoTopup($poor->fresh())['reason']);
    }

    // ───────────────────────── 接口 ─────────────────────────

    public function test_plan_list_quote_subscribe_ledger_and_toggle(): void
    {
        $plan = $this->paygPlan();
        $monthly = $this->monthlyPlan();

        $plans = collect($this->getJson('/api/v1/guest/plan/fetch')->assertOk()->json('data'))->keyBy('id');
        $this->assertNull($plans[$monthly->id]['payg']);
        $this->assertSame([
            'unit_price' => 15, 'min_amount' => 1500, 'monthly_floor' => 500, 'first_bonus_gb' => 10,
            'first_bonus_eligible' => true,
            'bonus_tiers' => [['amount' => 10000, 'bonus' => 1000], ['amount' => 30000, 'bonus' => 4500]],
            'auto_topup' => ['site_enabled' => true, 'threshold' => 300, 'amount' => 1500],
        ], $plans[$plan->id]['payg']);

        $quote = $this->postJson('/api/v1/guest/plan/quote', ['plan_id' => $plan->id, 'period' => 'onetime', 'options' => $this->gbOptions(700)])
            ->assertOk()->json('data');
        $this->assertSame(10500, $quote['amount']);
        $this->assertSame(['bonus_bytes' => $this->bonusBytes(1000), 'first_bonus_bytes' => 10 * self::GB], $quote['payg_bonus']);

        $user = $this->user(['balance' => 100]);
        $this->buy($user, $plan, 100);
        Sanctum::actingAs($user->fresh());
        $this->assertFalse(collect($this->getJson('/api/v1/user/plan/fetch')->json('data'))->keyBy('id')[$plan->id]['payg']['first_bonus_eligible']);

        $this->usage($user, Carbon::now(), 2 * self::GB);
        User::where('id', $user->id)->update(['d' => 2 * self::GB]);
        $payg = $this->getJson('/api/v1/user/getSubscribe')->assertOk()->json('data.payg');
        $this->assertSame(108 * self::GB, $payg['balance_bytes']);
        $this->assertSame(15, $payg['unit_price']);
        $this->assertSame(30, $payg['month_used'], '2 GB × ¥0.15');
        $this->assertSame(500, $payg['monthly_floor']);
        $this->assertSame(['enabled' => false, 'site_enabled' => true, 'threshold' => 300, 'amount' => 1500, 'balance_enough' => false], $payg['auto_topup']);

        $this->postJson('/api/v1/user/update', ['payg_auto_topup' => 1])->assertOk();
        $this->assertSame(1, (int) $user->fresh()->payg_auto_topup);
        $this->assertSame(0, (int) $user->fresh()->auto_renew, '自动补充与自动续费是两个开关');
        $this->postJson('/api/v1/user/update', ['payg_auto_topup' => 2])->assertStatus(422);

        $ledger = $this->getJson('/api/v1/user/payg/ledger')->assertOk()->json('data');
        $this->assertCount(PaygService::LEDGER_MONTHS, $ledger['months']);
        $this->assertSame(Carbon::now()->format('Y-m'), $ledger['months'][0]['month']);
        $this->assertSame([['rate' => 1, 'bytes' => 2 * self::GB]], $ledger['months'][0]['by_rate']);
        $this->assertFalse($ledger['months'][0]['settled']);
        $this->assertSame('recharge', $ledger['entries'][0]['type']);
        $this->assertSame(10 * self::GB, $ledger['entries'][0]['first_bonus_bytes']);
        $this->assertNotNull($ledger['entries'][0]['trade_no']);

        $other = $this->user();
        Sanctum::actingAs($other);
        $this->assertNull($this->getJson('/api/v1/user/getSubscribe')->assertOk()->json('data.payg'));
        $this->getJson('/api/v1/user/payg/ledger')->assertStatus(400);
    }
}
