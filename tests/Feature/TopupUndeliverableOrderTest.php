<?php

namespace Tests\Feature;

use App\Jobs\OrderHandleJob;
use App\Jobs\SendTelegramJob;
use App\Models\BalanceLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\OrderService;
use App\Services\TrafficResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 回归测试：已付款却开通不了的订单不能把 check:order 拖进死循环（2026-10 #3866）。
 *
 * 背景（真实缺陷）：流量加购单下单时订阅有效，用户在收银台磨蹭到订阅到期后 81 秒才付款。
 * applyTrafficTopup 抛「订阅状态已变化，转人工」→ OrderHandleJob 重抛 → 三次后进 failed_jobs；
 * 订单停在「开通中」，check:order 每分钟重派，两天刷了 700 多条 failed_jobs，管理员一条通知都没收到，
 * 客户的钱既没变成流量也没退回。
 *
 * 修复后三层：
 *   1. 开通不了的加购单（到期 / 换套餐 / 被停用）：取消订单、已付款项（在线 + 手续费 + 余额抵扣）全额退回余额，
 *      到期 / 流量不动，同一订单只退一次，同一笔回调重投不会重开；告警管理员。
 *   2. 其余「转人工」（线路权限变了、折抵源被占……）：OrderHandleJob 不重抛，订单留在开通中，
 *      告警按订单 24 小时去重；条件恢复后照常自动开通。真正的故障异常照旧重抛重试。
 *   3. 收银台：加购单的订阅已失效就不给收款地址、不走余额全额开通，直接取消这张待付单让用户先续费。
 */
class TopupUndeliverableOrderTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1073741824;

    private ServerGroup $base;
    private ServerGroup $premium;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = $this->group('基础');
        $this->premium = $this->group('10x 高速');
        Bus::fake([SendTelegramJob::class]);
        // 有一个绑了 Telegram 的管理员，告警才会真的派发 SendTelegramJob
        User::create([
            'email' => 'admin@example.test', 'password' => 'test', 'uuid' => (string) Str::uuid(),
            'token' => Str::random(32), 'is_admin' => 1, 'telegram_id' => 10001,
        ]);
    }

    private function group(string $name): ServerGroup
    {
        $group = new ServerGroup();
        $group->name = $name;
        $group->save();
        return $group;
    }

    private const FIXED = [
        'transfer_enable' => ['mode' => 'fixed'], 'device_limit' => ['mode' => 'fixed'], 'speed_limit' => ['mode' => 'fixed'],
    ];

    /** 月付 ¥10 / 100GB，加购 50 分/GB。 */
    private function topupPlan(array $customization = []): Plan
    {
        return Plan::create([
            'name' => 'Topup ' . Str::random(4), 'capacity_limit' => null, 'group_id' => $this->base->id,
            'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => 100,
            'reset_traffic_method' => 1,
            'prices' => ['monthly' => 10, 'reset_traffic' => 3],
            'customization' => self::FIXED + ($customization ?: ['traffic_topup' => ['mode' => 'on', 'price_per_gb' => 50]]),
        ]);
    }

    /** premium 组对加购每 GB 再加 30 分。 */
    private function addonPlan(): Plan
    {
        return $this->topupPlan([
            'addon_groups' => [(string) $this->premium->id => ['mode' => 'optional', 'price' => 500, 'topup_price_per_gb' => 30]],
            'traffic_topup' => ['mode' => 'on', 'price_per_gb' => 50],
        ]);
    }

    private function user(Plan $plan, array $attributes = []): User
    {
        return User::create($attributes + [
            'email' => Str::random(12) . '@example.test', 'password' => 'test',
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'plan_id' => $plan->id, 'group_id' => $plan->group_id, 'balance' => 0,
            'transfer_enable' => 100 * self::GB, 'u' => 3 * self::GB, 'd' => 7 * self::GB,
            'device_limit' => 2, 'speed_limit' => 100,
            'expired_at' => time() + 20 * 86400, 'next_reset_at' => time() + 10 * 86400,
        ]);
    }

    /** 模拟网关回调把订单翻成「开通中」（paid() 那一步），不立刻开通。 */
    private function markPaid(Order $order, string $callbackNo = 'CB-1', int $handling = 0): Order
    {
        $order->status = Order::STATUS_PROCESSING;
        $order->paid_at = time();
        $order->callback_no = $callbackNo;
        $order->handling_amount = $handling ?: null;
        $order->save();
        return $order->fresh();
    }

    private function subscriptionState(User $user): array
    {
        $user = $user->fresh();
        return [(int) $user->transfer_enable, (int) $user->transfer_topup, (int) $user->u, (int) $user->d,
            (int) $user->expired_at, (int) $user->next_reset_at, (int) $user->plan_id];
    }

    // ───────────────────────── 1. 开通不了的加购单：取消 + 全额退回余额 ─────────────────────────

    public function test_topup_paid_after_expiry_is_cancelled_and_fully_refunded_once(): void
    {
        $plan = $this->topupPlan();
        $user = $this->user($plan, ['balance' => 100]);
        $order = OrderService::createFromRequest($user, $plan, 'traffic_topup', null, ['topup_gb' => 10]);
        $this->assertSame([400, 100, 0], [(int) $order->total_amount, (int) $order->balance_amount, (int) $user->fresh()->balance],
            '¥5 的加购包：余额先抵 ¥1，在线应付 ¥4');

        $order = $this->markPaid($order, 'CB-1', 20);   // 在线付了 ¥4 + 手续费 ¥0.20
        $user->fresh()->forceFill(['expired_at' => time() - 81])->save();   // 付款晚于到期 81 秒
        $before = $this->subscriptionState($user);

        OrderHandleJob::dispatchSync($order->trade_no);   // 修复前这里会抛 RuntimeException

        $order->refresh();
        $this->assertSame(Order::STATUS_CANCELLED, (int) $order->status);
        $this->assertSame('CB-1', $order->callback_no, 'paid_at / callback_no 留作对账');
        $this->assertSame(520, (int) $user->fresh()->balance, '在线 400 + 手续费 20 + 余额抵扣 100 全部退回余额');
        $this->assertSame($before, $this->subscriptionState($user), '流量 / 到期 / 重置日 / 套餐一概不动');

        $log = BalanceLog::where('user_id', $user->id)->latest('id')->first();
        $this->assertSame([BalanceLog::TYPE_ORDER_CANCEL, 520, (int) $order->id, 0, 520],
            [$log->type, (int) $log->amount, (int) $log->order_id, (int) $log->balance_before, (int) $log->balance_after]);
        $this->assertStringContainsString('订阅已到期', $log->remark);
        Bus::assertDispatchedTimes(SendTelegramJob::class, 1);

        // 重复处理：check:order 再派一次、或另一个 worker 拿着还没刷新的旧模型（内存里仍是「开通中」）
        $stale = $order->replicate();
        $stale->id = $order->id;
        $stale->exists = true;
        $stale->status = Order::STATUS_PROCESSING;
        OrderHandleJob::dispatchSync($order->trade_no);
        DB::transaction(fn () => (new OrderService($stale))->open());
        $this->assertSame(520, (int) $user->fresh()->balance, '钱只退一次');
        $this->assertSame(1, BalanceLog::where('user_id', $user->id)->where('type', BalanceLog::TYPE_ORDER_CANCEL)->count());
        Bus::assertDispatchedTimes(SendTelegramJob::class, 1);

        // 网关把同一笔回调重投：不能被当成「超时取消后的迟到支付」重新开通（会把同一笔钱再花一次）
        $this->assertSame('duplicate', (new OrderService($order->fresh()))->reopenFromCancelled('CB-1'));
        // 同一张单又来一笔新流水：重复付款，交人工，不自动开通
        $this->assertSame('manual', (new OrderService($order->fresh()))->reopenFromCancelled('CB-2'));
        $this->assertSame(Order::STATUS_CANCELLED, (int) $order->fresh()->status);
        $this->assertSame(520, (int) $user->fresh()->balance);
        $this->assertSame($before, $this->subscriptionState($user));
    }

    public function test_topup_after_plan_change_or_suspension_is_refunded_and_never_lands_on_the_new_plan(): void
    {
        foreach ([
            ['订阅已更换套餐', fn (User $u) => $u->forceFill(['plan_id' => $this->topupPlan()->id])],
            ['账户已被停用', fn (User $u) => $u->forceFill(['banned' => 1])],
        ] as [$reason, $change]) {
            $plan = $this->topupPlan();
            $user = $this->user($plan);
            $order = $this->markPaid(OrderService::createFromRequest($user, $plan, 'traffic_topup', null, ['topup_gb' => 4]));
            $change($user->fresh())->save();
            $before = $this->subscriptionState($user);

            OrderHandleJob::dispatchSync($order->trade_no);

            $this->assertSame(Order::STATUS_CANCELLED, (int) $order->fresh()->status, $reason);
            $this->assertSame(200, (int) $user->fresh()->balance, $reason);
            $this->assertSame($before, $this->subscriptionState($user), $reason);
            $this->assertStringContainsString($reason, BalanceLog::where('user_id', $user->id)->latest('id')->first()->remark);
        }
    }

    public function test_topup_paid_while_subscription_is_valid_still_opens_normally(): void
    {
        $plan = $this->topupPlan();
        $user = $this->user($plan);
        $order = $this->markPaid(OrderService::createFromRequest($user, $plan, 'traffic_topup', null, ['topup_gb' => 10]));

        OrderHandleJob::dispatchSync($order->trade_no);

        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        $this->assertSame([110 * self::GB, 10 * self::GB], array_slice($this->subscriptionState($user), 0, 2));
        $this->assertSame(0, (int) $user->fresh()->balance);
        Bus::assertNotDispatched(SendTelegramJob::class);
    }

    // ───────────────────────── 2. 其余「转人工」：不进 failed_jobs、告警去重、条件恢复后自愈 ─────────────────────────

    public function test_manual_review_keeps_the_order_processing_alerts_once_and_recovers_by_itself(): void
    {
        $plan = $this->addonPlan();
        $options = ['transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => 100,
            'addon_groups' => [$this->premium->id], 'granted_groups' => [$this->premium->id]];
        $user = $this->user($plan, ['plan_options' => $options]);
        $order = $this->markPaid(OrderService::createFromRequest($user, $plan, 'traffic_topup', null, ['topup_gb' => 10]));
        $this->assertSame(800, (int) $order->total_amount, '带 10x 组：每 GB 50 + 30');

        // 付款后线路权限被收走：按旧价开通会少收钱，必须人看
        $user->fresh()->forceFill(['plan_options' => ['transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => 100]])->save();
        $before = $this->subscriptionState($user);

        OrderHandleJob::dispatchSync($order->trade_no);   // 修复前：抛出 → failed_jobs，每分钟一轮
        OrderHandleJob::dispatchSync($order->trade_no);   // check:order 下一分钟又派一次

        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status, '不退款、不取消，留给人或条件恢复');
        $this->assertSame($before, $this->subscriptionState($user));
        $this->assertSame(0, (int) $user->fresh()->balance);
        Bus::assertDispatchedTimes(SendTelegramJob::class, 1);   // 24 小时内只提醒一次

        // 管理员补回线路权限 → 下一轮自动开通
        $user->fresh()->forceFill(['plan_options' => $options])->save();
        OrderHandleJob::dispatchSync($order->trade_no);
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        $this->assertSame(110 * self::GB, (int) $user->fresh()->transfer_enable);
    }

    public function test_genuine_failures_are_still_thrown_for_queue_retry(): void
    {
        $plan = $this->topupPlan();
        $user = $this->user($plan);
        $this->app->instance(TrafficResetService::class, new class extends TrafficResetService {
            public function performReset(User $user, string $triggerSource = 'manual', bool $preserveSchedule = false): bool
            {
                return false;
            }
        });
        $order = Order::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'type' => Order::TYPE_RESET_TRAFFIC,
            'period' => Plan::PERIOD_RESET_TRAFFIC, 'trade_no' => Str::random(20), 'total_amount' => 300,
            'status' => Order::STATUS_PROCESSING, 'paid_at' => time(), 'callback_no' => 'CB-R',
        ]);

        try {
            OrderHandleJob::dispatchSync($order->trade_no);
            $this->fail('重置失败是暂时性故障，必须抛给队列重试');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(\App\Exceptions\OrderNeedsManualReview::class, $e);
        }
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
        Bus::assertNotDispatched(SendTelegramJob::class);
    }

    // ───────────────────────── 3. 收银台：订阅已失效的加购单不给付款 ─────────────────────────

    public function test_checkout_refuses_and_cancels_a_topup_whose_subscription_expired(): void
    {
        $plan = $this->topupPlan();
        $user = $this->user($plan, ['balance' => 100]);
        $order = OrderService::createFromRequest($user, $plan, 'traffic_topup', null, ['topup_gb' => 10]);
        $user->fresh()->forceFill(['expired_at' => time() - 1])->save();
        $payment = Payment::create(['uuid' => Str::random(32), 'payment' => 'EPay', 'name' => 'EPay Test', 'config' => [], 'enable' => true]);
        Sanctum::actingAs($user->fresh());

        $this->postJson('/api/v1/user/order/checkout', ['trade_no' => $order->trade_no, 'method' => $payment->id])
            ->assertStatus(400)
            ->assertJsonFragment(['message' => '订阅已到期，流量包已无法加到当前订阅。这张订单已自动取消，余额抵扣（如有）已退回；请先续费订阅，再重新购买流量包']);

        $order->refresh();
        $this->assertSame(Order::STATUS_CANCELLED, (int) $order->status);
        $this->assertNull($order->payment_id, '没有绑网关、没有要收款地址');
        $this->assertSame(100, (int) $user->fresh()->balance, '余额抵扣退回');
    }

    public function test_checkout_refuses_a_balance_covered_topup_whose_subscription_expired(): void
    {
        $plan = $this->topupPlan();
        $user = $this->user($plan, ['balance' => 1000]);
        $order = OrderService::createFromRequest($user, $plan, 'traffic_topup', null, ['topup_gb' => 10]);
        $this->assertSame([0, 500], [(int) $order->total_amount, (int) $order->balance_amount], '余额全额抵扣：checkout 会直接开通');
        $user->fresh()->forceFill(['expired_at' => time() - 1])->save();
        $before = $this->subscriptionState($user);
        Sanctum::actingAs($user->fresh());

        $this->postJson('/api/v1/user/order/checkout', ['trade_no' => $order->trade_no])->assertStatus(400);

        $this->assertSame(Order::STATUS_CANCELLED, (int) $order->fresh()->status);
        $this->assertNull($order->fresh()->paid_at);
        $this->assertSame(1000, (int) $user->fresh()->balance);
        $this->assertSame($before, $this->subscriptionState($user));
    }

    public function test_checkout_of_a_valid_balance_covered_topup_still_opens(): void
    {
        $plan = $this->topupPlan();
        $user = $this->user($plan, ['balance' => 1000]);
        $order = OrderService::createFromRequest($user, $plan, 'traffic_topup', null, ['topup_gb' => 10]);
        Sanctum::actingAs($user->fresh());

        $this->postJson('/api/v1/user/order/checkout', ['trade_no' => $order->trade_no])->assertOk()->assertJsonPath('type', -1);

        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        $this->assertSame(110 * self::GB, (int) $user->fresh()->transfer_enable);
        $this->assertSame(500, (int) $user->fresh()->balance);
    }
}
