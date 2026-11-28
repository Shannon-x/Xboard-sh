<?php

namespace Tests\Feature;

use App\Jobs\SendEmailJob;
use App\Jobs\SendTelegramJob;
use App\Models\Order;
use App\Models\PaymentVerification;
use App\Models\User;
use App\Services\EnforcedUserPurge;
use App\Services\PaymentAlertDigest;
use App\Services\PaymentEnforcement;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 付款异常自动封号的用户，封号满 180 天由系统自动删号，不再等管理员确认（2026-11-28）。
 * 删法与后台「删除用户」一致；删前把邮箱、单号写进核验记录，日报汇总删了谁、谁快到期。
 *
 * Setting 用内存桩替换（真实实现走 redis 缓存）。
 */
class EnforcedUserPurgeTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = 86400;

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

    private function order(User $user, array $attrs = []): Order
    {
        return Order::create($attrs + [
            'user_id' => $user->id, 'plan_id' => 1, 'period' => 'monthly', 'type' => Order::TYPE_NEW_PURCHASE,
            'trade_no' => (string) random_int(10 ** 15, 10 ** 16 - 1), 'total_amount' => 2080, 'status' => Order::STATUS_COMPLETED,
            'payment_id' => 1, 'callback_no' => 'GW' . Str::random(8), 'paid_at' => time() - 200 * self::DAY,
            'created_at' => time() - 200 * self::DAY,
        ]);
    }

    /** 一次已完成的自动处置（退款回查），处置时间 $enforcedAt */
    private function enforced(User $user, int $enforcedAt): PaymentVerification
    {
        $order = $this->order($user, ['created_at' => $enforcedAt - self::DAY]);
        return PaymentVerification::create([
            'subject_type' => 'order', 'subject_id' => $order->id, 'user_id' => $user->id, 'payment_id' => 1,
            'callback_no' => $order->callback_no, 'expected_amount' => 2080, 'verdict' => 'refunded',
            'checked_at' => $enforcedAt - self::DAY, 'refunded_at' => $enforcedAt,
            'enforce_snapshot' => ['banned' => 0, 'plan_id' => 57, 'expired_at' => time() + 30 * self::DAY, 'transfer_enable' => 0, 'balance' => 0],
        ]);
    }

    public function test_deletes_users_banned_for_180_days_and_keeps_a_trace(): void
    {
        $now = time();
        $due = $this->user(['banned' => 1, 'email' => 'due@example.test']);
        $dueRow = $this->enforced($due, $now - 181 * self::DAY);
        $tradeNo = PaymentEnforcement::tradeNoOf($dueRow);
        $invitee = $this->user(['invite_user_id' => $due->id]);

        $recent = $this->user(['banned' => 1]);
        $this->enforced($recent, $now - 10 * self::DAY);
        $restored = $this->user(['banned' => 0]);              // 误判已解封
        $this->enforced($restored, $now - 200 * self::DAY);
        $staff = $this->user(['banned' => 1, 'is_staff' => 1]);
        $this->enforced($staff, $now - 200 * self::DAY);
        $twice = $this->user(['banned' => 1]);                 // 以最近一次处置起算
        $this->enforced($twice, $now - 300 * self::DAY);
        $this->enforced($twice, $now - 20 * self::DAY);
        $repaid = $this->user(['banned' => 1]);                // 封号后又有付款：多半人工恢复过，不自动删
        $this->enforced($repaid, $now - 200 * self::DAY);
        $this->order($repaid, ['created_at' => $now - 30 * self::DAY]);

        $result = app(EnforcedUserPurge::class)->run($now);

        $this->assertSame([$due->id], $result['deleted']);
        $this->assertArrayHasKey($repaid->id, $result['skipped']);
        $this->assertStringContainsString('付款订单', $result['skipped'][$repaid->id]);
        $this->assertCount(1, $result['skipped'], '解封的、客服账号、未满期的都不在到期名单里');

        $this->assertNull(User::find($due->id));
        $this->assertSame(0, Order::where('user_id', $due->id)->count(), '与后台删除一致：订单随用户删');
        $this->assertNull($invitee->fresh()->invite_user_id);
        foreach ([$recent, $restored, $staff, $twice, $repaid] as $kept) {
            $this->assertNotNull(User::find($kept->id));
        }

        $dueRow->refresh();
        $this->assertSame($now, $dueRow->user_deleted_at);
        $this->assertSame(57, $dueRow->enforce_snapshot['plan_id'], '原处置快照原样保留');
        $this->assertSame('due@example.test', $dueRow->enforce_snapshot['deleted']['email']);
        $this->assertSame($tradeNo, $dueRow->enforce_snapshot['deleted']['trade_no']);
        $this->assertSame(1, $dueRow->enforce_snapshot['deleted']['paid_orders']);
        $this->assertSame($tradeNo, PaymentEnforcement::tradeNoOf($dueRow), '订单删了，单号从快照里取');

        $this->assertSame([], app(EnforcedUserPurge::class)->run($now)['deleted'], '再跑一次没有可删的');
    }

    public function test_dry_run_lists_but_deletes_nothing(): void
    {
        $due = $this->user(['banned' => 1]);
        $this->enforced($due, time() - 181 * self::DAY);

        $this->artisan('payment:purge-banned', ['--dry-run' => true])
            ->expectsOutputToContain('到期该删 1 个：#' . $due->id)
            ->assertSuccessful();
        $this->assertNotNull(User::find($due->id));
        $this->assertSame(0, PaymentVerification::whereNotNull('user_deleted_at')->count());
    }

    public function test_digest_reports_deletions_and_upcoming_ones_without_asking_to_delete(): void
    {
        $now = time();
        $gone = $this->user(['banned' => 1, 'email' => 'gone@example.test']);
        $goneRow = $this->enforced($gone, $now - 181 * self::DAY);
        $tradeNo = PaymentEnforcement::tradeNoOf($goneRow);
        app(EnforcedUserPurge::class)->run($now - 3600);

        $soon = $this->user(['banned' => 1, 'email' => 'soon@example.test']);
        $this->enforced($soon, $now - 177 * self::DAY);
        $stuck = $this->user(['banned' => 1, 'email' => 'stuck@example.test']);
        $this->enforced($stuck, $now - 200 * self::DAY);
        $this->order($stuck, ['created_at' => $now - 30 * self::DAY]);
        $far = $this->user(['banned' => 1, 'email' => 'far@example.test']);
        $this->enforced($far, $now - 2 * self::DAY);

        $digest = (new PaymentAlertDigest())->build($now - self::DAY, $now);
        $content = $digest['content'];

        $this->assertStringNotContainsString('待你确认删号', $content);
        $this->assertStringNotContainsString('执行删号', $content);
        $this->assertStringContainsString('【自动删号】1 个（封号满 180 天）', $content);
        $this->assertMatchesRegularExpression('/已删号：.*gone@example\.test（#' . $gone->id . '）/u', $content, '用户删了，邮箱从快照里取');
        $this->assertStringContainsString($tradeNo, $content, '订单删了也能看到单号');
        $this->assertStringContainsString('【封号待删】3 个，封号满 180 天系统自动删号', $content);
        $this->assertStringContainsString('7 天内到期 1 个', $content);
        $this->assertStringContainsString('soon@example.test', $content);
        $this->assertStringNotContainsString('far@example.test', $content, '远期的只计数');
        $this->assertStringContainsString('没能自动删的 1 个', $content);
        $this->assertStringContainsString('stuck@example.test', $content);
        $this->assertStringContainsString('封号后又有付款订单', $content);
        $this->assertSame(1, $digest['needs_action'], '只有删不掉的那个要人工看；far 的自动封号不算待办');
    }

    public function test_admin_delete_still_removes_user_and_related_data(): void
    {
        $target = $this->user();
        $this->order($target);
        $invitee = $this->user(['invite_user_id' => $target->id]);
        Sanctum::actingAs($this->user(['is_admin' => 1]), ['admin']);

        $path = '/api/v2/' . admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))) . '/user/destroy';
        $this->postJson($path, ['id' => $target->id])->assertOk()->assertJsonPath('data', true);

        $this->assertNull(User::find($target->id));
        $this->assertSame(0, Order::where('user_id', $target->id)->count());
        $this->assertNull($invitee->fresh()->invite_user_id);
    }
}
