<?php

namespace Tests\Feature;

use App\Console\Commands\CheckCommission;
use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * check:commission 的 autoCheck 改成「只读选 id → 按主键分批 UPDATE」后，结果必须与原来的整表 UPDATE 完全一致：
 * 只有「待确认 × 已完成 × 有邀请人 × 满 3 天」的订单推进到发放中，其余一律不动，超过一批也要全部推进。
 */
class CommissionAutoCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // admin_setting() 的缓存固定走 redis store，测试里换成 array
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
    }

    private function order(array $attrs, int $updatedAt): int
    {
        $order = Order::create(array_merge([
            'user_id' => 1, 'plan_id' => 1, 'period' => 'month_price',
            'trade_no' => Helper::generateOrderNo(), 'total_amount' => 1000, 'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_COMPLETED, 'invite_user_id' => 2, 'commission_status' => 0,
        ], $attrs));
        // Eloquent 会把 updated_at 写成现在，这里直接改表模拟订单年龄
        DB::table('v2_order')->where('id', $order->id)->update(['updated_at' => $updatedAt]);
        return $order->id;
    }

    #[Test]
    public function only_completed_invited_orders_older_than_three_days_are_promoted(): void
    {
        $old = time() - 4 * 86400;
        $fresh = time() - 2 * 86400;
        $eligible = $this->order([], $old);
        $tooFresh = $this->order([], $fresh);
        $noInviter = $this->order(['invite_user_id' => null], $old);
        $cancelled = $this->order(['status' => Order::STATUS_CANCELLED], $old);
        $pending = $this->order(['status' => Order::STATUS_PENDING], $old);
        $alreadyPaid = $this->order(['commission_status' => 2], $old);
        $invalid = $this->order(['commission_status' => 3], $old);

        app(CheckCommission::class)->autoCheck();

        $status = fn (int $id) => (int) DB::table('v2_order')->where('id', $id)->value('commission_status');
        $this->assertSame(1, $status($eligible));
        $this->assertSame(0, $status($tooFresh));
        $this->assertSame(0, $status($noInviter));
        $this->assertSame(0, $status($cancelled));
        $this->assertSame(0, $status($pending));
        $this->assertSame(2, $status($alreadyPaid));
        $this->assertSame(3, $status($invalid));
    }

    #[Test]
    public function more_than_one_chunk_is_promoted_in_a_single_run(): void
    {
        $old = time() - 4 * 86400;
        $count = CheckCommission::AUTO_CHECK_CHUNK * 2 + 7;
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'user_id' => 1, 'plan_id' => 1, 'period' => 'month_price', 'trade_no' => Helper::generateOrderNo() . $i,
                'total_amount' => 1000, 'type' => Order::TYPE_NEW_PURCHASE, 'status' => Order::STATUS_COMPLETED,
                'invite_user_id' => 2, 'commission_status' => 0, 'created_at' => $old, 'updated_at' => $old,
            ];
        }
        foreach (array_chunk($rows, 100) as $slice) {
            DB::table('v2_order')->insert($slice);
        }

        app(CheckCommission::class)->autoCheck();

        $this->assertSame($count, DB::table('v2_order')->where('commission_status', 1)->count());
        $this->assertSame(0, DB::table('v2_order')->where('commission_status', 0)->count());
    }

    #[Test]
    public function disabled_switch_changes_nothing(): void
    {
        $id = $this->order([], time() - 4 * 86400);
        // 与 InviteGrowthTest 同样的做法：用内存桩替掉 Setting，避免 save() 走 Redis 缓存
        $stub = new class extends \App\Support\Setting {
            public array $store = ['commission_auto_check_enable' => 0];
            public function __construct() {}
            public function get(string $key, mixed $default = null): mixed { return $this->store[strtolower($key)] ?? $default; }
            public function save(array $settings): bool { foreach ($settings as $k => $v) { $this->store[strtolower($k)] = $v; } return true; }
            public function set(string $key, mixed $value = null): bool { return $this->save([$key => $value]); }
        };
        $this->app->instance(\App\Support\Setting::class, $stub);

        app(CheckCommission::class)->autoCheck();

        $this->assertSame(0, (int) DB::table('v2_order')->where('id', $id)->value('commission_status'));
    }

    /**
     * 被邀请人在确认期内换套餐，原订单变成已折抵(4)：起点之后创建的照常推进，起点之前的存量不动；
     * 站点设置 commission_discounted_since=0 时存量也推进（整体补发的开关）。
     */
    #[Test]
    public function discounted_orders_follow_the_cutoff(): void
    {
        $old = time() - 4 * 86400;
        $since = Order::COMMISSION_DISCOUNTED_SINCE;
        $after = $this->order(['status' => Order::STATUS_DISCOUNTED, 'created_at' => $since + 60], $old);
        $before = $this->order(['status' => Order::STATUS_DISCOUNTED, 'created_at' => $since - 60], $old);
        $afterButFresh = $this->order(['status' => Order::STATUS_DISCOUNTED, 'created_at' => $since + 60], time() - 2 * 86400);

        app(CheckCommission::class)->autoCheck();

        $status = fn (int $id) => (int) DB::table('v2_order')->where('id', $id)->value('commission_status');
        $this->assertSame(1, $status($after));
        $this->assertSame(0, $status($before));
        $this->assertSame(0, $status($afterButFresh));

        $this->app->instance(\App\Support\Setting::class, new class extends \App\Support\Setting {
            public function __construct() {}
            public function get(string $key, mixed $default = null): mixed { return strtolower($key) === 'commission_discounted_since' ? 0 : $default; }
        });
        app(CheckCommission::class)->autoCheck();

        $this->assertSame(1, $status($before));
    }

    #[Test]
    public function promoted_discounted_orders_are_paid_and_pre_cutoff_ones_are_skipped(): void
    {
        Queue::fake();
        $user = fn () => User::create([
            'email' => Str::random(10) . '@example.test', 'password' => 'x', 'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'balance' => 0, 'commission_balance' => 0, 'transfer_enable' => 0, 'u' => 0, 'd' => 0,
        ]);
        $inviter = $user();
        $buyer = $user();
        $attrs = ['user_id' => $buyer->id, 'invite_user_id' => $inviter->id, 'status' => Order::STATUS_DISCOUNTED,
            'commission_status' => 1, 'commission_balance' => 300];
        $paid = $this->order($attrs + ['created_at' => Order::COMMISSION_DISCOUNTED_SINCE + 60], time() - 86400);
        $skipped = $this->order($attrs + ['created_at' => Order::COMMISSION_DISCOUNTED_SINCE - 60], time() - 86400);

        app(CheckCommission::class)->autoPayCommission();

        $this->assertSame(2, (int) DB::table('v2_order')->where('id', $paid)->value('commission_status'));
        $this->assertSame(1, (int) DB::table('v2_order')->where('id', $skipped)->value('commission_status'));
        $this->assertSame(300, (int) $inviter->fresh()->commission_balance);
        $this->assertSame(1, CommissionLog::where('invite_user_id', $inviter->id)->count());
    }

    #[Test]
    public function pending_commission_on_the_invite_page_counts_post_cutoff_discounted_orders(): void
    {
        $base = ['invite_user_id' => 7, 'commission_status' => 0, 'commission_balance' => 100];
        $this->order($base, time());
        $this->order($base + ['status' => Order::STATUS_DISCOUNTED, 'created_at' => Order::COMMISSION_DISCOUNTED_SINCE + 60], time());
        $this->order($base + ['status' => Order::STATUS_DISCOUNTED, 'created_at' => Order::COMMISSION_DISCOUNTED_SINCE - 60], time());
        $this->order($base + ['status' => Order::STATUS_CANCELLED], time());

        $this->assertSame(200, (int) Order::commissionSettleable()->where('invite_user_id', 7)->where('commission_status', 0)->sum('commission_balance'));
    }

    #[Test]
    public function the_scan_index_exists_after_migration(): void
    {
        $this->assertTrue(Schema::hasIndex('v2_order', 'idx_order_commission_scan'));
    }
}
