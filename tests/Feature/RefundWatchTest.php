<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramJob;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentVerification;
use App\Models\User;
use App\Services\EpayGatewayMonitor;
use App\Services\RefundWatchService;
use App\Support\EpaySignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * 退款回查：用户通过投诉等途径让网关退了款 → 自动封号、收回套餐、重置订阅、踢下线并告警，
 * 管理员确认后删号。两次查询一致才处置；冻结只告警。
 *
 * 网关用 Http::fake 打桩：V1 按 trade_no 给出应答队列，V2 由测试内生成的平台私钥签名。
 */
class RefundWatchTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = 86400;

    /** @var array<string, list<array>> 网关交易号 → 依次给出的查单应答 */
    private array $answers = [];

    private static ?array $platformKey = null;

    protected function setUp(): void
    {
        parent::setUp();
        self::$platformKey ??= self::keyPair();
        Cache::flush();
        Bus::fake([SendTelegramJob::class]);
        Http::preventStrayRequests();
        Http::fake(['gateway.test/*' => function (HttpRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $params = $request->method() === 'POST' ? $request->data() : $query;
            if ($path === '/api/complain/list') {
                return Http::response(self::platformSign(['code' => 0, 'data' => ['total' => count($this->complaints), 'rows' => $this->complaints]]));
            }
            $queue = $this->answers[$params['trade_no'] ?? ''] ?? [];
            $answer = count($queue) > 1 ? array_shift($this->answers[$params['trade_no']]) : ($queue[0] ?? ['code' => -1, 'msg' => '订单号不存在']);
            return Http::response($path === '/api.php' ? $answer : self::platformSign($answer));
        }]);
        User::create([
            'email' => 'admin@example.test', 'password' => 'x', 'uuid' => (string) Str::uuid(),
            'token' => Str::random(32), 'is_admin' => 1, 'telegram_id' => 10001,
        ]);
    }

    /** @var list<array> 投诉列表接口的应答行 */
    private array $complaints = [];

    // ───────────────────────── 工具 ─────────────────────────

    private static function keyPair(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        return ['private' => $private, 'public' => openssl_pkey_get_details($key)['key']];
    }

    private static function platformSign(array $params): array
    {
        $params['timestamp'] = (string) time();
        openssl_sign(EpaySignature::content($params), $sig, self::$platformKey['private'], OPENSSL_ALGO_SHA256);
        $params['sign'] = base64_encode($sig);
        $params['sign_type'] = 'RSA';
        return $params;
    }

    private function v1Payment(): Payment
    {
        return $this->payment(['url' => 'https://gateway.test', 'pid' => '80001', 'key' => 'v1-secret']);
    }

    private function v2Payment(): Payment
    {
        $merchant = self::keyPair();
        return $this->payment([
            'url' => 'https://gateway.test', 'pid' => '1001', 'api_version' => 'v2',
            'merchant_private_key' => $merchant['private'], 'platform_public_key' => self::$platformKey['public'],
        ]);
    }

    private function payment(array $config): Payment
    {
        return Payment::create(['uuid' => Str::random(8), 'payment' => 'EPay', 'name' => '易支付', 'config' => $config, 'enable' => true]);
    }

    private function customer(): User
    {
        $user = User::create([
            'email' => Str::random(8) . '@example.test', 'password' => 'x', 'uuid' => (string) Str::uuid(),
            'token' => Str::random(32), 'balance' => 0, 'transfer_enable' => 100 * 1073741824,
            'plan_id' => 7, 'expired_at' => time() + 30 * self::DAY,
        ]);
        $user->createToken('session');
        return $user;
    }

    /** 已付、已核验通过的订单（¥35.20，$daysAgo 天前付款） */
    private function verifiedOrder(Payment $payment, float $daysAgo, array $orderAttrs = [], array $rowAttrs = []): array
    {
        $user = $this->customer();
        $order = Order::create($orderAttrs + [
            'user_id' => $user->id, 'plan_id' => 7, 'period' => 'monthly', 'type' => Order::TYPE_NEW_PURCHASE,
            'trade_no' => (string) random_int(10 ** 15, 10 ** 16 - 1), 'total_amount' => 3520,
            'status' => Order::STATUS_COMPLETED, 'payment_id' => $payment->id,
            'callback_no' => 'GW' . Str::random(10), 'paid_at' => time() - (int) ($daysAgo * self::DAY),
        ]);
        $row = PaymentVerification::create($rowAttrs + [
            'subject_type' => 'order', 'subject_id' => $order->id, 'user_id' => $user->id, 'payment_id' => $payment->id,
            'callback_no' => $order->callback_no, 'expected_amount' => 3520, 'verdict' => 'ok', 'gateway_status' => 1,
        ]);
        return [$user, $order, $row];
    }

    private function gatewaySays(Order $order, int ...$statuses): void
    {
        $this->answers[$order->callback_no] = array_map(fn (int $status) => [
            'code' => 1, 'code_v2' => 0, 'trade_no' => $order->callback_no, 'out_trade_no' => $order->trade_no,
            'status' => $status, 'money' => '35.20', 'type' => 'alipay',
        ], $statuses);
    }

    private function v2Says(Order $order, array ...$answers): void
    {
        $this->answers[$order->callback_no] = array_map(fn (array $a) => $a + [
            'code' => 0, 'trade_no' => $order->callback_no, 'out_trade_no' => $order->trade_no, 'money' => '35.20', 'type' => 'wxpay',
        ], $answers);
    }

    private function run_(): array
    {
        return (new RefundWatchService())->run(100, 0);
    }

    private function alerts(): array
    {
        $texts = [];
        Bus::assertDispatched(SendTelegramJob::class, function (SendTelegramJob $job) use (&$texts) {
            $texts[] = (fn () => $this->text)->call($job);
            return true;
        });
        return $texts;
    }

    // ───────────────────────── 回查节奏 ─────────────────────────

    public function test_first_recheck_after_one_day_then_daily(): void
    {
        $payment = $this->v1Payment();
        [, $fresh] = $this->verifiedOrder($payment, 0.5);
        [, $order, $row] = $this->verifiedOrder($payment, 2.5);
        $this->gatewaySays($order, 1);

        $this->assertSame(1, $this->run_()['checked'], '付款不满 1 天的不查');
        $row->refresh();
        $this->assertSame(2, $row->recheck_stage);
        $this->assertSame($order->paid_at + 3 * self::DAY, $row->recheck_at, '下一次在付款后第 3 天');
        $this->assertSame(0, $this->run_()['checked'], '没到点不重复查');
        $this->assertNull(PaymentVerification::where('subject_id', $fresh->id)->value('recheck_at'));
    }

    public function test_older_orders_jump_to_the_next_checkpoint_and_finish_after_45_days(): void
    {
        $payment = $this->v1Payment();
        [, $mid, $midRow] = $this->verifiedOrder($payment, 17);
        [, $old, $oldRow] = $this->verifiedOrder($payment, 46);
        $this->gatewaySays($mid, 1);
        $this->gatewaySays($old, 1);

        $this->run_();
        $this->assertSame($mid->paid_at + 20 * self::DAY, $midRow->fresh()->recheck_at);
        $this->assertNull($oldRow->fresh()->recheck_at, '45 天的回查点过了就结束');
        $this->assertSame(count(RefundWatchService::RECHECK_OFFSETS), $oldRow->fresh()->recheck_stage);
    }

    // ───────────────────────── 判定与处置 ─────────────────────────

    public function test_v1_refund_confirmed_twice_bans_user_and_revokes_service(): void
    {
        $payment = $this->v1Payment();
        $inviter = $this->customer();
        [$user, $order, $row] = $this->verifiedOrder($payment, 8, ['commission_balance' => 1056, 'invite_user_id' => $inviter->id]);
        $oldToken = $user->token;
        $this->gatewaySays($order, 0, 0);

        $this->assertSame(1, $this->run_()['refunded']);

        $user->refresh();
        $this->assertSame([1, null, 0], [(int) $user->banned, $user->plan_id, (int) $user->transfer_enable]);
        $this->assertNotSame($oldToken, $user->token, '旧订阅链接作废');
        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $user->id)->where('tokenable_type', User::class)->count(), '踢下线');
        $this->assertStringContainsString('网关退款自动封禁', (string) $user->remarks);

        $row->refresh();
        $this->assertSame('refunded', $row->verdict);
        $this->assertNotNull($row->refunded_at);
        $this->assertSame(7, $row->enforce_snapshot['plan_id'], '留下处置前的套餐，误判时照此恢复');

        $alert = $this->alerts()[0];
        $this->assertStringContainsString('已自动封号', $alert);
        $this->assertStringContainsString('删除 #' . $user->id, $alert);
        $this->assertStringContainsString('该单佣金：¥10.56', $alert);
        $this->assertStringContainsString($order->trade_no, $alert);

        $this->run_();
        Bus::assertDispatchedTimes(SendTelegramJob::class, 1);
    }

    public function test_a_single_odd_answer_does_not_ban(): void
    {
        $payment = $this->v1Payment();
        [$user, $order, $row] = $this->verifiedOrder($payment, 3);
        $this->gatewaySays($order, 0, 1);

        $this->assertSame(1, $this->run_()['errors']);
        $this->assertSame(0, (int) $user->fresh()->banned);
        $this->assertGreaterThan(time(), $row->fresh()->recheck_at, '一小时后再查');
        Bus::assertNotDispatched(SendTelegramJob::class);
    }

    public function test_v2_refund_and_partial_refund_ban_while_frozen_only_alerts(): void
    {
        $payment = $this->v2Payment();
        [$frozenUser, $frozen] = $this->verifiedOrder($payment, 5);
        [$refundedUser, $refunded] = $this->verifiedOrder($payment, 5);
        [$partialUser, $partial] = $this->verifiedOrder($payment, 5);
        $this->v2Says($frozen, ['status' => 3]);
        $this->v2Says($refunded, ['status' => 2]);
        $this->v2Says($partial, ['status' => 1, 'refundmoney' => '5.00']);

        $stats = $this->run_();
        $this->assertSame([1, 2], [$stats['frozen'], $stats['refunded']]);
        $this->assertSame(0, (int) $frozenUser->fresh()->banned);
        $this->assertSame(1, (int) $refundedUser->fresh()->banned);
        $this->assertSame(1, (int) $partialUser->fresh()->banned);
        $this->assertCount(1, array_filter($this->alerts(), fn ($t) => str_contains($t, '冻结')));
    }

    public function test_orders_flagged_at_payment_verification_are_rechecked_then_enforced_or_cleared(): void
    {
        $payment = $this->v1Payment();
        [$refundedUser, $refunded] = $this->verifiedOrder($payment, 2, [], ['verdict' => 'refunded', 'gateway_status' => 0]);
        [$paidUser, $paid, $paidRow] = $this->verifiedOrder($payment, 2, [], ['verdict' => 'refunded', 'gateway_status' => 0]);
        $this->gatewaySays($refunded, 0, 0);
        $this->gatewaySays($paid, 1);

        $this->run_();
        $this->assertSame(1, (int) $refundedUser->fresh()->banned);
        $this->assertSame(0, (int) $paidUser->fresh()->banned);
        $this->assertSame('ok', $paidRow->fresh()->verdict, '网关现为已支付：改回核验通过');
    }

    public function test_refunded_recharge_is_enforced_and_alert_mentions_balance(): void
    {
        $payment = $this->v1Payment();
        $user = $this->customer();
        $user->forceFill(['balance' => 5000])->save();
        $id = DB::table('v2_recharge_order')->insertGetId([
            'user_id' => $user->id, 'trade_no' => 'R20261024', 'deposit_amount' => 5000, 'total_credit' => 5000,
            'pay_amount' => 5000, 'payment_id' => $payment->id,
            'status' => 3, 'callback_no' => 'GWR1', 'paid_at' => time() - 4 * self::DAY,
            'created_at' => time() - 4 * self::DAY, 'updated_at' => time() - 4 * self::DAY,
        ]);
        PaymentVerification::create([
            'subject_type' => 'recharge', 'subject_id' => $id, 'user_id' => $user->id, 'payment_id' => $payment->id,
            'callback_no' => 'GWR1', 'expected_amount' => 5000, 'verdict' => 'ok',
        ]);
        $this->answers['GWR1'] = [['code' => 1, 'status' => 0, 'money' => '50.00'], ['code' => 1, 'status' => 0, 'money' => '50.00']];

        $this->assertSame(1, $this->run_()['refunded']);
        $this->assertSame(1, (int) $user->fresh()->banned);
        $alert = $this->alerts()[0];
        $this->assertStringContainsString('充值单：<code>R20261024</code>', $alert);
        $this->assertStringContainsString('余额 ¥50.00 未扣回', $alert);
    }

    // ───────────────────────── 投诉触发 ─────────────────────────

    public function test_complaints_trigger_immediate_recheck_and_open_ones_every_six_hours(): void
    {
        $payment = $this->v2Payment();
        [$user, $order, $row] = $this->verifiedOrder($payment, 9);
        $this->v2Says($order, ['status' => 1]);
        $this->complaints = [[
            'id' => 55, 'pay_type' => 'wxpay', 'trade_no' => $order->callback_no, 'out_trade_no' => $order->trade_no,
            'problem_type' => '申请退款', 'status' => 0, 'money' => '35.20', 'edittime' => '2026-10-24 10:00:00', 'addtime' => '2026-10-24 10:00:00',
        ]];
        $monitor = new EpayGatewayMonitor();

        $this->assertSame(1, $monitor->complaints()['rechecked'], '新投诉立即回查');
        $this->assertSame(0, $monitor->complaints()['rechecked'], '未结案的 6 小时内不重复查');

        $this->travel(7)->hours();
        $this->v2Says($order, ['status' => 2]);
        $this->assertSame(1, $monitor->complaints()['rechecked'], '未结案满 6 小时再查');
        $this->assertSame(1, (int) $user->fresh()->banned, '投诉期间退了款 → 封号');
        $this->assertStringContainsString('投诉 #55', (string) $row->fresh()->last_error);
    }
}
