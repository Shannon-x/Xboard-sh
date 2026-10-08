<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P0-1 回归测试：支付回调 URL 里的支付方式（method）必须与 uuid 记录存储的方式一致。
 *
 * 漏洞：/payment/notify/{method}/{uuid} 之前用 uuid 取 config、却用 URL 的 method 选插件，
 * 两者不校验一致性。攻击者用一个 config 缺少目标插件签名密钥字段的网关 uuid，配合
 * 另一网关的 method，可让验签退化为 md5(明文参数) 从而伪造支付成功。
 *
 * 修复：PaymentService 构造函数在 uuid 分支强制 strcasecmp(payment, method) === 0，
 * 不符即抛 ApiException('payment method mismatch')。大小写不敏感以兼容网关对 URL 的归一化。
 */
class PaymentMethodBindingTest extends TestCase
{
    use RefreshDatabase;

    private function makePayment(string $method, array $config = []): Payment
    {
        return Payment::create([
            'uuid' => Str::random(32),
            'payment' => $method,
            'name' => $method . ' Gateway',
            'icon' => null,
            'config' => $config,
            'enable' => true,
        ]);
    }

    public function test_notify_rejects_method_uuid_mismatch(): void
    {
        // uuid 指向 EPay 记录，但回调 URL 的 method 是 Mgate（跨插件混淆攻击）
        $payment = $this->makePayment('EPay', ['key' => 'epay-secret']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('payment method mismatch');

        new PaymentService('Mgate', null, $payment->uuid);
    }

    public function test_notify_allows_exact_method_match(): void
    {
        // 合法回调：method 与 uuid 记录的 payment 完全一致，绝不能抛「方法不匹配」
        $payment = $this->makePayment('EPay', ['key' => 'epay-secret']);

        $this->assertMismatchNotThrownFor('EPay', $payment->uuid);
    }

    public function test_notify_allows_case_insensitive_method_match(): void
    {
        // 兼容网关对 URL 路径大小写归一化：epay 应视为与 EPay 一致，不得误伤合法回调
        $payment = $this->makePayment('EPay', ['key' => 'epay-secret']);

        $this->assertMismatchNotThrownFor('epay', $payment->uuid);
    }

    public function test_notify_rejects_unknown_uuid(): void
    {
        // 保留既有行为：uuid 不存在时抛 payment not found（在方法校验之前）
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('payment not found');

        new PaymentService('EPay', null, Str::random(32));
    }

    /**
     * 断言：给定 method/uuid 组合不会因「方法不匹配」被拒。
     *
     * 构造函数在通过一致性校验后会继续走插件查找；测试环境未启用任何支付插件，
     * 后续步骤可能因找不到插件类而抛其它异常——这与本安全校验无关。因此这里只断言
     * 「若抛异常，其消息不得是 payment method mismatch」，即校验没有误伤合法组合。
     */
    private function assertMismatchNotThrownFor(string $method, string $uuid): void
    {
        try {
            new PaymentService($method, null, $uuid);
            $this->assertTrue(true); // 构造成功同样满足预期
        } catch (\Throwable $e) {
            $this->assertStringNotContainsString(
                'payment method mismatch',
                $e->getMessage(),
                '合法的 method/uuid 组合被一致性校验误拒'
            );
        }
    }

    /** 假装启用了一个提供 EPay 的支付插件（测试环境默认不启用任何支付插件） */
    private function fakeEpayPlugin(): object
    {
        $plugin = new class {
            public array $config = [];
            public function getPluginCode(): string { return 'epay_test'; }
            public function setConfig(array $config): void { $this->config = $config; }
            public function notify($params) { return false; }
        };
        HookManager::registerFilter('available_payment_methods', fn ($methods) => $methods + ['EPay' => ['plugin_code' => 'epay_test']]);
        app()->instance(PluginManager::class, new class ($plugin) extends PluginManager {
            public function __construct(private object $fake) {}
            public function getEnabledPaymentPlugins(): array { return [$this->fake]; }
        });
        return $plugin;
    }

    public function test_case_insensitive_match_uses_the_stored_method_name(): void
    {
        // 网关把路径改成小写时，method 统一成库里的 EPay，按键名精确查插件才能查到
        $payment = $this->makePayment('EPay', ['key' => 'epay-secret']);
        $plugin = $this->fakeEpayPlugin();

        foreach (['epay', 'Epay', 'EPAY'] as $method) {
            $service = new PaymentService($method, null, $payment->uuid);
            $this->assertSame('EPay', $service->method, $method);
            $this->assertSame((int) $payment->id, (int) $plugin->config['id']);
        }
    }

    public function test_method_without_an_enabled_plugin_is_rejected_cleanly(): void
    {
        // 以前会走到 new $this->class 抛 Error（Class name must be a valid object or a string）
        $payment = $this->makePayment('EPay', ['key' => 'epay-secret']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('payment method not available');

        new PaymentService('EPay', null, $payment->uuid);
    }

    public function test_notify_endpoint_answers_rejected_callbacks_with_422_and_logs_the_source(): void
    {
        $payment = $this->makePayment('EPay', ['key' => 'epay-secret']);
        Log::spy();

        // 方式不符、方式大小写不同但没有插件、uuid 不存在：都是 422 + 同一句 verify error，不再 500
        $this->get("/api/v1/guest/payment/notify/Mgate/{$payment->uuid}", ['REMOTE_ADDR' => '194.36.145.128'])
            ->assertStatus(422)->assertJsonPath('message', 'verify error');
        $this->post("/api/v1/guest/payment/notify/Epay/{$payment->uuid}")
            ->assertStatus(422)->assertJsonPath('message', 'verify error');
        $this->get('/api/v1/guest/payment/notify/EPay/' . Str::random(8))
            ->assertStatus(422)->assertJsonPath('message', 'verify error');

        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => $message === '[payment] notify rejected'
            && $context['reason'] === 'payment method mismatch' && $context['ip'] === '194.36.145.128' && $context['method'] === 'Mgate')->once();
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => $message === '[payment] notify rejected'
            && $context['reason'] === 'payment method not available')->once();
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => $message === '[payment] notify rejected'
            && $context['reason'] === 'payment not found')->once();
        Log::shouldNotHaveReceived('error');
    }
}
