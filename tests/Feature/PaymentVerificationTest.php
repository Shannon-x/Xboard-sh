<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramJob;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentVerification;
use App\Models\User;
use App\Services\PaymentVerificationService;
use App\Support\EpayOrderQuery;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 付款事后核验（payment:verify）：定时任务向网关复核已付单，只读网关、只写核验表，不碰开通。
 * 网关一律用 Http::fake 打桩；配置全是假值。
 */
class PaymentVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const CONFIG = ['url' => 'https://gateway.test', 'pid' => '1000', 'key' => 'fake-key-alpha'];

    /** @var array<string, array|\Closure> 网关交易号 => 网关返回（数组即 JSON；闭包可抛异常） */
    private array $gateway = [];

    /** @var list<array> 网关收到的查询参数 */
    private array $queries = [];

    private Payment $epay;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 10, 20, 12, 0, 0));
        Bus::fake([SendTelegramJob::class]);
        Http::preventStrayRequests();
        Http::fake(['gateway.test/*' => function (HttpRequest $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $this->queries[] = $query;
            $answer = $this->gateway[$query['trade_no'] ?? ''] ?? ['code' => -1, 'msg' => '订单号不存在'];
            if ($answer instanceof \Closure) {
                return $answer($query);
            }
            return Http::response($answer);
        }]);
        // 绑了 Telegram 的管理员：告警才会真的派发 SendTelegramJob
        User::create([
            'email' => 'admin@example.test', 'password' => 'test', 'uuid' => (string) Str::uuid(),
            'token' => Str::random(32), 'is_admin' => 1, 'telegram_id' => 10001,
        ]);
        $this->epay = $this->payment('EPay', self::CONFIG);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payment(string $method, array $config): Payment
    {
        return Payment::create([
            'uuid' => Str::random(8), 'payment' => $method, 'name' => $method . ' 测试通道',
            'config' => $config, 'enable' => true,
        ]);
    }

    private function user(): User
    {
        return User::create([
            'email' => Str::random(10) . '@example.test', 'password' => 'x', 'uuid' => (string) Str::uuid(),
            'token' => Str::random(32), 'balance' => 0, 'transfer_enable' => 0, 'expired_at' => 0,
        ]);
    }

    /** 已付订单：默认 ¥10、10 分钟前入账、网关交易号 GW + 订单号。 */
    private function paidOrder(array $attrs = []): Order
    {
        $tradeNo = $attrs['trade_no'] ?? (string) random_int(10 ** 15, 10 ** 16 - 1);
        return Order::create($attrs + [
            'user_id' => $this->user()->id, 'plan_id' => 1, 'period' => 'monthly', 'type' => Order::TYPE_NEW_PURCHASE,
            'trade_no' => $tradeNo, 'total_amount' => 1000, 'handling_amount' => null,
            'status' => Order::STATUS_COMPLETED, 'payment_id' => $this->epay->id,
            'callback_no' => 'GW' . $tradeNo, 'paid_at' => Carbon::now()->getTimestamp() - 600,
        ]);
    }

    private function paid(Order $order, string $money = '10.00', ?string $outTradeNo = null): void
    {
        $this->gateway[$order->callback_no] = [
            'code' => 1, 'msg' => '查询订单号成功！', 'trade_no' => $order->callback_no,
            'out_trade_no' => $outTradeNo ?? ($order->trade_no . '-ab12cd34'), 'type' => 'wxpay',
            'money' => $money, 'status' => 1,
        ];
    }

    private function verifyDue(int $limit = 100): array
    {
        return (new PaymentVerificationService())->run($limit, 7, 0);
    }

    private function verdict(Order $order): ?PaymentVerification
    {
        return PaymentVerification::where('subject_type', 'order')->where('subject_id', $order->id)->first();
    }

    // ───────────────────────── 判定 ─────────────────────────

    public function test_order_matching_gateway_is_ok_and_never_queried_again(): void
    {
        $order = $this->paidOrder(['handling_amount' => 30]);
        $this->paid($order, '10.30');

        $this->verifyDue();
        $row = $this->verdict($order);
        $this->assertSame(PaymentVerification::VERDICT_OK, $row->verdict);
        $this->assertSame([1030, 1030, 1, 'wxpay'], [$row->expected_amount, $row->gateway_money, $row->gateway_status, $row->gateway_type]);
        $this->assertSame(1, $row->attempts);

        Carbon::setTestNow(Carbon::now()->addDay());
        $this->verifyDue();
        $this->assertCount(1, $this->queries, '有结论后不再查询');
        Bus::assertNotDispatched(SendTelegramJob::class);
    }

    public function test_payment_younger_than_five_minutes_waits(): void
    {
        $order = $this->paidOrder(['paid_at' => Carbon::now()->getTimestamp() - 120]);
        $this->paid($order);

        $this->verifyDue();
        $this->assertNull($this->verdict($order));
        $this->assertSame([], $this->queries);

        Carbon::setTestNow(Carbon::now()->addMinutes(4));
        $this->verifyDue();
        $this->assertSame(PaymentVerification::VERDICT_OK, $this->verdict($order)->verdict);
    }

    public function test_not_found_retries_after_30min_and_2h_then_alerts_once(): void
    {
        $order = $this->paidOrder();   // 网关没有这笔

        $this->verifyDue();
        $row = $this->verdict($order);
        $this->assertSame([PaymentVerification::VERDICT_PENDING, 1], [$row->verdict, $row->miss_count]);
        $this->assertSame(Carbon::now()->getTimestamp() + 1800, $row->next_check_at);

        Carbon::setTestNow(Carbon::now()->addMinutes(29));
        $this->verifyDue();
        $this->assertCount(1, $this->queries, '未到重试时间不查');

        Carbon::setTestNow(Carbon::now()->addMinutes(2));
        $this->verifyDue();
        $row->refresh();
        $this->assertSame([PaymentVerification::VERDICT_PENDING, 2], [$row->verdict, $row->miss_count]);
        Bus::assertNotDispatched(SendTelegramJob::class);

        Carbon::setTestNow(Carbon::now()->addHours(2)->addMinute());
        $this->verifyDue();
        $row->refresh();
        $this->assertSame([PaymentVerification::VERDICT_NOT_FOUND, 3, 3], [$row->verdict, $row->miss_count, $row->attempts]);
        $this->assertNotNull($row->alerted_at);
        Bus::assertDispatchedTimes(SendTelegramJob::class, 1);
        Bus::assertDispatched(SendTelegramJob::class, fn ($job) => str_contains((fn () => $this->text)->call($job), $order->trade_no));

        Carbon::setTestNow(Carbon::now()->addDay());
        $this->verifyDue();
        $this->assertCount(3, $this->queries);
        Bus::assertDispatchedTimes(SendTelegramJob::class, 1);
    }

    public function test_gateway_status_zero_is_refunded(): void
    {
        $order = $this->paidOrder();
        $this->paid($order);
        $this->gateway[$order->callback_no]['status'] = 0;

        $this->verifyDue();
        $this->assertSame(PaymentVerification::VERDICT_REFUNDED, $this->verdict($order)->verdict);
        Bus::assertDispatchedTimes(SendTelegramJob::class, 1);
    }

    public function test_amount_or_out_trade_no_mismatch(): void
    {
        $cheaper = $this->paidOrder();
        $this->paid($cheaper, '0.01');
        $foreign = $this->paidOrder();
        $this->paid($foreign, '10.00', '9999999999999999-zz');
        $session = $this->paidOrder();
        $this->paid($session, '10.00', $session->trade_no);   // 网关单号就是订单号本身也算一致

        $this->verifyDue();
        $this->assertSame(PaymentVerification::VERDICT_MISMATCH, $this->verdict($cheaper)->verdict);
        $this->assertStringContainsString('¥0.01', $this->verdict($cheaper)->last_error);
        $this->assertSame(PaymentVerification::VERDICT_MISMATCH, $this->verdict($foreign)->verdict);
        $this->assertSame(PaymentVerification::VERDICT_OK, $this->verdict($session)->verdict);
        Bus::assertDispatchedTimes(SendTelegramJob::class, 2);
    }

    public function test_network_error_backs_off_and_never_stores_the_key(): void
    {
        $order = $this->paidOrder();
        $this->gateway[$order->callback_no] = function (array $query) {
            throw new ConnectionException('cURL error 28: timed out for https://gateway.test/api.php?act=order&pid=1000&key=' . $query['key']);
        };

        $this->verifyDue();
        $row = $this->verdict($order);
        $this->assertSame(PaymentVerification::VERDICT_ERROR, $row->verdict);
        $this->assertSame(Carbon::now()->getTimestamp() + 300, $row->next_check_at);
        $this->assertStringNotContainsString(self::CONFIG['key'], (string) $row->last_error);
        Bus::assertNotDispatched(SendTelegramJob::class);

        Carbon::setTestNow(Carbon::now()->addMinutes(6));
        $this->verifyDue();
        $row->refresh();
        $this->assertSame(Carbon::now()->getTimestamp() + 1800, $row->next_check_at, '第二次出错退避 30 分钟');

        $this->paid($order);
        Carbon::setTestNow(Carbon::now()->addMinutes(31));
        $this->verifyDue();
        $this->assertSame(PaymentVerification::VERDICT_OK, $this->verdict($order)->verdict);
    }

    public function test_auth_error_is_retried_and_alerted_once_per_hour(): void
    {
        $a = $this->paidOrder();
        $b = $this->paidOrder();
        $this->gateway[$a->callback_no] = ['code' => -1, 'msg' => 'KEY校验失败'];
        $this->gateway[$b->callback_no] = ['code' => -1, 'msg' => 'KEY校验失败'];

        $this->verifyDue();
        $this->assertSame(PaymentVerification::VERDICT_ERROR, $this->verdict($a)->verdict, '鉴权失败不是「没有这笔」');
        $this->assertSame(0, $this->verdict($a)->miss_count);
        $this->assertSame(PaymentVerification::VERDICT_ERROR, $this->verdict($b)->verdict);
        Bus::assertDispatchedTimes(SendTelegramJob::class, 1);
        Bus::assertDispatched(SendTelegramJob::class, fn ($job) => str_contains((fn () => $this->text)->call($job), '密钥可能已失效'));
    }

    public function test_manual_chain_hash_and_zero_amount_orders_are_not_queried(): void
    {
        $manual = $this->paidOrder(['callback_no' => 'manual_operation']);
        $chain = $this->paidOrder(['callback_no' => str_repeat('ab', 32)]);
        $free = $this->paidOrder(['total_amount' => 0]);
        $cancelled = $this->paidOrder(['status' => Order::STATUS_CANCELLED]);

        $this->verifyDue();
        $this->assertSame([], $this->queries);
        $this->assertSame(PaymentVerification::VERDICT_SKIPPED, $this->verdict($manual)->verdict);
        $this->assertSame(PaymentVerification::VERDICT_SKIPPED, $this->verdict($chain)->verdict);
        $this->assertNull($this->verdict($free));
        $this->assertNull($this->verdict($cancelled));
    }

    public function test_orders_paid_through_other_gateways_are_ignored(): void
    {
        $usdt = $this->payment('BEpusdt', ['token' => 'fake-token-x']);
        $order = $this->paidOrder(['payment_id' => $usdt->id]);

        $this->verifyDue();
        $this->assertNull($this->verdict($order));
        $this->assertSame([], $this->queries);
    }

    public function test_each_query_uses_the_current_key(): void
    {
        $first = $this->paidOrder();
        $this->paid($first);
        $this->verifyDue();

        $this->epay->config = ['key' => 'fake-key-bravo'] + self::CONFIG;
        $this->epay->save();
        $second = $this->paidOrder();
        $this->paid($second);
        $this->verifyDue();

        $this->assertSame(['fake-key-alpha', 'fake-key-bravo'], array_column($this->queries, 'key'));
        $this->assertSame(['order', 'order'], array_column($this->queries, 'act'));
    }

    public function test_single_run_is_capped_and_goes_oldest_first(): void
    {
        $orders = [];
        foreach ([50, 40, 30, 20, 10] as $minutesAgo) {
            $order = $this->paidOrder(['paid_at' => Carbon::now()->getTimestamp() - $minutesAgo * 60]);
            $this->paid($order);
            $orders[] = $order;
        }

        $result = $this->verifyDue(2);
        $this->assertSame(2, $result['checked']);
        $this->assertNotNull($this->verdict($orders[0]));
        $this->assertNotNull($this->verdict($orders[1]));
        $this->assertNull($this->verdict($orders[2]));
    }

    // ───────────────────────── 充值单 ─────────────────────────

    public function test_recharge_orders_are_verified_when_the_plugin_table_exists(): void
    {
        $order = $this->paidOrder();
        $this->paid($order);
        $this->verifyDue();   // 没有充值表也照常跑
        $this->assertSame(PaymentVerification::VERDICT_OK, $this->verdict($order)->verdict);

        Schema::create('plugin_recharge_orders', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('trade_no', 64);
            $table->integer('pay_amount');
            $table->integer('handling_amount')->nullable();
            $table->integer('payment_id')->nullable();
            $table->tinyInteger('status');
            $table->string('callback_no', 255)->nullable();
            $table->integer('paid_at')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
        $now = Carbon::now()->getTimestamp();
        $id = DB::table('plugin_recharge_orders')->insertGetId([
            'user_id' => $order->user_id, 'trade_no' => '2026102012000011', 'pay_amount' => 500000, 'handling_amount' => null,
            'payment_id' => $this->epay->id, 'status' => 3, 'callback_no' => 'GW-R1', 'paid_at' => $now - 600,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->gateway['GW-R1'] = ['code' => 1, 'money' => '5000.00', 'status' => 1, 'out_trade_no' => '2026102012000011-q1w2e3r4', 'type' => 'usdt'];

        $this->verifyDue();
        $row = PaymentVerification::where('subject_type', 'recharge')->where('subject_id', $id)->first();
        $this->assertSame(PaymentVerification::VERDICT_OK, $row->verdict, (string) $row->last_error);
        $this->assertSame(500000, $row->expected_amount);
    }

    // ───────────────────────── 日报与命令 ─────────────────────────

    public function test_daily_report_lists_counts_and_anomalies(): void
    {
        $ok = $this->paidOrder();
        $this->paid($ok);
        $bad = $this->paidOrder(['total_amount' => 299989]);
        $this->paid($bad, '0.01');
        $this->paidOrder(['paid_at' => Carbon::now()->getTimestamp() - 60]);   // 还没到核验时间
        $this->verifyDue();

        $report = (new PaymentVerificationService())->dailyReport(Carbon::today());
        $this->assertStringContainsString('付款核验日报 2026-10-20', $report);
        $this->assertStringContainsString('今日核验 2 笔', $report);
        $this->assertStringContainsString('一致 1', $report);
        $this->assertStringContainsString('异常 1 笔', $report);
        $this->assertStringContainsString($bad->trade_no, $report);
        $this->assertStringContainsString('¥2,999.89', $report);

        Bus::fake([SendTelegramJob::class]);
        $this->artisan('payment:verify-report')->assertSuccessful();
        Bus::assertDispatchedTimes(SendTelegramJob::class, 1);
    }

    public function test_verify_command_runs_and_backfill_widens_the_window(): void
    {
        $old = $this->paidOrder(['paid_at' => Carbon::now()->subDays(20)->getTimestamp()]);
        $this->paid($old);

        $this->artisan('payment:verify', ['--sleep-ms' => 0])->assertSuccessful();
        $this->assertNull($this->verdict($old), '默认只看最近 7 天');

        $this->artisan('payment:verify', ['--sleep-ms' => 0, '--backfill-days' => 30])->assertSuccessful();
        $this->assertSame(PaymentVerification::VERDICT_OK, $this->verdict($old)->verdict);
    }

    // ───────────────────────── 网关应答解析 ─────────────────────────

    public function test_gateway_answers_are_classified(): void
    {
        $this->gateway['A'] = ['code' => 1, 'money' => '45.02', 'status' => '1', 'out_trade_no' => 'X-1', 'type' => 'alipay'];
        $this->gateway['B'] = ['code' => -1, 'msg' => '订单号不存在'];
        $this->gateway['C'] = ['code' => -1, 'msg' => '商户不存在或已封禁'];
        $this->gateway['D'] = ['code' => -2, 'msg' => '系统繁忙'];
        $this->gateway['E'] = fn () => Http::response('<html>bad gateway</html>', 502);

        $a = EpayOrderQuery::query(self::CONFIG, 'A');
        $this->assertSame(['found', 1, 4502, 'alipay', 'X-1'], [$a['outcome'], $a['status'], $a['money'], $a['type'], $a['out_trade_no']]);
        $this->assertSame('not_found', EpayOrderQuery::query(self::CONFIG, 'B')['outcome']);
        $this->assertSame('auth_error', EpayOrderQuery::query(self::CONFIG, 'C')['outcome']);
        $this->assertSame('error', EpayOrderQuery::query(self::CONFIG, 'D')['outcome']);
        $this->assertSame(['error', 'HTTP 502'], [EpayOrderQuery::query(self::CONFIG, 'E')['outcome'], EpayOrderQuery::query(self::CONFIG, 'E')['msg']]);
        $this->assertSame('error', EpayOrderQuery::query(['url' => '', 'pid' => '', 'key' => ''], 'A')['outcome'], '配置缺失不发请求');
        $this->assertCount(6, $this->queries);
    }
}
