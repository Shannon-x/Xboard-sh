<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\ConfigSave;
use App\Jobs\SendBillingMailJob;
use App\Jobs\SendEmailJob;
use App\Models\BillingDocument;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\Billing\BillingArchive;
use App\Services\Billing\BillingDocumentService;
use App\Services\Billing\BillingPayService;
use App\Services\CheckoutService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\RenewService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 续费账单的免登录付款：邮件里的按钮打开 /pay/<账单凭据>，不用密码就能把这一张账单付掉。
 * 凭据只对应一张账单，付清 / 用别的方式续过费 / 到期后超过宽限天数就作废；下单与登录后的一键续费走同一段代码。
 */
class BillingPayLinkTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1073741824;
    private const APP_URL = 'https://panel.example.test';

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
        // Setting 缓存写死 Cache::store('redis')，CI 没起 Redis：桩成内存驱动，并丢掉启动时建好的 Setting 实例
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        app()->forgetScopedInstances();
        config(['v2board.app_name' => '苏菲家宽', 'v2board.app_url' => self::APP_URL . '/']);
        // 支付网关换成假的：不装插件，记下每次 pay() 收到的参数
        FakeGateway::$calls = [];
        $this->app->bind(CheckoutService::class, fn () => new FakeCheckout());
        $group = new ServerGroup();
        $group->name = '基础';
        $group->save();
        $this->plan = Plan::create([
            'name' => '静态家宽拼车', 'group_id' => $group->id, 'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => null, 'reset_traffic_method' => 1,
            'prices' => ['monthly' => 20, 'quarterly' => 57],
        ]);
    }

    private function user(array $attributes = []): User
    {
        return User::create($attributes + [
            'email' => 'shannon' . Str::random(6) . '@example.test', 'password' => 'x', 'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'plan_id' => $this->plan->id, 'group_id' => $this->plan->group_id, 'balance' => 0,
            'transfer_enable' => 100 * self::GB, 'u' => 0, 'd' => 0, 'device_limit' => 2,
            'remind_expire' => 1, 'remind_traffic' => 0, 'expired_at' => time() + 5 * 86400,
        ]);
    }

    /** 跑一遍账单邮件任务，返回开出的账单 */
    private function invoice(User $user, string $stage = BillingDocumentService::STAGE_FIRST): BillingDocument
    {
        $job = new SendBillingMailJob(SendBillingMailJob::KIND_INVOICE, $user->id, $stage, (int) $user->expired_at);
        $job->handle(app(BillingDocumentService::class), app(BillingArchive::class));
        return BillingDocument::where('user_id', $user->id)->where('kind', BillingDocument::KIND_INVOICE)->firstOrFail();
    }

    private function payment(string $method = 'EPay', array $attributes = []): Payment
    {
        return Payment::create($attributes + ['uuid' => Str::random(32), 'name' => $method, 'payment' => $method, 'config' => [], 'enable' => true]);
    }

    private function fetch(string $token)
    {
        return $this->postJson('/api/v1/guest/billing/pay/fetch', ['token' => $token]);
    }

    private function checkout(string $token, ?int $method, ?int $expected = null)
    {
        return $this->postJson('/api/v1/guest/billing/pay/checkout', ['token' => $token, 'method' => $method, 'expected_amount' => $expected]);
    }

    /** @return \Illuminate\Support\Collection<int, \Symfony\Component\Mailer\SentMessage> */
    private function sent()
    {
        return app('mailer')->getSymfonyTransport()->messages();
    }

    // ───────────────────────── 邮件里的链接 ─────────────────────────

    public function test_invoice_mail_and_pdf_carry_a_login_free_pay_link_that_survives_the_final_stage_refresh(): void
    {
        $user = $this->user();
        $doc = $this->invoice($user);
        $token = $doc->payToken();
        $url = self::APP_URL . '/pay/' . $token;

        $this->assertMatchesRegularExpression('/^\d+-[a-f0-9]{32}$/', $token);
        $this->assertSame($url, $doc->payload['pay_url']);
        $this->assertSame($url, $doc->payload['cta_url'], '按钮直接去付款页');
        $this->assertSame('立即付款', $doc->payload['cta_label']);
        $this->assertStringContainsString('7 天内有效', $doc->payload['pay_note']);
        $this->assertSame($this->plan->id, $doc->payload['plan_id']);
        $this->assertNull($doc->pay_order_id);

        $message = $this->sent()->first()->getOriginalMessage();
        $html = $message->getHtmlBody();
        $this->assertStringContainsString($url, $html);
        $this->assertStringContainsString('无需登录', $html);
        $this->assertStringNotContainsString('/plans?mode=renew', $html);
        $this->assertCount(1, $message->getAttachments(), 'PDF 照常附上（正文印的也是付款链接）');

        // 7 天档的链接已经下了单，24 小时档刷新快照：链接、订单绑定都不变
        $order = Order::create(['user_id' => $user->id, 'plan_id' => $this->plan->id, 'period' => 'monthly', 'type' => Order::TYPE_RENEWAL,
            'trade_no' => Helper::generateOrderNo(), 'total_amount' => 2000, 'status' => Order::STATUS_PENDING]);
        $doc->update(['pay_order_id' => $order->id]);
        $final = $this->invoice($user, BillingDocumentService::STAGE_FINAL);
        $this->assertSame($doc->id, $final->id);
        $this->assertSame(BillingDocumentService::STAGE_FINAL, $final->stage);
        $this->assertSame($order->id, (int) $final->pay_order_id, '刷新快照不丢订单绑定');
        $this->assertSame($token, $final->payToken(), '链接不变');
        $this->assertSame($url, $final->payload['pay_url']);
    }

    public function test_no_pay_link_when_the_switch_is_off_or_auto_renew_will_cover_the_bill(): void
    {
        config(['v2board.billing_pay_link_enable' => 0]);
        $doc = $this->invoice($this->user());
        $this->assertArrayNotHasKey('pay_url', $doc->payload);
        $this->assertSame(self::APP_URL . '/plans?mode=renew', $doc->payload['cta_url']);
        $this->assertStringContainsString('/plans?mode=renew', $this->sent()->first()->getOriginalMessage()->getHtmlBody());
        $this->fetch($doc->payToken())->assertStatus(200)->assertJsonPath('data.state', 'unavailable')->assertJsonPath('data.reason', 'disabled');
        $this->checkout($doc->payToken(), null)->assertStatus(400);

        config(['v2board.billing_pay_link_enable' => 1]);
        $doc = $this->invoice($this->user(['auto_renew' => 1, 'balance' => 5000]));
        $this->assertTrue($doc->payload['auto_covered']);
        $this->assertArrayNotHasKey('pay_url', $doc->payload, '余额够、自动续费会扣：这封只是告知');
        $this->assertSame(self::APP_URL . '/dashboard', $doc->payload['cta_url']);
    }

    public function test_expired_notice_and_auto_renew_shortfall_mail_link_the_pay_page_while_the_link_lives(): void
    {
        // 到期当天的「已暂停」通知：这期账单还在宽限期内，按钮直接去付款页
        $user = $this->user(['expired_at' => time() - 3600]);
        $doc = BillingDocument::create(['user_id' => $user->id, 'kind' => BillingDocument::KIND_INVOICE, 'stage' => 'final', 'expired_at' => $user->expired_at,
            'doc_no' => 'INV-TEST-1', 'amount' => 2000, 'access_key' => bin2hex(random_bytes(16)), 'payload' => ['doc_no' => 'INV-TEST-1', 'plan_id' => $this->plan->id], 'size' => 1, 'locale' => 'zh-CN']);
        $job = new SendBillingMailJob(SendBillingMailJob::KIND_EXPIRED, $user->id, '1', (int) $user->expired_at);
        $job->handle(app(BillingDocumentService::class), app(BillingArchive::class));
        $html = $this->sent()->first()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString(self::APP_URL . '/pay/' . $doc->payToken(), $html);
        $this->assertStringNotContainsString('/plans?mode=renew', $html);

        // 宽限期过了：回到登录后的续费入口
        config(['v2board.billing_pay_link_days' => 0]);
        $job->handle(app(BillingDocumentService::class), app(BillingArchive::class));
        $this->assertStringContainsString('/plans?mode=renew', $this->sent()->last()->getOriginalMessage()->getHtmlBody());
        config(['v2board.billing_pay_link_days' => 7]);

        // 自动续费余额不够的通知：同样给付款链接
        Bus::fake([SendEmailJob::class, SendBillingMailJob::class]);
        $short = $this->user(['expired_at' => time() + 20 * 3600, 'auto_renew' => 1, 'balance' => 500]);
        $bill = BillingDocument::create(['user_id' => $short->id, 'kind' => BillingDocument::KIND_INVOICE, 'stage' => 'first', 'expired_at' => $short->expired_at,
            'doc_no' => 'INV-TEST-2', 'amount' => 2000, 'access_key' => bin2hex(random_bytes(16)), 'payload' => ['doc_no' => 'INV-TEST-2'], 'size' => 1, 'locale' => 'zh-CN']);
        $this->assertSame('insufficient', app(RenewService::class)->attemptAutoRenew($short)['status']);
        Bus::assertDispatched(SendEmailJob::class, fn (SendEmailJob $job) => str_contains((fn () => $this->params)->call($job)['template_value']['content'], self::APP_URL . '/pay/' . $bill->payToken()));
    }

    // ───────────────────────── 付款页接口 ─────────────────────────

    public function test_fetch_rejects_forged_tokens_and_describes_a_payable_bill(): void
    {
        $user = $this->user(['email' => 'shannon@example.test']);
        $doc = $this->invoice($user);
        $epay = $this->payment('EPay');
        $this->payment('StripeSubscription');

        $this->fetch('abc')->assertStatus(404);
        $this->fetch($doc->id . '-' . str_repeat('0', 32))->assertStatus(404);
        $this->fetch(($doc->id + 1) . '-' . $doc->paySignature())->assertStatus(404);
        // 收据没有付款页：哪怕签名算得出来也当不存在
        $receipt = BillingDocument::create(['user_id' => $user->id, 'kind' => BillingDocument::KIND_RECEIPT, 'doc_no' => 'RC-TEST', 'amount' => 2000,
            'access_key' => bin2hex(random_bytes(16)), 'payload' => [], 'size' => 1, 'locale' => 'zh-CN']);
        $this->fetch($receipt->payToken())->assertStatus(404);

        $json = $this->fetch($doc->payToken())->assertStatus(200)->json('data');
        $this->assertSame('payable', $json['state']);
        $this->assertSame($doc->doc_no, $json['bill']['doc_no']);
        $this->assertSame('静态家宽拼车', $json['bill']['plan_name']);
        $this->assertSame(2000, $json['bill']['amount']);
        $this->assertSame((int) $user->expired_at, $json['bill']['expired_at']);
        $this->assertSame((int) $user->expired_at + 7 * 86400, $json['pay_until']);
        $this->assertSame('sh***@example.test', $json['email'], '邮箱打码');
        $this->assertSame(['amount' => 2000, 'list_amount' => 2000, 'balance_applied' => 0, 'cash_due' => 2000], array_intersect_key($json['quote'], array_flip(['amount', 'list_amount', 'balance_applied', 'cash_due'])));
        $this->assertSame('monthly', $json['quote']['period']);
        $this->assertNull($json['order']);
        $this->assertSame([$epay->id], array_column($json['methods'], 'id'), '订阅式网关不列');
        $this->assertArrayNotHasKey('user_id', $json);
        $this->assertStringNotContainsString($user->email, json_encode($json));
    }

    public function test_checkout_creates_one_renewal_order_bound_to_the_bill_and_returns_the_gateway_url(): void
    {
        $user = $this->user();
        $doc = $this->invoice($user);
        $token = $doc->payToken();
        $epay = $this->payment('EPay', ['handling_fee_percent' => 10]);
        $other = $this->payment('Mgate');

        $this->checkout($token, $epay->id, 1999)->assertStatus(400);   // 页面上的金额和现价对不上：不下单
        $this->assertSame(0, Order::count());

        $json = $this->checkout($token, $epay->id, 2000)->assertStatus(200)->json();
        $order = Order::firstOrFail();
        $this->assertSame(['type' => 1, 'data' => 'https://gateway.example.test/pay?no=' . $order->trade_no, 'trade_no' => $order->trade_no], $json);
        $this->assertSame((int) $user->id, (int) $order->user_id);
        $this->assertSame(Order::TYPE_RENEWAL, (int) $order->type);
        $this->assertSame('monthly', $order->period);
        $this->assertSame(2000, (int) $order->total_amount);
        $this->assertSame(200, (int) $order->handling_amount);
        $this->assertSame($epay->id, (int) $order->payment_id);
        $this->assertSame($order->id, (int) $doc->fresh()->pay_order_id, '订单记在账单上');
        $this->assertNull($doc->fresh()->order_id, '收据那一列不占');
        $this->assertSame(self::APP_URL . '/pay/' . $token, FakeGateway::$calls[0]['return_url'], '付完回到付款页，不是登录后的订单页');
        $this->assertSame(2200, FakeGateway::$calls[0]['total_amount']);

        // 再打开链接：接着付同一单，不另开
        $pending = $this->fetch($token)->assertStatus(200)->json('data');
        $this->assertSame('pending', $pending['state']);
        $this->assertSame($order->trade_no, $pending['order']['trade_no']);
        $this->assertSame($epay->id, $pending['order']['payment_id']);
        $this->checkout($token, $epay->id)->assertStatus(200)->assertJsonPath('trade_no', $order->trade_no);
        $this->assertSame(1, Order::count());
        $this->checkout($token, $other->id)->assertStatus(400);   // 已绑定别的支付方式
        $this->postJson('/api/v1/guest/billing/pay/check', ['token' => $token])->assertStatus(200)
            ->assertJsonPath('data.state', 'pending')->assertJsonPath('data.order_status', Order::STATUS_PENDING);

        // 网关回调付款成功：账单结清，收据另起一行、不覆盖这张账单
        $this->assertTrue((new OrderService($order))->paid('gateway-1'));
        $this->assertSame('paid', $this->fetch($token)->json('data.state'));
        $this->assertGreaterThan((int) $doc->expired_at, (int) $user->fresh()->expired_at);
        $this->assertSame(1, BillingDocument::where('order_id', $order->id)->where('kind', BillingDocument::KIND_RECEIPT)->count());
        $this->assertSame(BillingDocument::KIND_INVOICE, $doc->fresh()->kind);
        $this->checkout($token, $epay->id)->assertStatus(400);
    }

    public function test_balance_pays_the_bill_without_a_gateway(): void
    {
        $user = $this->user(['balance' => 2000]);
        $doc = $this->invoice($user);
        $json = $this->fetch($doc->payToken())->json('data');
        $this->assertSame(['balance_applied' => 2000, 'cash_due' => 0], array_intersect_key($json['quote'], array_flip(['balance_applied', 'cash_due'])));

        $this->checkout($doc->payToken(), null, 2000)->assertStatus(200)->assertJsonPath('type', -1);
        $order = Order::firstOrFail();
        $this->assertSame(0, (int) $order->total_amount);
        $this->assertSame(2000, (int) $order->balance_amount);
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->status);
        $this->assertSame(0, (int) $user->fresh()->balance);
        $this->assertSame('paid', $this->fetch($doc->payToken())->json('data.state'));
        $this->assertSame([], FakeGateway::$calls);
    }

    public function test_cancel_releases_only_the_bill_order_and_refunds_the_balance_part(): void
    {
        $user = $this->user(['balance' => 500]);
        $doc = $this->invoice($user);
        $token = $doc->payToken();
        $epay = $this->payment();

        $this->postJson('/api/v1/guest/billing/pay/cancel', ['token' => $token])->assertStatus(400);   // 还没下单
        $this->checkout($token, $epay->id)->assertStatus(200);
        $first = Order::firstOrFail();
        $this->assertSame(1500, (int) $first->total_amount);
        $this->assertSame(0, (int) $user->fresh()->balance);

        $this->postJson('/api/v1/guest/billing/pay/cancel', ['token' => $token])->assertStatus(200);
        $this->assertSame(Order::STATUS_CANCELLED, (int) $first->fresh()->status);
        $this->assertSame(500, (int) $user->fresh()->balance, '余额抵扣退回');
        $this->assertSame('payable', $this->fetch($token)->json('data.state'));

        // 取消后可以重新下单，账单改记新单
        $this->checkout($token, $epay->id)->assertStatus(200);
        $this->assertSame(2, Order::count());
        $second = Order::orderByDesc('id')->first();
        $this->assertSame($second->id, (int) $doc->fresh()->pay_order_id);
    }

    public function test_bill_states_follow_the_account(): void
    {
        $epay = $this->payment();

        // 用别的方式续过费：结清
        $user = $this->user();
        $doc = $this->invoice($user);
        $user->update(['expired_at' => $user->expired_at + 30 * 86400]);
        $this->fetch($doc->payToken())->assertJsonPath('data.state', 'settled');
        $json = $this->checkout($doc->payToken(), $epay->id)->assertStatus(400)->json();
        $this->assertStringContainsString('已经续费', $json['message']);

        // 到期后：宽限期内还能付，过了就失效
        $user = $this->user(['expired_at' => time() - 3 * 86400]);
        $doc = BillingDocument::create(['user_id' => $user->id, 'kind' => BillingDocument::KIND_INVOICE, 'stage' => 'final', 'expired_at' => $user->expired_at,
            'doc_no' => 'INV-TEST-3', 'amount' => 2000, 'access_key' => bin2hex(random_bytes(16)), 'payload' => ['plan_id' => $this->plan->id], 'size' => 1, 'locale' => 'zh-CN']);
        $this->fetch($doc->payToken())->assertJsonPath('data.state', 'payable');
        config(['v2board.billing_pay_link_days' => 2]);
        $this->fetch($doc->payToken())->assertJsonPath('data.state', 'expired');
        $this->checkout($doc->payToken(), $epay->id)->assertStatus(400);
        config(['v2board.billing_pay_link_days' => 7]);
        $this->checkout($doc->payToken(), $epay->id)->assertStatus(200);
        $this->assertSame(Order::TYPE_NEW_PURCHASE, (int) Order::firstOrFail()->type, '过期后续费按新购开通，与登录后一致');

        // 账号另有一笔待付单：先登录处理那一笔
        $user = $this->user();
        $doc = $this->invoice($user);
        $unrelated = Order::create(['user_id' => $user->id, 'plan_id' => $this->plan->id, 'period' => 'quarterly', 'type' => Order::TYPE_RENEWAL,
            'trade_no' => Helper::generateOrderNo(), 'total_amount' => 5700, 'status' => Order::STATUS_PENDING]);
        $this->fetch($doc->payToken())->assertJsonPath('data.state', 'blocked')->assertJsonPath('data.reason', 'other_order');
        $this->checkout($doc->payToken(), $epay->id)->assertStatus(400);
        $this->postJson('/api/v1/guest/billing/pay/cancel', ['token' => $doc->payToken()])->assertStatus(400);
        $this->assertSame(Order::STATUS_PENDING, (int) $unrelated->fresh()->status, '链接动不了别的订单');

        // 账单开出后换了套餐 / 套餐停售 / 账号封禁：不能照付
        $user = $this->user();
        $doc = $this->invoice($user);
        $other = Plan::create(['name' => '另一个', 'group_id' => $this->plan->group_id, 'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 200, 'device_limit' => 2, 'reset_traffic_method' => 1, 'prices' => ['monthly' => 30]]);
        $user->update(['plan_id' => $other->id, 'group_id' => $other->group_id]);
        $this->fetch($doc->payToken())->assertJsonPath('data.state', 'unavailable')->assertJsonPath('data.reason', 'plan_changed');
        $user->update(['plan_id' => $this->plan->id]);
        $this->plan->update(['renew' => false]);
        $json = $this->fetch($doc->payToken())->assertJsonPath('data.state', 'unavailable')->assertJsonPath('data.reason', 'spec')->json('data');
        $this->assertNotEmpty($json['message']);
        $this->plan->update(['renew' => true]);
        $user->update(['banned' => 1]);
        $this->fetch($doc->payToken())->assertJsonPath('data.state', 'unavailable')->assertJsonPath('data.reason', 'account');
    }

    public function test_panel_lists_the_pay_path_of_open_bills_and_the_settings_validate(): void
    {
        $user = $this->user();
        $doc = $this->invoice($user);
        Sanctum::actingAs($user);
        $rows = $this->getJson('/api/v1/user/billing/documents')->assertStatus(200)->json('data');
        $this->assertSame($doc->payPath(), $rows[0]['pay_path']);
        $user->update(['expired_at' => $user->expired_at + 30 * 86400]);
        $this->assertNull($this->getJson('/api/v1/user/billing/documents')->json('data.0.pay_path'), '结清的账单没有付款入口');

        $rules = (new ConfigSave())->rules();
        $this->assertTrue(Validator::make(['billing_pay_link_enable' => 1, 'billing_pay_link_days' => 7], $rules)->passes());
        $this->assertFalse(Validator::make(['billing_pay_link_days' => 31], $rules)->passes());
        $this->assertFalse(Validator::make(['billing_pay_link_days' => -1], $rules)->passes());

        $this->assertSame('a***@x.io', BillingPayService::maskEmail('abc@x.io'));
        $this->assertSame('sh***@x.io', BillingPayService::maskEmail('shannon@x.io'));
    }

    public function test_pay_actions_are_rate_limited_per_ip(): void
    {
        $doc = $this->invoice($this->user());
        for ($i = 0; $i < 10; $i++) {
            $this->checkout($doc->payToken(), null, 1)->assertStatus(400);
        }
        $this->checkout($doc->payToken(), null, 1)->assertStatus(429);
    }
}

/** 不连支付插件：记下 pay() 收到的参数，回一个固定的收款地址 */
class FakeGateway extends PaymentService
{
    public static array $calls = [];

    public function __construct()
    {
    }

    public function pay($order)
    {
        self::$calls[] = $order;
        return ['type' => 1, 'data' => 'https://gateway.example.test/pay?no=' . $order['trade_no']];
    }
}

class FakeCheckout extends CheckoutService
{
    protected function gateway(Payment $payment): PaymentService
    {
        return new FakeGateway();
    }
}
