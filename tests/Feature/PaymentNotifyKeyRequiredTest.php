<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Plugin\Btcpay\Plugin as BtcpayPlugin;
use Plugin\Coinbase\Plugin as CoinbasePlugin;
use Plugin\CoinPayments\Plugin as CoinPaymentsPlugin;
use Plugin\Mgate\Plugin as MgatePlugin;
use Tests\TestCase;

/**
 * 回调验签依赖后台填写的密钥：密钥留空时，签名用空串算出来，任何人都能算对。
 * 这种配置下一律不认回调；密钥填了的正常回调照旧通过。
 */
class PaymentNotifyKeyRequiredTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $serverKeys = [];

    /** 回调里的单号都对应一张 ¥10 的待付订单，金额校验能过，留下的只有验签这一关。 */
    protected function setUp(): void
    {
        parent::setUp();
        $user = User::create([
            'email' => 'notify-key@example.com', 'password' => 'x', 'uuid' => Str::uuid()->toString(),
            'token' => Str::random(32), 'balance' => 0, 'transfer_enable' => 0, 'expired_at' => 0,
        ]);
        foreach (['T20261101000001', 'T20261101000002', 'T20261101000003'] as $tradeNo) {
            Order::create([
                'user_id' => $user->id, 'plan_id' => 1, 'period' => Plan::PERIOD_MONTHLY, 'trade_no' => $tradeNo,
                'total_amount' => 1000, 'type' => Order::TYPE_NEW_PURCHASE, 'status' => Order::STATUS_PENDING,
            ]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->serverKeys as $key) {
            unset($_SERVER[$key]);
        }
        parent::tearDown();
    }

    /** getallheaders()（CLI 下是 polyfill）读 $_SERVER，request() 读容器里的请求：两边都要摆好。 */
    private function fakeWebhook(string $body, array $headers): void
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
            $server[$key] = $value;
            $_SERVER[$key] = $value;
            $this->serverKeys[] = $key;
        }
        $this->app->instance('request', Request::create('/api/v1/guest/payment/notify/x/y', 'POST', [], [], [], $server, $body));
    }

    private function mgate(string $secret): MgatePlugin
    {
        $plugin = new MgatePlugin('mgate');
        $plugin->setConfig(['mgate_url' => 'https://mgate.invalid', 'mgate_app_id' => 'app-1', 'mgate_app_secret' => $secret]);
        return $plugin;
    }

    private static function mgateSign(array $params, string $secret): string
    {
        ksort($params);
        return md5(http_build_query($params) . $secret);
    }

    private const MGATE_PARAMS = ['out_trade_no' => 'T20261101000001', 'trade_no' => 'MG-1', 'app_id' => 'app-1', 'total_amount' => '1000'];

    public function test_mgate_ignores_notify_when_secret_is_empty(): void
    {
        foreach (['', '   '] as $secret) {
            $params = self::MGATE_PARAMS + ['sign' => self::mgateSign(self::MGATE_PARAMS, $secret)];
            $this->assertFalse($this->mgate($secret)->notify($params), var_export($secret, true));
        }
    }

    public function test_mgate_still_accepts_correctly_signed_notify(): void
    {
        $params = self::MGATE_PARAMS + ['sign' => self::mgateSign(self::MGATE_PARAMS, 'mgate-secret')];
        $result = $this->mgate('mgate-secret')->notify($params);
        $this->assertSame(['trade_no' => 'T20261101000001', 'callback_no' => 'MG-1'], $result);

        $params['sign'] = self::mgateSign(self::MGATE_PARAMS, 'other');
        $this->assertFalse($this->mgate('mgate-secret')->notify($params));
    }

    private function coinbase(?string $key): CoinbasePlugin
    {
        $plugin = new CoinbasePlugin('coinbase');
        $plugin->setConfig(array_filter(['coinbase_url' => 'https://commerce.invalid', 'coinbase_api_key' => 'api', 'coinbase_webhook_key' => $key], fn ($v) => $v !== null));
        return $plugin;
    }

    private static function coinbaseBody(): string
    {
        return json_encode(['event' => [
            'id' => 'evt-1',
            'type' => 'charge:confirmed',
            'data' => ['metadata' => ['outTradeNo' => 'T20261101000002'], 'pricing' => ['local' => ['amount' => '10.00', 'currency' => 'CNY']]],
        ]]);
    }

    public function test_coinbase_ignores_webhook_when_key_is_missing(): void
    {
        foreach ([null, '', ' '] as $key) {
            $body = self::coinbaseBody();
            $this->fakeWebhook($body, ['X-Cc-Webhook-Signature' => hash_hmac('sha256', $body, (string) $key)]);
            try {
                $this->coinbase($key)->notify([]);
                $this->fail('accepted with key ' . var_export($key, true));
            } catch (ApiException $e) {
                $this->assertSame('Webhook key is not configured', $e->getMessage());
            }
        }
    }

    public function test_coinbase_still_accepts_correctly_signed_webhook(): void
    {
        $body = self::coinbaseBody();
        $this->fakeWebhook($body, ['X-Cc-Webhook-Signature' => hash_hmac('sha256', $body, 'coinbase-key')]);
        $this->assertSame(
            ['trade_no' => 'T20261101000002', 'callback_no' => 'evt-1'],
            $this->coinbase('coinbase-key')->notify([])
        );
    }

    private function btcpay(string $key): BtcpayPlugin
    {
        $plugin = new BtcpayPlugin('btcpay');
        // 回查地址故意不可达：验签放行的话会走到回查并报「Unable to fetch」，说明没在验签处拦住
        $plugin->setConfig(['btcpay_url' => 'http://127.0.0.1:9/', 'btcpay_storeId' => 'store', 'btcpay_api_key' => 'api', 'btcpay_webhook_key' => $key]);
        return $plugin;
    }

    public function test_btcpay_ignores_webhook_when_key_is_empty(): void
    {
        $body = json_encode(['invoiceId' => 'inv-1', 'type' => 'InvoiceSettled']);
        $this->fakeWebhook($body, ['Btcpay-Sig' => 'sha256=' . hash_hmac('sha256', $body, '')]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Webhook key is not configured');
        $this->btcpay('')->notify([]);
    }

    public function test_btcpay_with_key_still_checks_signature_then_looks_up_invoice(): void
    {
        $body = json_encode(['invoiceId' => 'inv-1', 'type' => 'InvoiceSettled']);
        $this->fakeWebhook($body, ['Btcpay-Sig' => 'sha256=' . hash_hmac('sha256', $body, 'btcpay-key')]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unable to fetch BTCPay invoice');
        $this->btcpay('btcpay-key')->notify([]);
    }

    private function coinPayments(string $secret): CoinPaymentsPlugin
    {
        $plugin = new CoinPaymentsPlugin('coin_payments');
        $plugin->setConfig(['coinpayments_merchant_id' => 'merchant-1', 'coinpayments_ipn_secret' => $secret, 'coinpayments_currency' => 'CNY']);
        return $plugin;
    }

    private const COINPAYMENTS_PARAMS = [
        'merchant' => 'merchant-1', 'status' => '100', 'item_number' => 'T20261101000003',
        'txn_id' => 'CP-1', 'amount1' => '10.00', 'currency1' => 'CNY',
    ];

    private static function coinPaymentsHmac(array $params, string $secret): string
    {
        ksort($params);
        return hash_hmac('sha512', stripslashes(http_build_query($params)), trim($secret));
    }

    public function test_coinpayments_ignores_ipn_when_secret_is_empty(): void
    {
        foreach (['', '  '] as $secret) {
            $this->fakeWebhook('', ['Hmac' => self::coinPaymentsHmac(self::COINPAYMENTS_PARAMS, $secret)]);
            try {
                $this->coinPayments($secret)->notify(self::COINPAYMENTS_PARAMS);
                $this->fail('accepted with secret ' . var_export($secret, true));
            } catch (ApiException $e) {
                $this->assertSame('IPN secret is not configured', $e->getMessage());
            }
        }
    }

    public function test_coinpayments_still_accepts_correctly_signed_ipn(): void
    {
        $this->fakeWebhook('', ['Hmac' => self::coinPaymentsHmac(self::COINPAYMENTS_PARAMS, 'ipn-secret')]);
        $result = $this->coinPayments('ipn-secret')->notify(self::COINPAYMENTS_PARAMS);
        $this->assertSame('T20261101000003', $result['trade_no']);
        $this->assertSame('CP-1', $result['callback_no']);
    }
}
