<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Jobs\SendTelegramJob;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\PaymentLinkReport;
use App\Services\PaymentService;
use App\Support\PaymentLinkStats;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 支付通道拉取统计：下单拿收款链接的成功 / 失败按通道计数、失败按原因归类，进 23:30 日报；
 * 白天失败过半或拉到链接没人付时告警。
 */
class PaymentLinkStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Bus::fake([SendTelegramJob::class]);
        User::create([
            'email' => 'admin@example.test', 'password' => 'x', 'uuid' => (string) Str::uuid(),
            'token' => Str::random(32), 'is_admin' => 1, 'telegram_id' => 10001,
        ]);
    }

    private function payment(string $name = '支付宝支付'): Payment
    {
        return Payment::create(['uuid' => Str::random(8), 'payment' => 'EPay', 'name' => $name, 'config' => ['url' => 'https://pay.test', 'pid' => '1', 'key' => 'a'], 'enable' => true]);
    }

    /** @param callable(): array $pay */
    private function checkout(callable $pay): CheckoutService
    {
        return new class ($pay) extends CheckoutService {
            public function __construct(private $pay)
            {
            }

            protected function gateway(Payment $payment): PaymentService
            {
                $pay = $this->pay;
                return new class ($pay) extends PaymentService {
                    public function __construct(private $pay)
                    {
                    }

                    public function pay($order)
                    {
                        return ($this->pay)();
                    }
                };
            }
        };
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

    public function test_request_payment_counts_success_and_failure_with_gateway_reason(): void
    {
        $payment = $this->payment();
        $ok = $this->checkout(fn () => ['type' => 1, 'data' => 'https://pay.test/submit']);
        $ok->requestPayment($payment, 'order', 1, 'T1', 1, 1000);
        $ok->requestPayment($payment, 'order', 1, 'T1', 1, 1000); // 同一单连点：不重复计
        $ok->requestPayment($payment, 'order', 2, 'T2', 1, 1000);

        $fail = $this->checkout(function () {
            PaymentLinkStats::reason('单笔交易金额[1.00/20.00]');
            throw new ApiException('支付通道暂时不可用，请稍后重试或更换支付方式');
        });
        try {
            $fail->requestPayment($payment, 'order', 3, 'T3', 1, 5000);
            $this->fail('应当把网关错误抛给用户');
        } catch (ApiException $e) {
            $this->assertStringContainsString('支付通道暂时不可用', $e->getMessage());
        }

        $raw = $this->checkout(fn () => throw new \RuntimeException('cURL error 28: Operation timed out'));
        try {
            $raw->requestPayment($payment, 'order', 4, 'T4', 1, 1000);
        } catch (\RuntimeException $e) {
        }

        $day = PaymentLinkStats::day(date('Ymd'))[$payment->id];
        $this->assertSame(2, $day['ok']);
        $this->assertSame(2, $day['fail']);
        $this->assertSame(1, $day['reasons']['单笔限额']['count']);
        $this->assertSame('单笔交易金额[1.00/20.00]', $day['reasons']['单笔限额']['sample']);
        $this->assertSame(1, $day['reasons']['网关连不上']['count'], '插件没留原话时用异常信息归类');
    }

    public function test_reason_does_not_leak_into_the_next_request(): void
    {
        $payment = $this->payment();
        PaymentLinkStats::reason('请使用国内IP');   // 上一个请求留下的（Octane 下静态变量跨请求）
        $fail = $this->checkout(fn () => throw new ApiException('支付通道配置有误'));
        try {
            $fail->requestPayment($payment, 'order', 1, 'T1', 1, 1000);
        } catch (ApiException $e) {
        }
        $reasons = PaymentLinkStats::day(date('Ymd'))[$payment->id]['reasons'];
        $this->assertArrayNotHasKey('限国内IP', $reasons);
        $this->assertArrayHasKey('签名或配置错误', $reasons);
    }

    public function test_categories(): void
    {
        $this->assertSame('无可用通道', PaymentLinkStats::category('失败：未适配到通道，请检查金额是否符合'));
        $this->assertSame('无可用通道', PaymentLinkStats::category('无满足收款规则金额通道'));
        $this->assertSame('限国内IP', PaymentLinkStats::category('请使用国内IP访问'));
        $this->assertSame('通道维护', PaymentLinkStats::category('收银台维护中'));
        $this->assertSame('单号重复', PaymentLinkStats::category('商户交易订单已存在'));
        $this->assertSame('跳转地址不认', PaymentLinkStats::category('跳转地址不认：wxapi.example'));
        $this->assertSame('其他', PaymentLinkStats::category('奇怪的错误'));
    }

    public function test_daily_lines_show_counts_reasons_and_conversion(): void
    {
        $payment = $this->payment('微信支付');
        $now = time();
        foreach (['A', 'B', 'C', 'D'] as $no) {
            PaymentLinkStats::record($payment->id, $no, PaymentLinkStats::OK, '', $now);
        }
        PaymentLinkStats::record($payment->id, 'E', PaymentLinkStats::FAIL, '失败：未适配到通道', $now);
        $user = User::create(['email' => 'u@example.test', 'password' => 'x', 'uuid' => (string) Str::uuid(), 'token' => Str::random(32)]);
        $plan = Plan::create(['name' => '月付', 'group_id' => 1, 'show' => true, 'sell' => true, 'renew' => true, 'transfer_enable' => 100, 'prices' => ['monthly' => 10]]);
        Order::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'period' => 'monthly', 'type' => Order::TYPE_NEW_PURCHASE,
            'trade_no' => Helper::generateOrderNo(), 'total_amount' => 1000, 'status' => Order::STATUS_COMPLETED,
            'payment_id' => $payment->id, 'paid_at' => $now,
        ]);

        $text = implode("\n", (new PaymentLinkReport())->dailyLines(Carbon::today()));
        $this->assertStringContainsString("#{$payment->id} 微信支付：成功 4、失败 1（失败率 20%），已付 1（转化 25%）", $text);
        $this->assertStringContainsString('无可用通道 1 次', $text);
    }

    public function test_daily_report_command_includes_link_section(): void
    {
        $payment = $this->payment();
        PaymentLinkStats::record($payment->id, 'A', PaymentLinkStats::OK);
        $this->artisan('payment:verify-report', ['--no-send' => true])
            ->expectsOutputToContain('支付通道拉取（成功 / 失败 / 已付）')
            ->assertSuccessful();
    }

    public function test_health_alerts_once_when_most_attempts_fail(): void
    {
        $payment = $this->payment();
        $now = time();
        PaymentLinkStats::record($payment->id, 'A', PaymentLinkStats::OK, '', $now);
        PaymentLinkStats::record($payment->id, 'B', PaymentLinkStats::OK, '', $now);
        foreach (['C', 'D', 'E'] as $no) {
            PaymentLinkStats::record($payment->id, $no, PaymentLinkStats::FAIL, '无满足收款规则金额通道', $now);
        }

        $report = new PaymentLinkReport();
        $this->assertSame(1, $report->checkHealth($now));
        $this->assertSame(0, $report->checkHealth($now + 60), '3 小时内不重复');
        $alerts = $this->alerts();
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('拉取 5 次，失败 3 次（60%）', $alerts[0]);
        $this->assertStringContainsString('无可用通道', $alerts[0]);
    }

    public function test_health_stays_quiet_below_thresholds_and_for_disabled_channels(): void
    {
        $payment = $this->payment();
        $now = time();
        foreach (['A', 'B', 'C', 'D'] as $no) {
            PaymentLinkStats::record($payment->id, $no, PaymentLinkStats::FAIL, 'x', $now); // 只有 4 次
        }
        $this->assertSame(0, (new PaymentLinkReport())->checkHealth($now));

        PaymentLinkStats::record($payment->id, 'E', PaymentLinkStats::FAIL, 'x', $now);
        $payment->update(['enable' => false]);
        $this->assertSame(0, (new PaymentLinkReport())->checkHealth($now));
        Bus::assertNotDispatched(SendTelegramJob::class);
    }

    public function test_health_alerts_when_links_are_created_but_nobody_pays(): void
    {
        $payment = $this->payment();
        $user = User::create(['email' => 'u@example.test', 'password' => 'x', 'uuid' => (string) Str::uuid(), 'token' => Str::random(32)]);
        $plan = Plan::create(['name' => '月付', 'group_id' => 1, 'show' => true, 'sell' => true, 'renew' => true, 'transfer_enable' => 100, 'prices' => ['monthly' => 10]]);
        $now = Carbon::today()->setTime(20, 0)->timestamp;
        // 前 7 天：40 次成功拉取、付了 20 笔（转化 50%）
        for ($i = 0; $i < 40; $i++) {
            PaymentLinkStats::record($payment->id, "old{$i}", PaymentLinkStats::OK, '', $now - 2 * 86400);
        }
        for ($i = 0; $i < 20; $i++) {
            Order::create([
                'user_id' => $user->id, 'plan_id' => $plan->id, 'period' => 'monthly', 'type' => Order::TYPE_NEW_PURCHASE,
                'trade_no' => Helper::generateOrderNo(), 'total_amount' => 1000, 'status' => Order::STATUS_COMPLETED,
                'payment_id' => $payment->id, 'paid_at' => $now - 2 * 86400,
            ]);
        }
        // 最近 2 小时：8 次拉成功，0 笔付款
        for ($i = 0; $i < 8; $i++) {
            PaymentLinkStats::record($payment->id, "new{$i}", PaymentLinkStats::OK, '', $now - 3600 - $i * 300);
        }

        $this->assertSame(1, (new PaymentLinkReport())->checkHealth($now));
        $alerts = $this->alerts();
        $this->assertStringContainsString('拉到链接却没人付款', $alerts[0]);
        $this->assertStringContainsString('近 7 天转化 50%', $alerts[0]);
    }
}
