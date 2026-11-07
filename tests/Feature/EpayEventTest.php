<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramJob;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentVerification;
use App\Models\User;
use App\Services\EpayEventService;
use App\Support\EpaySignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 易支付 V2 事件消息订阅（交易投诉 / 订单退款）接收端：/api/v1/guest/payment/notify/EPay/{uuid}/event。
 *
 * 推送只是「该去查了」的信号：验签通过后按网关交易号立即回查，网关两次都说退了才封号；
 * 验签不过只告警并提前拉一次投诉列表，不按推送里的单号做任何事。
 */
class EpayEventTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = 86400;

    private static ?array $platformKey = null;

    /** @var array<string, list<array>> 网关交易号 → 依次给出的查单应答 */
    private array $answers = [];

    /** @var array<string, string> 商户单号 → 网关交易号 */
    private array $byOutTradeNo = [];

    /** @var list<array> 投诉列表接口的应答行 */
    private array $complaints = [];

    /** @var list<string> 打到网关的请求路径 */
    private array $hits = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::$platformKey ??= self::keyPair();
        Cache::flush();
        Bus::fake([SendTelegramJob::class]);
        Http::preventStrayRequests();
        Http::fake(['gateway.test/*' => function (HttpRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $params = $request->data();
            $this->hits[] = $path;
            if ($path === '/api/complain/list') {
                return Http::response(self::platformSign(['code' => 0, 'data' => ['total' => count($this->complaints), 'rows' => $this->complaints]]));
            }
            $tradeNo = (string) ($params['trade_no'] ?? $this->byOutTradeNo[$params['out_trade_no'] ?? ''] ?? '');
            $queue = $this->answers[$tradeNo] ?? [];
            $answer = count($queue) > 1 ? array_shift($this->answers[$tradeNo]) : ($queue[0] ?? ['code' => -1, 'msg' => '订单号不存在']);
            return Http::response(self::platformSign($answer));
        }]);
        User::create([
            'email' => 'admin@example.test', 'password' => 'x', 'uuid' => (string) Str::uuid(),
            'token' => Str::random(32), 'is_admin' => 1, 'telegram_id' => 10001,
        ]);
    }

    // ───────────────────────── 工具 ─────────────────────────

    private static function keyPair(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        return ['private' => $private, 'public' => openssl_pkey_get_details($key)['key']];
    }

    private static function platformSign(array $params, ?string $privateKey = null): array
    {
        $params['timestamp'] ??= (string) time();
        openssl_sign(EpaySignature::content($params), $sig, $privateKey ?? self::$platformKey['private'], OPENSSL_ALGO_SHA256);
        $params['sign'] = base64_encode($sig);
        $params['sign_type'] = 'RSA';
        return $params;
    }

    private function v2Payment(array $overrides = []): Payment
    {
        $merchant = self::keyPair();
        return Payment::create(['uuid' => Str::random(8), 'payment' => 'EPay', 'name' => '牛牛支付', 'enable' => false, 'config' => $overrides + [
            'url' => 'https://gateway.test', 'pid' => '1001', 'api_version' => 'v2',
            'merchant_private_key' => $merchant['private'], 'platform_public_key' => self::$platformKey['public'],
        ]]);
    }

    /** 已付、已核验通过的订单（¥35.20，5 天前付款） */
    private function verifiedOrder(Payment $payment): array
    {
        $user = User::create([
            'email' => Str::random(8) . '@example.test', 'password' => 'x', 'uuid' => (string) Str::uuid(),
            'token' => Str::random(32), 'balance' => 0, 'transfer_enable' => 100 * 1073741824,
            'plan_id' => 7, 'expired_at' => time() + 30 * self::DAY,
        ]);
        $order = Order::create([
            'user_id' => $user->id, 'plan_id' => 7, 'period' => 'monthly', 'type' => Order::TYPE_NEW_PURCHASE,
            'trade_no' => (string) random_int(10 ** 15, 10 ** 16 - 1), 'total_amount' => 3520,
            'status' => Order::STATUS_COMPLETED, 'payment_id' => $payment->id,
            'callback_no' => 'GW' . Str::random(10), 'paid_at' => time() - 5 * self::DAY,
        ]);
        PaymentVerification::create([
            'subject_type' => 'order', 'subject_id' => $order->id, 'user_id' => $user->id, 'payment_id' => $payment->id,
            'callback_no' => $order->callback_no, 'expected_amount' => 3520, 'verdict' => 'ok', 'gateway_status' => 1,
        ]);
        return [$user, $order];
    }

    private function gatewaySays(Order $order, int ...$statuses): void
    {
        $this->answers[$order->callback_no] = array_map(fn (int $status) => [
            'code' => 0, 'trade_no' => $order->callback_no, 'out_trade_no' => $order->trade_no,
            'status' => $status, 'money' => '35.20', 'type' => 'alipay',
        ], $statuses);
        $this->byOutTradeNo[$order->trade_no] = $order->callback_no;
    }

    private function url(Payment $payment): string
    {
        return '/api/v1/guest/payment/notify/EPay/' . $payment->uuid . '/event';
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

    private function alertsContaining(string $needle): array
    {
        return array_values(array_filter($this->alerts(), fn ($t) => str_contains($t, $needle)));
    }

    // ───────────────────────── 退款推送 ─────────────────────────

    public function test_signed_refund_event_rechecks_at_once_and_bans_when_gateway_confirms(): void
    {
        $payment = $this->v2Payment();
        [$user, $order] = $this->verifiedOrder($payment);
        $this->gatewaySays($order, 2);

        $this->post($this->url($payment), self::platformSign([
            'pid' => '1001', 'event' => 'refund', 'trade_no' => $order->callback_no,
            'out_trade_no' => $order->trade_no, 'refundmoney' => '35.20',
        ]))->assertOk()->assertSee('success');

        $this->assertSame(1, (int) $user->fresh()->banned, '网关两次查单都是已退款 → 封号');
        $this->assertNull($user->fresh()->plan_id);
        $this->assertStringContainsString('退款推送', (string) PaymentVerification::where('subject_id', $order->id)->value('last_error'));
        $this->assertCount(1, $this->alertsContaining('事件订阅已打通'));
        $this->assertCount(1, $this->alertsContaining('已自动封号'));
    }

    public function test_json_event_with_nested_data_and_get_query_both_work(): void
    {
        $payment = $this->v2Payment();
        [$jsonUser, $jsonOrder] = $this->verifiedOrder($payment);
        [$getUser, $getOrder] = $this->verifiedOrder($payment);
        $this->gatewaySays($jsonOrder, 2);
        $this->gatewaySays($getOrder, 2);

        // 子对象不参与签名（与签名规则一致：数组跳过），单号从子对象里取
        $body = self::platformSign(['pid' => '1001', 'event_type' => 'order.refund']);
        $body['data'] = ['trade_no' => $jsonOrder->callback_no, 'out_trade_no' => $jsonOrder->trade_no];
        $this->postJson($this->url($payment), $body)->assertOk();

        $query = http_build_query(self::platformSign(['pid' => '1001', 'notify_type' => '订单退款', 'trade_no' => $getOrder->callback_no]));
        $this->get($this->url($payment) . '?' . $query)->assertOk();

        $this->assertSame(1, (int) $jsonUser->fresh()->banned);
        $this->assertSame(1, (int) $getUser->fresh()->banned);
    }

    public function test_refund_event_with_only_merchant_order_number_looks_up_the_gateway_number(): void
    {
        $payment = $this->v2Payment();
        [$user, $order] = $this->verifiedOrder($payment);
        $this->gatewaySays($order, 2);

        $this->post($this->url($payment), self::platformSign([
            'pid' => '1001', 'type' => 'refund', 'out_trade_no' => $order->trade_no,
        ]))->assertOk();

        $this->assertSame(1, (int) $user->fresh()->banned);
    }

    public function test_refund_event_while_gateway_still_says_paid_only_alerts(): void
    {
        $payment = $this->v2Payment();
        [$user, $order] = $this->verifiedOrder($payment);
        $this->gatewaySays($order, 1);

        $event = self::platformSign(['pid' => '1001', 'event' => 'refund', 'trade_no' => $order->callback_no]);
        $this->post($this->url($payment), $event)->assertOk();
        $this->post($this->url($payment), $event)->assertOk();

        $this->assertSame(0, (int) $user->fresh()->banned, '推送不是事实，以网关查单为准');
        $this->assertCount(1, $this->alertsContaining('查单仍显示已支付'));
        $this->assertCount(1, array_filter($this->hits, fn ($p) => $p === '/api/pay/query'), '重投的同一条推送 10 分钟内只查一次');
    }

    public function test_refund_event_for_unknown_order_alerts_once(): void
    {
        $payment = $this->v2Payment();
        $this->post($this->url($payment), self::platformSign(['pid' => '1001', 'event' => 'refund', 'trade_no' => 'GWnotours']))->assertOk();
        $this->assertCount(1, $this->alertsContaining('本站没有对应的已核验单'));
        $this->assertNotContains('/api/pay/query', $this->hits, '本站没有这笔就不查网关');
    }

    // ───────────────────────── 投诉推送 ─────────────────────────

    public function test_complaint_event_pulls_the_complaint_list_and_rechecks(): void
    {
        $payment = $this->v2Payment();
        [, $order] = $this->verifiedOrder($payment);
        $this->gatewaySays($order, 3);
        $this->complaints = [[
            'id' => 77, 'pay_type' => 'alipay', 'trade_no' => $order->callback_no, 'out_trade_no' => $order->trade_no,
            'problem_type' => '申请退款', 'status' => 0, 'money' => '35.20', 'edittime' => '2026-11-07 10:00:00', 'addtime' => '2026-11-07 10:00:00',
        ]];

        $this->post($this->url($payment), self::platformSign([
            'pid' => '1001', 'event' => 'complain', 'trade_no' => $order->callback_no, 'phone' => '13800000000',
        ]))->assertOk();

        $this->assertContains('/api/complain/list', $this->hits);
        $this->assertCount(1, $this->alertsContaining('新的支付投诉'));
        $this->assertCount(1, $this->alertsContaining('冻结'), '投诉中网关冻结了这笔 → 冻结告警');
        foreach ($this->alerts() as $text) {
            $this->assertStringNotContainsString('13800000000', $text, '推送里的手机号不进告警');
        }
    }

    // ───────────────────────── 拒收 ─────────────────────────

    public function test_bad_signature_does_nothing_with_the_order_but_polls_complaints(): void
    {
        $payment = $this->v2Payment();
        [$user, $order] = $this->verifiedOrder($payment);
        $this->gatewaySays($order, 2);
        $forged = self::platformSign(['pid' => '1001', 'event' => 'refund', 'trade_no' => $order->callback_no], self::keyPair()['private']);

        $this->post($this->url($payment), $forged)->assertStatus(422);
        $this->post($this->url($payment), $forged)->assertStatus(422);

        $this->assertSame(0, (int) $user->fresh()->banned);
        $this->assertNotContains('/api/pay/query', $this->hits, '验签不过不按推送里的单号查');
        $this->assertCount(1, array_filter($this->hits, fn ($p) => $p === '/api/complain/list'), '投诉列表 1 分钟内只拉一次');
        $this->assertCount(1, $this->alertsContaining('验签没过'), '1 小时内只提醒一次');
    }

    public function test_unknown_uuid_and_non_v2_configs_are_rejected(): void
    {
        $v1 = Payment::create(['uuid' => Str::random(8), 'payment' => 'EPay', 'name' => '易支付', 'enable' => true,
            'config' => ['url' => 'https://gateway.test', 'pid' => '80001', 'key' => 'v1-secret']]);
        $this->post($this->url($v1), ['event' => 'refund'])->assertStatus(422);
        $this->post('/api/v1/guest/payment/notify/EPay/' . Str::random(8) . '/event', ['event' => 'refund'])->assertStatus(422);
        $this->assertSame([], $this->hits);
    }

    public function test_describe_recognises_event_kinds_without_mistaking_pay_type(): void
    {
        $this->assertSame('unknown', EpayEventService::describe(['type' => 'alipay', 'trade_no' => 'x'])['kind']);
        $this->assertSame('refund', EpayEventService::describe(['type' => 'alipay', 'refund_no' => 'r1'])['kind']);
        $this->assertSame('complaint', EpayEventService::describe(['event' => 'trade.complaint'])['kind']);
        $this->assertSame('complaint', EpayEventService::describe(['data' => json_encode(['complain_id' => 1, 'trade_no' => 'T9'])]) ['kind']);
        $this->assertSame('T9', EpayEventService::describe(['data' => json_encode(['complain_id' => 1, 'trade_no' => 'T9'])])['trade_no']);
    }
}
