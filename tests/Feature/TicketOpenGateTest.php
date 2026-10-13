<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Setting;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 「需有效订阅才能开工单」的放行口径：
 * 有效订阅 / 余额 / 佣金随时可开；其余用户要下过单（没付成的也算）且邮箱已验证。
 * 2026-10-12 有人批量注册假邮箱小号、每号下一单不付（自动取消）就绕过了门槛去刷工单。
 *
 * Setting 用内存桩替换（同 TicketCategoryTest）。
 */
class TicketOpenGateTest extends TestCase
{
    use RefreshDatabase;

    private array $settings = [];

    protected function setUp(): void
    {
        parent::setUp();
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
        $this->settings = ['ticket_active_subscription_required' => 1];
    }

    private function makeUser(array $overrides = []): User
    {
        return User::create($overrides + [
            'email' => 'gate-' . Str::random(6) . '@example.com',
            'password' => 'x',
            'uuid' => Str::uuid()->toString(),
            'token' => bin2hex(random_bytes(16)),
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'expired_at' => 0,
            'plan_id' => null,
            'group_id' => null,
        ]);
    }

    private function makeOrder(User $user, array $attributes): Order
    {
        return Order::create($attributes + [
            'user_id' => $user->id, 'plan_id' => 1, 'period' => 'monthly', 'type' => Order::TYPE_NEW_PURCHASE,
            'trade_no' => Helper::generateOrderNo(), 'total_amount' => 2000, 'status' => Order::STATUS_PENDING,
        ]);
    }

    private function openTicket(User $user)
    {
        Sanctum::actingAs($user);

        return $this->postJson('/api/v1/user/ticket/save', [
            'subject' => '付款问题',
            'level' => 1,
            'category' => 'billing',
            'message' => '付了钱没到账',
        ]);
    }

    public function test_unverified_user_with_only_a_failed_order_is_sent_to_email_verification(): void
    {
        // 10-12 的小号就是这样：下单不付（被自动取消），邮箱是假的
        $user = $this->makeUser();
        $this->makeOrder($user, ['status' => Order::STATUS_CANCELLED]);

        $this->openTicket($user)->assertStatus(403)->assertJsonPath('error.reason', 'email_unverified');
        $this->assertSame(0, Ticket::where('user_id', $user->id)->count());
    }

    public function test_verified_user_with_a_failed_order_can_open_a_ticket(): void
    {
        // 付款出问题（出码失败、付了没到账）的人订单多半是取消状态，验证过邮箱就要能来问
        $user = $this->makeUser(['email_verified_at' => time()]);
        $this->makeOrder($user, ['status' => Order::STATUS_CANCELLED]);

        $this->openTicket($user)->assertStatus(200);
        $this->assertSame(1, Ticket::where('user_id', $user->id)->count());
    }

    public function test_verified_user_without_any_order_cannot_open_a_ticket(): void
    {
        $user = $this->makeUser(['email_verified_at' => time()]);

        $this->openTicket($user)->assertStatus(400);
        $this->assertSame(0, Ticket::where('user_id', $user->id)->count());
    }

    public function test_former_paying_customer_must_verify_email_too(): void
    {
        $user = $this->makeUser();
        $this->makeOrder($user, [
            'status' => Order::STATUS_COMPLETED,
            'paid_at' => time() - 90 * 86400,
            'created_at' => time() - 90 * 86400,
        ]);

        $this->openTicket($user)->assertStatus(403)->assertJsonPath('error.reason', 'email_unverified');

        $user->forceFill(['email_verified_at' => time()])->save();
        $this->openTicket($user->fresh())->assertStatus(200);
    }

    public function test_active_subscriber_is_not_gated_by_email_verification(): void
    {
        $user = $this->makeUser(['plan_id' => 1, 'expired_at' => time() + 30 * 86400]);

        $this->openTicket($user)->assertStatus(200);
    }

    public function test_commission_holder_without_orders_is_not_gated(): void
    {
        $this->openTicket($this->makeUser(['commission_balance' => 500]))->assertStatus(200);
    }

    public function test_gate_off_lets_anyone_open_a_ticket(): void
    {
        $this->settings['ticket_active_subscription_required'] = 0;

        $this->openTicket($this->makeUser())->assertStatus(200);
    }
}
