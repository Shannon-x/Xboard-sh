<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Jobs\SendTelegramJob;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\EpayGatewayMonitor;
use App\Support\EpayOrderQuery;
use App\Support\EpaySignature;
use App\Support\EpayV2;
use App\Support\PaymentGatewayBinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Plugin\Epay\Plugin as EpayPlugin;
use Tests\TestCase;

/**
 * 易支付 V2（SHA256WithRSA）接入与配套功能：签名规则、统一下单、回调验签、入账前向网关确认、
 * 投诉告警、日报对账；以及 V1 按文档去空值验签。
 *
 * 网关用 Http::fake 打桩，商户 / 平台两对 RSA 密钥在测试里现生成；配置全是假值。
 */
class EpayV2Test extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://gateway.test';

    /** @var array{merchant_private: string, merchant_public: string, platform_private: string, platform_public: string} */
    private static array $keys = [];

    /** @var array<string, array|\Closure> 按接口路径给出的网关应答 */
    private array $gateway = [];

    /** @var list<array{path: string, params: array}> 网关收到的请求 */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$keys === []) {
            self::$keys = self::keyPairs();
        }
        Cache::flush();
        Bus::fake([SendTelegramJob::class]);
        Http::preventStrayRequests();
        Http::fake(['gateway.test/*' => function (HttpRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $params = $request->method() === 'POST' ? $request->data() : $query;
            $this->requests[] = ['path' => $path, 'params' => $params];
            $answer = $this->gateway[$path] ?? Http::response('not found', 404);
            return $answer instanceof \Closure ? $answer($params) : $answer;
        }]);
        // 绑了 Telegram 的管理员：告警才会真的派发 SendTelegramJob
        User::create([
            'email' => 'admin@example.test', 'password' => 'x', 'uuid' => (string) Str::uuid(),
            'token' => Str::random(32), 'is_admin' => 1, 'telegram_id' => 10001,
        ]);
    }

    // ───────────────────────── 工具 ─────────────────────────

    /** @return array{merchant_private: string, merchant_public: string, platform_private: string, platform_public: string} */
    private static function keyPairs(): array
    {
        $pair = function (): array {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);
            return [$private, openssl_pkey_get_details($key)['key']];
        };
        [$mPriv, $mPub] = $pair();
        [$pPriv, $pPub] = $pair();
        return ['merchant_private' => $mPriv, 'merchant_public' => $mPub, 'platform_private' => $pPriv, 'platform_public' => $pPub];
    }

    /** 商户后台给的是去掉头尾、没有换行的裸 base64 */
    private static function bare(string $pem): string
    {
        return preg_replace('/-----(BEGIN|END)[A-Z ]*-----|\s+/', '', $pem);
    }

    private function v2Config(array $overrides = []): array
    {
        return $overrides + [
            'url' => self::URL, 'pid' => '1001', 'api_version' => 'v2', 'type' => 'alipay',
            'merchant_private_key' => self::bare(self::$keys['merchant_private']),
            'platform_public_key' => self::bare(self::$keys['platform_public']),
        ];
    }

    private function payment(array $config): Payment
    {
        return Payment::create([
            'uuid' => Str::random(8), 'payment' => 'EPay', 'name' => '易支付', 'config' => $config, 'enable' => true,
        ]);
    }

    private function plugin(Payment $payment): EpayPlugin
    {
        $plugin = new EpayPlugin('epay');
        $plugin->setConfig((array) $payment->config + ['id' => $payment->id]);
        return $plugin;
    }

    private function user(): User
    {
        return User::create([
            'email' => Str::random(10) . '@example.test', 'password' => 'x', 'uuid' => (string) Str::uuid(),
            'token' => Str::random(32), 'balance' => 0, 'transfer_enable' => 0, 'expired_at' => 0,
        ]);
    }

    private function order(Payment $payment, array $attrs = []): Order
    {
        return Order::create($attrs + [
            'user_id' => $this->user()->id, 'plan_id' => 1, 'period' => 'monthly', 'type' => Order::TYPE_NEW_PURCHASE,
            'trade_no' => (string) random_int(10 ** 15, 10 ** 16 - 1), 'total_amount' => 1000, 'handling_amount' => null,
            'status' => Order::STATUS_PENDING, 'payment_id' => $payment->id,
        ]);
    }

    private static function platformSign(array $params): array
    {
        $params['timestamp'] ??= (string) time();
        openssl_sign(EpaySignature::content($params), $sig, self::$keys['platform_private'], OPENSSL_ALGO_SHA256);
        $params['sign'] = base64_encode($sig);
        $params['sign_type'] = 'RSA';
        return $params;
    }

    private static function merchantSignatureValid(array $params): bool
    {
        return openssl_verify(EpaySignature::content($params), base64_decode($params['sign']), self::$keys['merchant_public'], OPENSSL_ALGO_SHA256) === 1;
    }

    private function notifyParams(Order $order, array $overrides = []): array
    {
        return self::platformSign($overrides + [
            'pid' => '1001', 'trade_no' => 'GW' . $order->trade_no, 'out_trade_no' => $order->trade_no,
            'api_trade_no' => '4200000001', 'type' => 'alipay', 'trade_status' => 'TRADE_SUCCESS',
            'name' => $order->trade_no, 'money' => '10.00', 'param' => '', 'addtime' => '2026-10-24 12:00:00',
        ]);
    }

    /** 网关查单应答：默认这笔已付、¥10 */
    private function gatewayKnows(Order $order, array $overrides = []): void
    {
        $this->gateway['/api/pay/query'] = Http::response(self::platformSign($overrides + [
            'code' => 0, 'trade_no' => 'GW' . $order->trade_no, 'out_trade_no' => $order->trade_no,
            'type' => 'alipay', 'status' => 1, 'pid' => 1001, 'money' => '10.00',
        ]));
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

    // ───────────────────────── 签名规则 ─────────────────────────

    public function test_sign_content_skips_empty_and_array_values_and_sorts_by_ascii(): void
    {
        $this->assertSame(
            'a=1&b=x y&money=1.00&pid=1001',
            EpaySignature::content([
                'pid' => 1001, 'money' => '1.00', 'b' => 'x y', 'a' => '1',
                'param' => '', 'buyer' => null, 'images' => ['1', '2'], 'sign' => 'zz', 'sign_type' => 'RSA',
            ])
        );
    }

    public function test_keys_are_accepted_as_bare_base64_or_pem_and_bad_keys_are_rejected(): void
    {
        $this->assertInstanceOf(EpayV2::class, EpayV2::fromConfig($this->v2Config()));
        $this->assertInstanceOf(EpayV2::class, EpayV2::fromConfig($this->v2Config([
            'merchant_private_key' => self::$keys['merchant_private'], 'platform_public_key' => self::$keys['platform_public'],
        ])));

        $this->expectException(\InvalidArgumentException::class);
        EpayV2::fromConfig($this->v2Config(['platform_public_key' => 'not-a-key']));
    }

    // ───────────────────────── 下单 ─────────────────────────

    public function test_v2_create_returns_jump_url_and_caches_it_per_attempt(): void
    {
        $payment = $this->payment($this->v2Config());
        $this->gateway['/api/pay/create'] = Http::response(self::platformSign([
            'code' => 0, 'trade_no' => '2026102412000001', 'pay_type' => 'jump', 'pay_info' => 'https://gateway.test/pay/abc',
        ]));
        $order = ['trade_no' => '202610240001-ab12cd34', 'total_amount' => 1990,
            'notify_url' => 'https://panel.test/notify', 'return_url' => 'https://panel.test/orders/1'];

        $first = $this->plugin($payment)->pay($order);
        $second = $this->plugin($payment)->pay($order);

        $this->assertSame(['type' => 1, 'data' => 'https://gateway.test/pay/abc'], $first);
        $this->assertSame($first, $second);
        $this->assertCount(1, $this->requests, '同一支付会话重复 checkout 不应重复向网关建单');
        $sent = $this->requests[0]['params'];
        $this->assertSame('/api/pay/create', $this->requests[0]['path']);
        $this->assertSame(['jump', 'alipay', '19.90', 'RSA', '1001'], [$sent['method'], $sent['type'], $sent['money'], $sent['sign_type'], $sent['pid']]);
        $this->assertArrayHasKey('clientip', $sent);
        $this->assertTrue(self::merchantSignatureValid($sent), '请求必须用商户私钥签名');
    }

    public function test_v2_create_failures_surface_as_user_facing_errors(): void
    {
        $payment = $this->payment($this->v2Config());
        $order = ['trade_no' => 'T1', 'total_amount' => 100, 'notify_url' => 'n', 'return_url' => 'r'];

        // 网关拒绝
        $this->gateway['/api/pay/create'] = Http::response(['code' => -1, 'msg' => '通道已关闭']);
        try {
            $this->plugin($payment)->pay($order);
            $this->fail('应抛出 ApiException');
        } catch (ApiException $e) {
            $this->assertStringContainsString('更换支付方式', $e->getMessage());
        }

        // 带了签名但签名不对（被篡改或平台公钥填错）：拒绝
        $tampered = self::platformSign(['code' => 0, 'pay_type' => 'jump', 'pay_info' => 'https://gateway.test/pay/x']);
        $tampered['pay_info'] = 'https://gateway.test/pay/y';
        $this->gateway['/api/pay/create'] = Http::response($tampered);
        try {
            $this->plugin($payment)->pay(['trade_no' => 'T2'] + $order);
            $this->fail('签名不对的应答应拒绝');
        } catch (ApiException) {
        }

        // 下单应答不带签名（网关实际行为）时，支付链接只认网关自己的域名
        $this->gateway['/api/pay/create'] = Http::response(['code' => 0, 'pay_type' => 'jump', 'pay_info' => 'https://evil.test/phish']);
        $this->expectException(ApiException::class);
        $this->plugin($payment)->pay(['trade_no' => 'T3'] + $order);
    }

    public function test_v2_create_accepts_unsigned_answer_pointing_at_the_gateway(): void
    {
        // 实测：统一下单的应答不带 sign / timestamp（与文档示例一致）
        $payment = $this->payment($this->v2Config());
        $this->gateway['/api/pay/create'] = Http::response([
            'code' => 0, 'trade_no' => '2026102412141389866', 'pay_type' => 'jump', 'pay_info' => self::URL . '/pay/submit/2026102412141389866/',
        ]);

        $result = $this->plugin($payment)->pay(['trade_no' => 'T4', 'total_amount' => 100, 'notify_url' => 'n', 'return_url' => 'r']);
        $this->assertSame(self::URL . '/pay/submit/2026102412141389866/', $result['data']);
    }

    public function test_v2_cashier_mode_builds_signed_submit_link(): void
    {
        $payment = $this->payment($this->v2Config(['type' => '']));
        $result = $this->plugin($payment)->pay(['trade_no' => 'T3', 'total_amount' => 1200, 'notify_url' => 'n', 'return_url' => 'r']);

        $this->assertSame(1, $result['type']);
        $this->assertStringStartsWith(self::URL . '/api/pay/submit?', $result['data']);
        parse_str((string) parse_url($result['data'], PHP_URL_QUERY), $query);
        $this->assertArrayNotHasKey('type', $query);
        $this->assertSame('12.00', $query['money']);
        $this->assertTrue(self::merchantSignatureValid($query));
        $this->assertCount(0, $this->requests);
    }

    // ───────────────────────── 回调 ─────────────────────────

    public function test_v2_notify_accepts_platform_signed_callback_with_extension_fields(): void
    {
        $payment = $this->payment($this->v2Config());
        $order = $this->order($payment);
        $this->gatewayKnows($order);

        // 文档：平台可能增加回调字段，验签必须带上扩展字段
        $result = $this->plugin($payment)->notify($this->notifyParams($order, ['buyer' => 'o9uAcc', 'new_field' => 'x']));

        $this->assertSame(['trade_no' => $order->trade_no, 'callback_no' => 'GW' . $order->trade_no], $result);
    }

    public function test_v2_notify_rejects_forgery_tampering_and_non_success(): void
    {
        $payment = $this->payment($this->v2Config());
        $order = $this->order($payment);
        $this->gatewayKnows($order);
        $plugin = $this->plugin($payment);

        $tampered = $this->notifyParams($order);
        $tampered['money'] = '0.01';
        $this->assertFalse($plugin->notify($tampered));

        // 用商户私钥（或任何非平台私钥）签的回调：配置泄露也伪造不了
        $forged = $this->notifyParams($order);
        unset($forged['sign']);
        openssl_sign(EpaySignature::content($forged), $sig, self::$keys['merchant_private'], OPENSSL_ALGO_SHA256);
        $forged['sign'] = base64_encode($sig);
        $this->assertFalse($plugin->notify($forged));

        $this->assertFalse($plugin->notify($this->notifyParams($order, ['trade_status' => 'TRADE_CLOSED'])));
        $this->assertFalse($plugin->notify($this->notifyParams($order, ['money' => '9.99'])));
        $this->assertFalse($plugin->notify(array_merge($this->notifyParams($order), ['sign_type' => 'MD5'])));
    }

    public function test_gateway_confirmation_rejects_unknown_or_unpaid_and_alerts_once(): void
    {
        $payment = $this->payment($this->v2Config());
        $order = $this->order($payment);
        $plugin = $this->plugin($payment);

        $this->gateway['/api/pay/query'] = Http::response(['code' => -1, 'msg' => '订单不存在']);
        $this->assertFalse($plugin->notify($this->notifyParams($order)));
        $this->assertFalse($plugin->notify($this->notifyParams($order)), '网关重投同一条回调仍拒收');

        $this->gatewayKnows($order, ['status' => 0]);
        $this->assertFalse($plugin->notify($this->notifyParams($order)));

        $alerts = $this->alerts();
        $this->assertCount(1, $alerts, '每单只告警一次');
        $this->assertStringContainsString('网关核验拒收', $alerts[0]);
    }

    public function test_gateway_confirmation_fails_open_when_gateway_query_is_unavailable(): void
    {
        $payment = $this->payment($this->v2Config());
        $order = $this->order($payment);
        $this->gateway['/api/pay/query'] = Http::response('bad gateway', 502);

        $this->assertIsArray($this->plugin($payment)->notify($this->notifyParams($order)));
    }

    public function test_gateway_confirmation_can_be_switched_off(): void
    {
        $payment = $this->payment($this->v2Config(['verify_before_fulfil' => '0']));
        $order = $this->order($payment);

        $this->assertIsArray($this->plugin($payment)->notify($this->notifyParams($order)));
        $this->assertCount(0, $this->requests);
    }

    public function test_v1_notify_follows_the_spec_and_ignores_empty_params(): void
    {
        $payment = $this->payment(['url' => self::URL, 'pid' => '80001', 'key' => 'v1-secret']);
        $order = $this->order($payment);
        // 现役网关每笔回调都带空的 param=；按文档空值不参与签名
        $params = [
            'pid' => '80001', 'trade_no' => 'GW1', 'out_trade_no' => $order->trade_no, 'type' => 'alipay',
            'name' => $order->trade_no, 'money' => '10.00', 'trade_status' => 'TRADE_SUCCESS', 'param' => '',
        ];
        $params['sign'] = md5('money=10.00&name=' . $order->trade_no . '&out_trade_no=' . $order->trade_no
            . '&pid=80001&trade_no=GW1&trade_status=TRADE_SUCCESS&type=alipay' . 'v1-secret');
        $params['sign_type'] = 'MD5';

        $this->gateway['/api.php'] = Http::response([
            'code' => 1, 'trade_no' => 'GW1', 'out_trade_no' => $order->trade_no, 'status' => 1, 'money' => '10.00', 'type' => 'alipay',
        ]);
        $this->assertIsArray($this->plugin($payment)->notify($params));
        $this->assertSame('order', $this->requests[0]['params']['act'], 'V1 入账前走 act=order 确认');

        $this->gateway['/api.php'] = Http::response(['code' => -1, 'msg' => '订单号不存在']);
        $this->assertFalse($this->plugin($payment)->notify($params), '签名有效但网关没有这笔 = 伪造回调');
    }

    public function test_v1_pay_link_formats_money_with_two_decimals(): void
    {
        $payment = $this->payment(['url' => self::URL . '/', 'pid' => '80001', 'key' => 'v1-secret']);
        $result = $this->plugin($payment)->pay(['trade_no' => 'T9', 'total_amount' => 3520, 'notify_url' => 'n', 'return_url' => 'r']);

        $this->assertStringStartsWith(self::URL . '/submit.php?', $result['data']);
        parse_str((string) parse_url($result['data'], PHP_URL_QUERY), $query);
        $this->assertSame('35.20', $query['money']);
        $this->assertTrue(EpaySignature::verifyMd5($query, 'v1-secret'));
    }

    public function test_disabled_gateway_keeps_accepting_callbacks_for_three_days_only(): void
    {
        // 换网关：旧配置停用后，切换前打开的旧收银台还能付款，回调要能收
        $payment = $this->payment($this->v2Config(['verify_before_fulfil' => '0']));
        $order = $this->order($payment);
        $payment->forceFill(['enable' => false])->save();
        $plugin = new EpayPlugin('epay');
        \App\Services\Plugin\HookManager::registerFilter('available_payment_methods', fn ($methods) => $methods + ['EPay' => ['plugin_code' => $plugin->getPluginCode()]]);
        app()->instance(\App\Services\Plugin\PluginManager::class, new class ($plugin) extends \App\Services\Plugin\PluginManager {
            public function __construct(private object $fake)
            {
            }

            public function getEnabledPaymentPlugins(): array
            {
                return [$this->fake];
            }
        });

        $result = (new \App\Services\PaymentService('EPay', null, $payment->uuid))->notify($this->notifyParams($order));
        $this->assertIsArray($result);

        Payment::where('id', $payment->id)->update(['updated_at' => time() - \App\Services\PaymentService::NOTIFY_GRACE_AFTER_DISABLE - 60]);
        $this->expectException(ApiException::class);
        (new \App\Services\PaymentService('EPay', null, $payment->uuid))->notify($this->notifyParams($order));
    }

    // ───────────────────────── 查单 / 同源判定 ─────────────────────────

    public function test_order_query_supports_v2_for_after_the_fact_verification(): void
    {
        $config = $this->v2Config();
        $order = $this->order($this->payment($config));
        $this->gatewayKnows($order);

        $found = EpayOrderQuery::query($config, 'GW' . $order->trade_no);
        $this->assertSame([EpayOrderQuery::FOUND, 1, 1000, $order->trade_no], [$found['outcome'], $found['status'], $found['money'], $found['out_trade_no']]);

        $this->gateway['/api/pay/query'] = Http::response(['code' => -1, 'msg' => '订单不存在']);
        $this->assertSame(EpayOrderQuery::NOT_FOUND, EpayOrderQuery::query($config, 'GW-x')['outcome']);

        // 平台公钥填错：是配置问题，要告警而不是当成「没有这笔」
        $wrongKey = $this->v2Config(['platform_public_key' => self::bare(self::$keys['merchant_public'])]);
        $this->gatewayKnows($order);
        $this->assertSame(EpayOrderQuery::AUTH_ERROR, EpayOrderQuery::query($wrongKey, 'GW' . $order->trade_no)['outcome']);
    }

    public function test_toggles_and_empty_fields_do_not_split_same_merchant_configs(): void
    {
        $base = ['url' => 'https://pay.test/', 'pid' => '80001', 'key' => 'k'];
        $alipay = $this->payment($base + ['type' => 'alipay']);
        $wechat = $this->payment($base + ['type' => 'wxpay', 'api_version' => '', 'merchant_private_key' => '',
            'platform_public_key' => '', 'v2_pay_mode' => '', 'verify_before_fulfil' => '0']);
        $other = $this->payment(['url' => 'https://pay.test/', 'pid' => '80001', 'key' => 'other']);

        $this->assertTrue(PaymentGatewayBinding::equivalent($alipay->id, $wechat->id));
        $this->assertFalse(PaymentGatewayBinding::equivalent($alipay->id, $other->id));
    }

    // ───────────────────────── 投诉 / 日报 ─────────────────────────

    public function test_complaints_alert_once_per_state_and_skip_settled_history(): void
    {
        $payment = $this->payment($this->v2Config());
        $order = $this->order($payment, ['status' => Order::STATUS_COMPLETED, 'callback_no' => 'GW400']);
        $rows = [
            ['id' => 1, 'pay_type' => 'wxpay', 'trade_no' => 'GW400', 'out_trade_no' => $order->trade_no . '-ab12cd34',
                'problem_type' => '申请退款', 'problem_description' => '<b>没收到</b>', 'status' => 0, 'money' => '10.00',
                'edittime' => '2026-10-24 10:00:00', 'addtime' => '2026-10-24 10:00:00', 'phone' => '13800000000'],
            ['id' => 2, 'pay_type' => 'alipay', 'trade_no' => 'GW-old', 'status' => 2, 'money' => '5.00',
                'edittime' => '2026-10-01 10:00:00', 'addtime' => '2026-10-01 10:00:00'],
        ];
        // data 是数组，不参与签名
        $this->gateway['/api/complain/list'] = function () use (&$rows) {
            return Http::response(self::platformSign(['code' => 0, 'data' => ['total' => 2, 'rows' => $rows]]));
        };

        $monitor = new EpayGatewayMonitor();
        $this->assertSame(1, $monitor->complaints()['alerted']);
        $this->assertSame(0, $monitor->complaints()['alerted'], '同一状态不重复告警');

        $rows[0]['status'] = 1;
        $rows[0]['edittime'] = '2026-10-24 11:00:00';
        $this->assertSame(1, $monitor->complaints()['alerted'], '状态变化再告警');

        $alerts = $this->alerts();
        $this->assertCount(2, $alerts);
        $this->assertStringContainsString($order->trade_no, $alerts[0], '找回我方订单号（去掉会话后缀）');
        $this->assertStringContainsString('&lt;b&gt;没收到&lt;/b&gt;', $alerts[0], '投诉内容要转义');
        $this->assertStringNotContainsString('13800000000', $alerts[0], '不外发用户手机号');
    }

    public function test_merchant_summary_reconciles_gateway_income_with_our_ledger(): void
    {
        $payment = $this->payment($this->v2Config());
        $this->order($payment, ['status' => Order::STATUS_COMPLETED, 'callback_no' => 'GW500', 'total_amount' => 1000, 'paid_at' => time()]);
        $this->gateway['/api/merchant/info'] = Http::response(self::platformSign([
            'code' => 0, 'pid' => 1001, 'status' => 1, 'money' => '88.00', 'order_num_today' => 2, 'order_money_today' => '30.00',
        ]));

        $lines = (new EpayGatewayMonitor())->merchantSummary();
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('余额 ¥88.00；网关今日 2 单 ¥30.00，我方入账 ¥10.00', $lines[0]);
        $this->assertStringContainsString('差额 ¥20.00', $lines[0]);
    }
}
