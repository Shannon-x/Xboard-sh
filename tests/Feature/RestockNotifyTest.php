<?php

namespace Tests\Feature;

use App\Jobs\SendEmailJob;
use App\Models\Plan;
use App\Models\PlanRestockNotify;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 售罄套餐的到货提醒与套餐列表的有货 / 售罄。
 *
 *   - 只有在售、此刻满员的套餐能登记；登录用户用账户邮箱，游客留邮箱，一个邮箱最多同时等 20 款。
 *   - 定时任务发现有位置（本套餐放位，或同一 family 开了新一车）就每个登记发一封，之后不再发；重新登记才再等。
 *   - 套餐列表：include_sold_out=1 才列出满员的，带 stock；席位数（capacity_limit）恒为 null；
 *     目录前缀标签从 tags 拿掉、解析成 catalog。不带参数与原来相同。
 */
class RestockNotifyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['v2board.email_verify_nudge_enable' => 0, 'v2board.app_url' => 'https://www.example.test']);
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        app()->forgetScopedInstances();
        Bus::fake([SendEmailJob::class]);
    }

    private function plan(array $attributes = []): Plan
    {
        return Plan::create($attributes + [
            'name' => '美国 AT&T 静态家宽', 'group_id' => 17, 'capacity_limit' => 1,
            'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => null,
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY, 'prices' => ['monthly' => 30],
        ]);
    }

    private function user(array $attributes = []): User
    {
        return User::create($attributes + [
            'email' => Str::random(12) . '@example.test', 'password' => 'test',
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'balance' => 0, 'transfer_enable' => 0, 'expired_at' => 0,
        ]);
    }

    /** 占一个席位：套餐上、未到期 */
    private function occupy(Plan $plan): User
    {
        return $this->user(['plan_id' => $plan->id, 'group_id' => $plan->group_id, 'expired_at' => time() + 30 * 86400]);
    }

    private function mails(): array
    {
        $mails = [];
        Bus::assertDispatched(SendEmailJob::class, function (SendEmailJob $job) use (&$mails) {
            $mails[] = (fn () => $this->params)->call($job);
            return true;
        });
        return $mails;
    }

    // ───────────────────────── 登记 ─────────────────────────

    public function test_signed_in_user_subscribes_with_the_account_email_and_can_cancel(): void
    {
        $plan = $this->plan();
        $this->occupy($plan);
        $user = $this->user(['email' => 'Alice@Example.test']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/user/plan/restock-notify', ['plan_id' => $plan->id])
            ->assertOk()->assertJsonPath('data.subscribed', true);
        $row = PlanRestockNotify::sole();
        $this->assertSame([$plan->id, $user->id, 'alice@example.test', null], [$row->plan_id, $row->user_id, $row->email, $row->notified_at]);

        $list = $this->getJson('/api/v1/user/plan/fetch?include_sold_out=1')->assertOk()->json('data');
        $this->assertSame([[$plan->id, 'sold_out', true]], array_map(fn ($p) => [$p['id'], $p['stock'], $p['restock_subscribed']], $list));

        // 重复登记还是一行
        $this->postJson('/api/v1/user/plan/restock-notify', ['plan_id' => $plan->id])->assertOk();
        $this->assertSame(1, PlanRestockNotify::count());

        $this->postJson('/api/v1/user/plan/restock-notify', ['plan_id' => $plan->id, 'cancel' => 1])
            ->assertOk()->assertJsonPath('data.subscribed', false);
        $this->assertSame(0, PlanRestockNotify::count());
        $this->assertFalse($this->getJson('/api/v1/user/plan/fetch?include_sold_out=1')->json('data.0.restock_subscribed'));
    }

    public function test_guest_subscribes_by_email_and_registered_emails_are_linked(): void
    {
        $plan = $this->plan();
        $this->occupy($plan);
        $member = $this->user(['email' => 'bob@example.test']);

        $this->postJson('/api/v1/guest/plan/restock-notify', ['plan_id' => $plan->id, 'email' => ' BOB@example.test '])
            ->assertOk()->assertJsonPath('data.subscribed', true);
        $this->postJson('/api/v1/guest/plan/restock-notify', ['plan_id' => $plan->id, 'email' => 'new@example.test'])
            ->assertOk()->assertJsonPath('data.subscribed', true);
        $this->assertSame(
            ['bob@example.test' => $member->id, 'new@example.test' => null],
            PlanRestockNotify::orderBy('email')->pluck('user_id', 'email')->all()
        );

        $this->postJson('/api/v1/guest/plan/restock-notify', ['plan_id' => $plan->id, 'email' => 'not-an-email'])->assertStatus(422);
        $this->postJson('/api/v1/guest/plan/restock-notify', ['plan_id' => 999999, 'email' => 'x@example.test'])->assertStatus(400);
    }

    public function test_only_sold_out_plans_on_sale_can_be_watched(): void
    {
        $open = $this->plan(['capacity_limit' => 5]);
        $unlimited = $this->plan(['capacity_limit' => null]);
        $hidden = $this->plan(['show' => false]);
        $this->occupy($hidden);
        $offSale = $this->plan(['sell' => false]);
        $this->occupy($offSale);

        foreach ([$open, $unlimited] as $plan) {
            $this->postJson('/api/v1/guest/plan/restock-notify', ['plan_id' => $plan->id, 'email' => 'x@example.test'])
                ->assertStatus(400)->assertJsonPath('message', '这款套餐现在有位置，可以直接购买');
        }
        foreach ([$hidden, $offSale] as $plan) {
            $this->postJson('/api/v1/guest/plan/restock-notify', ['plan_id' => $plan->id, 'email' => 'x@example.test'])
                ->assertStatus(400);
        }
        $this->assertSame(0, PlanRestockNotify::count());
    }

    public function test_one_email_can_wait_for_at_most_twenty_plans(): void
    {
        $service = app(\App\Services\RestockNotifier::class);
        for ($i = 0; $i < 20; $i++) {
            $plan = $this->plan(['capacity_limit' => 0]);
            $service->subscribe($plan, 'busy@example.test');
        }
        // 已登记的那款再登记一次不受上限影响（只是重新等待）
        $service->subscribe($plan, 'busy@example.test');

        $this->postJson('/api/v1/guest/plan/restock-notify', ['plan_id' => $this->plan(['capacity_limit' => 0])->id, 'email' => 'busy@example.test'])
            ->assertStatus(400)->assertJsonPath('message', '登记的到货提醒太多了，请先取消一些');
        $this->assertSame(20, PlanRestockNotify::count());
    }

    // ───────────────────────── 发信 ─────────────────────────

    public function test_a_freed_seat_sends_one_mail_per_subscription_and_never_twice(): void
    {
        $plan = $this->plan();
        $holder = $this->occupy($plan);
        $notifier = app(\App\Services\RestockNotifier::class);
        $notifier->subscribe($plan, 'a@example.test');
        $notifier->subscribe($plan, 'b@example.test');

        // 还满着：不发
        $this->artisan('plan:restock-notify')->assertSuccessful();
        Bus::assertNotDispatched(SendEmailJob::class);

        // 演练：只数不发、不标记
        $holder->update(['expired_at' => time() - 60]);
        $this->assertSame(['plans' => 1, 'sent' => 2], $notifier->run(true));
        Bus::assertNotDispatched(SendEmailJob::class);
        $this->assertSame(2, PlanRestockNotify::whereNull('notified_at')->count());

        $this->artisan('plan:restock-notify')->assertSuccessful();
        Bus::assertDispatchedTimes(SendEmailJob::class, 2);
        $mails = $this->mails();
        $this->assertEqualsCanonicalizing(['a@example.test', 'b@example.test'], array_column($mails, 'email'));
        $content = $mails[0]['template_value']['content'];
        $this->assertSame('到货提醒：美国 AT&T 静态家宽 有位置了 - ' . admin_setting('app_name', 'XBoard'), $mails[0]['subject']);
        $this->assertStringContainsString('https://www.example.test/plans?plan=' . $plan->id, $content);
        $this->assertStringContainsString('这封提醒只发一次', $content);
        $this->assertDoesNotMatchRegularExpression('/剩\s*\d|\d+\s*个?(席|位)/u', $content, '只说有位，不写席位数');
        $this->assertSame(0, PlanRestockNotify::whereNull('notified_at')->count());

        // 再跑、又满又放位：已经提醒过的不再发
        $this->artisan('plan:restock-notify')->assertSuccessful();
        $other = $this->occupy($plan);
        $other->update(['expired_at' => time() - 60]);
        $this->artisan('plan:restock-notify')->assertSuccessful();
        Bus::assertDispatchedTimes(SendEmailJob::class, 2);

        // 重新登记（又满了才能登记）才会再等
        $this->occupy($plan);
        $notifier->subscribe($plan, 'a@example.test');
        $this->assertSame(1, PlanRestockNotify::whereNull('notified_at')->count());
        $this->assertSame(2, PlanRestockNotify::count(), '同一套餐同一邮箱仍是一行');
    }

    public function test_a_new_batch_of_the_same_family_counts_as_back_in_stock(): void
    {
        $tags = ['family:美国 AT&T 静态家宽 · 基础版', 'batch:第 1 车', '热门'];
        $first = $this->plan(['tags' => $tags]);
        $this->occupy($first);
        $unrelated = $this->plan(['tags' => ['family:日本 KDDI'], 'capacity_limit' => 5]);
        app(\App\Services\RestockNotifier::class)->subscribe($first, 'c@example.test');

        // 别的产品有位不算
        $this->artisan('plan:restock-notify')->assertSuccessful();
        Bus::assertNotDispatched(SendEmailJob::class);

        // 同一产品开了第 2 车：链接指向有位的那一车，标题用产品名
        $second = $this->plan(['tags' => ['family：美国 AT&T 静态家宽 · 基础版', 'batch:第 2 车'], 'capacity_limit' => 3]);
        $this->artisan('plan:restock-notify')->assertSuccessful();
        Bus::assertDispatchedTimes(SendEmailJob::class, 1);
        $mail = $this->mails()[0];
        $this->assertStringContainsString('美国 AT&T 静态家宽 · 基础版', $mail['subject']);
        $this->assertStringContainsString('/plans?plan=' . $second->id, $mail['template_value']['content']);
        $this->assertStringNotContainsString('/plans?plan=' . $unrelated->id, $mail['template_value']['content']);
        $this->assertNotNull(PlanRestockNotify::sole()->notified_at);
    }

    public function test_suppressed_mailboxes_are_skipped_but_the_subscription_counts_as_handled(): void
    {
        $plan = $this->plan();
        $holder = $this->occupy($plan);
        $bounced = $this->user(['email' => 'bounced@example.test', 'mail_suppressed_at' => time()]);
        app(\App\Services\RestockNotifier::class)->subscribe($plan, 'bounced@example.test');
        $this->assertSame($bounced->id, PlanRestockNotify::sole()->user_id);

        $holder->update(['expired_at' => time() - 60]);
        $this->artisan('plan:restock-notify')->assertSuccessful();
        Bus::assertNotDispatched(SendEmailJob::class);
        $this->assertNotNull(PlanRestockNotify::sole()->notified_at);
    }

    public function test_stale_subscriptions_are_pruned(): void
    {
        $plan = $this->plan();
        $this->occupy($plan);
        app(\App\Services\RestockNotifier::class)->subscribe($plan, 'old@example.test');
        PlanRestockNotify::query()->update(['created_at' => time() - 181 * 86400]);

        $this->artisan('plan:restock-notify')->assertSuccessful();
        $this->assertSame(0, PlanRestockNotify::count());
    }

    // ───────────────────────── 套餐列表 ─────────────────────────

    public function test_plan_list_shows_stock_only_and_parses_catalog_tags(): void
    {
        $soldOut = $this->plan(['sort' => 1, 'tags' => ['carrier:美国 AT&T', 'segment:静态IP', 'family:AT&T 静态', 'batch:第 1 车', '限量']]);
        $this->occupy($soldOut);
        $open = $this->plan(['sort' => 2, 'capacity_limit' => 10, 'tags' => ['热门', '推荐']]);
        $unlimited = $this->plan(['sort' => 3, 'capacity_limit' => null, 'tags' => null]);

        foreach (['/api/v1/guest/plan/fetch', '/api/v1/user/plan/fetch'] as $path) {
            if (str_contains($path, '/user/')) {
                Sanctum::actingAs($this->user());
            }
            // 不带参数：与原来相同，满员的不列出
            $default = $this->getJson($path)->assertOk()->json('data');
            $this->assertSame([$open->id, $unlimited->id], array_column($default, 'id'), $path);

            $list = $this->getJson($path . '?include_sold_out=1')->assertOk()->json('data');
            $this->assertSame([$soldOut->id, $open->id, $unlimited->id], array_column($list, 'id'), $path);
            $this->assertSame(['sold_out', 'available', 'available'], array_column($list, 'stock'), $path);
            $this->assertSame([null, null, null], array_column($list, 'capacity_limit'), '席位数不下发');

            $this->assertSame(['限量'], $list[0]['tags']);
            $this->assertSame([
                'carrier' => '美国 AT&T', 'segment' => 'static', 'family' => 'AT&T 静态',
                'family_name' => 'AT&T 静态', 'batch' => '第 1 车',
            ], $list[0]['catalog']);
            // 没有目录标签的套餐 tags 原样、catalog 为 null
            $this->assertSame(['热门', '推荐'], $list[1]['tags']);
            $this->assertNull($list[1]['catalog']);
            $this->assertNull($list[2]['tags']);

            if (str_contains($path, '/guest/')) {
                $this->assertArrayNotHasKey('restock_subscribed', $list[0], '游客没有登记状态');
            } else {
                $this->assertFalse($list[0]['restock_subscribed']);
            }
        }

        // 单查同样只给有货 / 售罄；满员套餐对新购仍查不到（与原来相同），占着位的老用户续费照常看得到
        $single = $this->getJson('/api/v1/user/plan/fetch?id=' . $open->id)->assertOk()->json('data');
        $this->assertSame(['available', null], [$single['stock'], $single['capacity_limit']]);
        $this->getJson('/api/v1/user/plan/fetch?id=' . $soldOut->id)->assertStatus(400);
        Sanctum::actingAs(User::where('plan_id', $soldOut->id)->sole());
        $single = $this->getJson('/api/v1/user/plan/fetch?id=' . $soldOut->id)->assertOk()->json('data');
        $this->assertSame(['sold_out', null], [$single['stock'], $single['capacity_limit']]);
    }
}
