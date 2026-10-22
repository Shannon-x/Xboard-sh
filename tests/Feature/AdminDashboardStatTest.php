<?php

namespace Tests\Feature;

use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\Ticket;
use App\Models\User;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 管理后台新仪表盘的统计口径：getDashboard（今日 / 本月收入与同期对比、用户、订阅、节点、待办）
 * 与 getIncome（按天收入趋势 + 等长上一段对比）。每个数字都按手工构造的订单逐笔核对。
 */
class AdminDashboardStatTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        // 周三下午两点：今天过了一半，本月过了一半，正好检验「同时段」切分
        $this->travelTo(Carbon::parse('2026-10-15 14:00:00', 'Asia/Shanghai'));
        $group = new ServerGroup();
        $group->name = '基础';
        $group->save();
        $this->plan = Plan::create([
            'name' => '标准 300G', 'group_id' => $group->id, 'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 300, 'device_limit' => 3, 'speed_limit' => null, 'reset_traffic_method' => 1,
            'prices' => ['monthly' => 29.9],
        ]);
    }

    private function at(string $time): int
    {
        return Carbon::parse($time, 'Asia/Shanghai')->getTimestamp();
    }

    private function adminPath(string $path): string
    {
        return '/api/v2/' . admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))) . $path;
    }

    private function user(array $attributes = []): User
    {
        return User::create($attributes + [
            'email' => Str::random(10) . '@example.test', 'password' => 'x', 'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'plan_id' => null, 'group_id' => null, 'balance' => 0, 'transfer_enable' => 0, 'u' => 0, 'd' => 0,
            'created_at' => $this->at('2026-01-01 00:00:00'),
        ]);
    }

    private function order(User $user, int $amount, string $paidAt, array $attributes = []): Order
    {
        return Order::create($attributes + [
            'user_id' => $user->id, 'plan_id' => $this->plan->id, 'period' => 'monthly', 'type' => Order::TYPE_NEW_PURCHASE,
            'trade_no' => Helper::generateOrderNo(), 'total_amount' => $amount, 'status' => Order::STATUS_COMPLETED,
            'paid_at' => $this->at($paidAt),
        ]);
    }

    private function admin(): User
    {
        $admin = $this->user(['is_admin' => 1]);
        Sanctum::actingAs($admin, ['admin']);
        return $admin;
    }

    public function test_income_windows_compare_like_for_like_and_keep_zero_orders_apart(): void
    {
        $buyer = $this->user();
        $this->order($buyer, 3000, '2026-10-15 10:00:00');                                          // 今天
        $this->order($buyer, 0, '2026-10-15 11:00:00');                                             // 今天，0 元单
        $this->order($buyer, 2000, '2026-10-14 09:00:00', ['type' => Order::TYPE_RENEWAL]);        // 昨天、早于同时段
        $this->order($buyer, 5000, '2026-10-14 20:00:00');                                          // 昨天、晚于同时段
        $this->order($buyer, 7000, '2026-09-10 12:00:00');                                          // 上月同期内
        $this->order($buyer, 1000, '2026-09-20 12:00:00');                                          // 上月、同期之后
        $this->order($buyer, 9999, '2026-10-15 09:00:00', ['status' => Order::STATUS_CANCELLED]);   // 取消单不算
        Order::create([
            'user_id' => $buyer->id, 'plan_id' => $this->plan->id, 'period' => 'monthly', 'type' => Order::TYPE_NEW_PURCHASE,
            'trade_no' => Helper::generateOrderNo(), 'total_amount' => 8888, 'status' => Order::STATUS_PENDING,
        ]);                                                                                          // 待支付不算
        CommissionLog::create(['invite_user_id' => $buyer->id, 'user_id' => $buyer->id, 'trade_no' => 'C1', 'order_amount' => 3000, 'get_amount' => 300, 'created_at' => $this->at('2026-10-15 10:00:00'), 'updated_at' => $this->at('2026-10-15 10:00:00')]);
        CommissionLog::create(['invite_user_id' => $buyer->id, 'user_id' => $buyer->id, 'trade_no' => 'C2', 'order_amount' => 7000, 'get_amount' => 700, 'created_at' => $this->at('2026-09-10 12:00:00'), 'updated_at' => $this->at('2026-09-10 12:00:00')]);

        $this->admin();
        $income = $this->getJson($this->adminPath('/stat/getDashboard'))->assertOk()->json('data.income');

        $this->assertSame(['amount' => 3000, 'paid_count' => 1, 'zero_count' => 1], $income['today']);
        $this->assertSame(['amount' => 2000, 'paid_count' => 1, 'zero_count' => 0], $income['yesterday_same_time']);
        $this->assertSame(['amount' => 7000, 'paid_count' => 2, 'zero_count' => 0], $income['yesterday']);
        $this->assertSame(['amount' => 10000, 'paid_count' => 3, 'zero_count' => 1], $income['month']);
        $this->assertSame(['amount' => 7000, 'paid_count' => 1, 'zero_count' => 0], $income['last_month_same_time']);
        $this->assertSame(['amount' => 8000, 'paid_count' => 2, 'zero_count' => 0], $income['last_month']);
        $this->assertSame([['type' => Order::TYPE_NEW_PURCHASE, 'label' => '新购', 'count' => 1, 'amount' => 3000]], $income['today_by_type']);
        $this->assertSame(['month' => 300, 'last_month_same_time' => 700, 'last_month' => 700], $income['commission']);
    }

    public function test_users_subscriptions_and_todo_counts(): void
    {
        $now = now()->getTimestamp();
        $sub = ['plan_id' => $this->plan->id, 'group_id' => $this->plan->group_id];
        $this->user($sub + ['expired_at' => $this->at('2026-10-15 20:00:00'), 'created_at' => $this->at('2026-10-15 09:00:00'), 't' => $now - 60, 'online_count' => 2]);
        $this->user($sub + ['expired_at' => $this->at('2026-10-18 12:00:00'), 'auto_renew' => 1, 'created_at' => $this->at('2026-10-14 08:00:00')]);
        $this->user($sub + ['expired_at' => null, 'created_at' => $this->at('2026-10-14 18:00:00')]);                 // 长期有效
        $this->user($sub + ['expired_at' => $this->at('2026-10-13 12:00:00'), 'created_at' => $this->at('2026-09-05 08:00:00'), 't' => $now - 3600]); // 两天前到期没续
        $this->user($sub + ['expired_at' => $this->at('2026-12-01 00:00:00'), 'banned' => 1]);                        // 封禁不算有效
        $this->user(['expired_at' => $this->at('2026-12-01 00:00:00')]);                                              // 没套餐不算有效
        $admin = $this->admin();

        $ticketUser = $this->user();
        Ticket::create(['user_id' => $ticketUser->id, 'subject' => 'a', 'level' => 0, 'status' => Ticket::STATUS_OPENING, 'reply_status' => Ticket::REPLY_STATUS_PENDING]);
        Ticket::create(['user_id' => $ticketUser->id, 'subject' => 'b', 'level' => 0, 'status' => Ticket::STATUS_OPENING, 'reply_status' => Ticket::REPLY_STATUS_REPLIED]);
        Ticket::create(['user_id' => $ticketUser->id, 'subject' => 'c', 'level' => 0, 'status' => Ticket::STATUS_CLOSED, 'reply_status' => Ticket::REPLY_STATUS_PENDING]);

        $data = $this->getJson($this->adminPath('/stat/getDashboard'))->assertOk()->json('data');

        $users = $data['users'];
        $this->assertSame(User::count(), $users['total']);
        $this->assertSame(1, $users['new_today']);
        $this->assertSame(1, $users['new_yesterday_same_time']);   // 昨天 08:00 的算，18:00 的不算
        $this->assertSame(2, $users['new_yesterday']);
        $this->assertSame(3, $users['new_month']);
        $this->assertSame(1, $users['new_last_month_same_time']);
        $this->assertSame(1, $users['online']);
        $this->assertSame(2, $users['online_devices']);

        $subs = $data['subscriptions'];
        $this->assertSame(3, $subs['active']);
        $this->assertSame(1, $subs['auto_renew']);
        $this->assertSame(1, $subs['expiring_today']);
        $this->assertSame(2, $subs['expiring_7d']);
        $this->assertSame(1, $subs['lapsed_7d']);
        $this->assertSame([['plan_id' => $this->plan->id, 'name' => '标准 300G', 'total' => 3]], $subs['by_plan']);

        $this->assertSame(1, $data['todo']['tickets_waiting']);
        $this->assertSame(2, $data['todo']['tickets_open']);
        $this->assertNotNull($admin);
    }

    public function test_child_nodes_follow_parent_heartbeat(): void
    {
        $make = function (string $name, ?int $parentId = null, bool $show = true): Server {
            $server = new Server();
            $server->type = 'trojan';
            $server->name = $name;
            $server->rate = 1;
            $server->host = 'example.test';
            $server->port = '443';
            $server->server_port = 443;
            $server->group_ids = [];
            $server->show = $show;
            $server->parent_id = $parentId;
            $server->save();
            return $server;
        };
        $parent = $make('香港 01');
        $make('香港 01 备用', $parent->id);                 // 子节点：看父节点的上报
        $make('日本 01');                                  // 从没上报
        $stale = $make('美国 01');
        $make('隐藏节点', null, false);
        $now = now()->getTimestamp();
        Cache::put(CacheKey::get('SERVER_TROJAN_LAST_CHECK_AT', $parent->id), $now - 30, 3600);
        Cache::put(CacheKey::get('SERVER_TROJAN_LAST_CHECK_AT', $stale->id), $now - 7200, 3600);

        $this->admin();
        $nodes = $this->getJson($this->adminPath('/stat/getDashboard'))->assertOk()->json('data.nodes');

        $this->assertSame(5, $nodes['total']);
        $this->assertSame(4, $nodes['shown']);
        $this->assertSame(1, $nodes['hidden']);
        $this->assertSame(2, $nodes['online']);
        $this->assertSame(2, $nodes['offline']);
        // 最近上报过的排前面，从没上报的排最后
        $this->assertSame(['美国 01', '日本 01'], array_column($nodes['offline_list'], 'name'));
        $this->assertNull($nodes['offline_list'][1]['last_check_at']);
    }

    public function test_income_trend_includes_today_and_compares_same_elapsed_time(): void
    {
        $a = $this->user();
        $b = $this->user();
        $this->order($a, 3000, '2026-10-15 10:00:00');
        $this->order($a, 0, '2026-10-15 11:00:00');
        $this->order($b, 2000, '2026-10-14 09:00:00', ['type' => Order::TYPE_RENEWAL]);
        $this->order($b, 5000, '2026-10-14 20:00:00', ['type' => Order::TYPE_RENEWAL]);
        $this->order($a, 1500, '2026-10-13 10:00:00');   // 上一段，早于「同一时刻」
        $this->order($a, 2500, '2026-10-13 16:00:00');   // 上一段最后一天、晚于同一时刻：不进对比
        $this->order($a, 4000, '2026-10-11 10:00:00');   // 上一段之前
        CommissionLog::create(['invite_user_id' => $a->id, 'user_id' => $b->id, 'trade_no' => 'C1', 'order_amount' => 2000, 'get_amount' => 200, 'created_at' => $this->at('2026-10-14 09:00:00'), 'updated_at' => $this->at('2026-10-14 09:00:00')]);

        $this->admin();
        $data = $this->getJson($this->adminPath('/stat/getIncome') . '?start_date=2026-10-14&end_date=2026-10-15')->assertOk()->json('data');

        $this->assertSame('2026-10-14', $data['range']['start_date']);
        $this->assertSame(2, $data['range']['days']);
        $this->assertSame('2026-10-12', $data['range']['previous_start_date']);
        $this->assertSame('2026-10-13', $data['range']['previous_end_date']);
        $this->assertSame($this->at('2026-10-13 14:00:01'), $data['range']['previous_end_at']);
        $this->assertTrue($data['range']['includes_today']);

        $this->assertSame(10000, $data['summary']['amount']);
        $this->assertSame(3, $data['summary']['paid_count']);
        $this->assertSame(1, $data['summary']['zero_count']);
        $this->assertSame(2, $data['summary']['paying_users']);
        $this->assertSame(3333, $data['summary']['avg_amount']);
        $this->assertSame(200, $data['summary']['commission_total']);
        $this->assertSame(1500, $data['previous']['amount']);
        $this->assertSame(1, $data['previous']['paid_count']);

        $this->assertSame(['2026-10-14', '2026-10-15'], array_column($data['list'], 'date'));
        $this->assertSame([7000, 3000], array_column($data['list'], 'paid_total'));
        $this->assertSame([2, 1], array_column($data['list'], 'paid_count'));
        $this->assertSame([0, 1], array_column($data['list'], 'zero_count'));
        $this->assertSame([false, true], array_column($data['list'], 'partial'));

        $byType = array_column($data['by_type'], null, 'type');
        $this->assertSame(3000, $byType[Order::TYPE_NEW_PURCHASE]['amount']);
        $this->assertSame(1, $byType[Order::TYPE_NEW_PURCHASE]['zero_count']);
        $this->assertSame(7000, $byType[Order::TYPE_RENEWAL]['amount']);
        $this->assertSame(2, $byType[Order::TYPE_RENEWAL]['count']);
    }

    public function test_income_trend_range_is_normalised(): void
    {
        $this->admin();
        // 不传：默认最近 30 天含今天
        $range = $this->getJson($this->adminPath('/stat/getIncome'))->assertOk()->json('data.range');
        $this->assertSame('2026-09-16', $range['start_date']);
        $this->assertSame('2026-10-15', $range['end_date']);
        $this->assertSame(30, $range['days']);
        // 起止颠倒会自动对调；超过 400 天截成 400 天
        $range = $this->getJson($this->adminPath('/stat/getIncome') . '?start_date=2026-10-15&end_date=2026-10-01')->assertOk()->json('data.range');
        $this->assertSame('2026-10-01', $range['start_date']);
        $range = $this->getJson($this->adminPath('/stat/getIncome') . '?start_date=2020-01-01&end_date=2026-10-15')->assertOk()->json('data.range');
        $this->assertSame(400, $range['days']);
        $this->getJson($this->adminPath('/stat/getIncome') . '?start_date=yesterday')->assertStatus(422);
    }

    public function test_regular_users_cannot_read_dashboard(): void
    {
        Sanctum::actingAs($this->user());
        $this->getJson($this->adminPath('/stat/getDashboard'))->assertStatus(403);
        $this->getJson($this->adminPath('/stat/getIncome'))->assertStatus(403);
    }
}
