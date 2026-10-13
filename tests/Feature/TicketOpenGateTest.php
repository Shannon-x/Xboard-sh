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
 * 有效订阅 / 余额 / 佣金 / 付过款的老客户随时可开；从没付过款的只认近期走到收银台的订单。
 * 2026-10-12 有人批量注册小号、每号下一单不付（自动取消）就绕过了门槛去刷工单。
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

    public function test_user_with_only_an_unpaid_cancelled_order_cannot_open_a_ticket(): void
    {
        $user = $this->makeUser();
        // 下单不付、也没选支付方式，被系统自动取消
        $this->makeOrder($user, ['status' => Order::STATUS_CANCELLED]);

        $this->openTicket($user)->assertStatus(400);
        $this->assertSame(0, Ticket::where('user_id', $user->id)->count());
    }

    public function test_user_without_any_order_cannot_open_a_ticket(): void
    {
        $this->openTicket($user = $this->makeUser())->assertStatus(400);
        $this->assertSame(0, Ticket::where('user_id', $user->id)->count());
    }

    public function test_recent_checkout_order_can_open_a_ticket(): void
    {
        // 走到收银台但没到账（出码失败、付了钱没回调）——这类人必须能来问
        $user = $this->makeUser();
        $this->makeOrder($user, ['status' => Order::STATUS_CANCELLED, 'payment_id' => 1]);

        $this->openTicket($user)->assertStatus(200);
    }

    public function test_stale_checkout_order_no_longer_opens_tickets(): void
    {
        $user = $this->makeUser();
        $this->makeOrder($user, [
            'status' => Order::STATUS_CANCELLED,
            'payment_id' => 1,
            'created_at' => time() - 8 * 86400,
        ]);

        $this->openTicket($user)->assertStatus(400);
    }

    public function test_former_paying_customer_with_expired_plan_can_open_a_ticket(): void
    {
        $user = $this->makeUser();
        $this->makeOrder($user, [
            'status' => Order::STATUS_COMPLETED,
            'paid_at' => time() - 90 * 86400,
            'created_at' => time() - 90 * 86400,
        ]);

        $this->openTicket($user)->assertStatus(200);
    }

    public function test_commission_holder_can_open_a_ticket(): void
    {
        $this->openTicket($this->makeUser(['commission_balance' => 500]))->assertStatus(200);
    }
}
