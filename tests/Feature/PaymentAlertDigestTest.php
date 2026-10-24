<?php

namespace Tests\Feature;

use App\Jobs\SendEmailJob;
use App\Jobs\SendTelegramJob;
use App\Models\Order;
use App\Models\PaymentVerification;
use App\Models\User;
use App\Services\PaymentAlertDigest;
use App\Support\AdminAlert;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 管理员不常看 Telegram：付款异常告警同时推给客服 / 客服群，每天再发一封汇总邮件
 * （自动封号、待确认删号、核验统计、支付投诉）。
 *
 * Setting 用内存桩替换（真实实现走 redis 缓存）。
 */
class PaymentAlertDigestTest extends TestCase
{
    use RefreshDatabase;

    private array $settings = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Bus::fake([SendTelegramJob::class, SendEmailJob::class]);

        $stub = new class extends Setting {
            public array $store = [];

            public function __construct()
            {
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->store[strtolower($key)] ?? $default;
            }

            public function save(array $settings): bool
            {
                foreach ($settings as $k => $v) {
                    $this->store[strtolower($k)] = $v;
                }
                return true;
            }

            public function set(string $key, mixed $value = null): bool
            {
                return $this->save([$key => $value]);
            }
        };
        $stub->store = &$this->settings;
        $this->app->instance(Setting::class, $stub);
        $this->settings = ['app_name' => '测试站', 'app_url' => 'https://panel.example.test'];
    }

    private function user(array $attrs = []): User
    {
        return User::create($attrs + [
            'email' => Str::random(8) . '@example.test', 'password' => 'x', 'uuid' => (string) Str::uuid(),
            'token' => Str::random(32), 'balance' => 0, 'transfer_enable' => 0, 'expired_at' => 0,
        ]);
    }

    private function enforcedRow(?User $user, array $attrs = []): PaymentVerification
    {
        $order = Order::create([
            'user_id' => $user?->id ?? 999999, 'plan_id' => 1, 'period' => 'monthly', 'type' => Order::TYPE_NEW_PURCHASE,
            'trade_no' => (string) random_int(10 ** 15, 10 ** 16 - 1), 'total_amount' => 3520, 'status' => Order::STATUS_COMPLETED,
            'payment_id' => 1, 'callback_no' => 'GW' . Str::random(8), 'paid_at' => time() - 86400,
        ]);
        return PaymentVerification::create($attrs + [
            'subject_type' => 'order', 'subject_id' => $order->id, 'user_id' => $user?->id ?? 999999, 'payment_id' => 1,
            'callback_no' => $order->callback_no, 'expected_amount' => 3520, 'verdict' => 'refunded',
            'refunded_at' => time() - 3600, 'enforce_snapshot' => ['plan_id' => 45, 'expired_at' => time() + 86400, 'balance' => 0],
        ]);
    }

    public function test_alerts_reach_staff_and_extra_chats_only_when_asked(): void
    {
        $this->user(['is_admin' => 1, 'telegram_id' => 1001]);
        $this->user(['is_staff' => 1, 'telegram_id' => 2002]);
        $this->settings['admin_alert_chat_ids'] = '-100123, abc, -100123';

        AdminAlert::send('普通告警');
        AdminAlert::send('付款异常', 'markdown', true);

        $targets = [];
        Bus::assertDispatched(SendTelegramJob::class, function (SendTelegramJob $job) use (&$targets) {
            $targets[] = [(fn () => $this->telegramId)->call($job), (fn () => $this->text)->call($job)];
            return true;
        });
        $this->assertEqualsCanonicalizing([
            [1001, '普通告警'],
            [1001, '付款异常'], [2002, '付款异常'], [-100123, '付款异常'],
        ], $targets);
    }

    public function test_digest_lists_new_enforcements_pending_deletions_and_complaints(): void
    {
        $banned = $this->user(['banned' => 1, 'email' => 'refunder@example.test']);
        $this->enforcedRow($banned);
        $old = $this->enforcedRow($this->user(['banned' => 1, 'email' => 'old@example.test']), ['refunded_at' => time() - 5 * 86400]);
        $this->enforcedRow(null);   // 用户已删除：不再算待删
        PaymentVerification::create([
            'subject_type' => 'recharge', 'subject_id' => 1, 'payment_id' => 1, 'callback_no' => 'GWOK',
            'expected_amount' => 100, 'verdict' => 'ok', 'checked_at' => time() - 60,
        ]);
        PaymentAlertDigest::recordComplaint(['id' => 55, 'pay_type' => 'wxpay', 'money' => '35.20', 'problem_type' => '申请退款',
            'status' => 0, 'trade_no' => 'GW55', 'phone' => '13800000000']);

        $digest = (new PaymentAlertDigest())->build(time() - 86400, time());

        $this->assertSame(3, $digest['needs_action'], '待删 2 个 + 投诉 1 条');
        $this->assertStringContainsString('支付日报：3 项需处理', $digest['subject']);
        $this->assertStringContainsString('【自动封号】2 个', $digest['content']);
        $this->assertStringContainsString('refunder@example.test', $digest['content']);
        $this->assertStringContainsString('【待你确认删号】2 个', $digest['content']);
        $this->assertStringContainsString('old@example.test', $digest['content'], '早先封的、还没删的也要继续列出来');
        $this->assertStringContainsString('原套餐 #45', $digest['content']);
        $this->assertStringContainsString('投诉 #55 wxpay ¥35.20 申请退款', $digest['content']);
        $this->assertStringNotContainsString('13800000000', $digest['content'], '不外发手机号');
        $this->assertStringContainsString('一致 1', $digest['content']);
        $this->assertNotNull($old);
    }

    public function test_digest_says_all_clear_and_goes_to_admins_or_configured_addresses(): void
    {
        $this->user(['is_admin' => 1, 'email' => 'boss@example.test']);
        $digest = new PaymentAlertDigest();

        $quiet = $digest->build(time() - 86400, time());
        $this->assertSame(0, $quiet['needs_action']);
        $this->assertStringContainsString('一切正常', $quiet['subject']);

        $this->assertSame(1, $digest->send(time() - 86400, time()));
        Bus::assertDispatched(SendEmailJob::class, function (SendEmailJob $job) {
            $params = (fn () => $this->params)->call($job);
            return $params['email'] === 'boss@example.test' && $params['template_name'] === 'notify';
        });

        $this->settings['payment_alert_emails'] = 'a@example.test, b@example.test, not-an-email';
        $this->assertSame(['a@example.test', 'b@example.test'], $digest->recipients());
    }
}
