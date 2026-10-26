<?php

namespace Tests\Feature;

use App\Jobs\SendBillingMailJob;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\Billing\BillingArchive;
use App\Services\Billing\BillingDocumentService;
use App\Services\PlanCapacity;
use App\Services\PlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 套餐席位：批量版 PlanCapacity 与下单 / 前台列表用的 PlanService::hasCapacity 同一口径；
 * 账单、到期、挽回、流量邮件里的「也可以看看」不推荐满员套餐，也不带席位数。
 */
class PlanCapacityTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1073741824;

    private int $groupId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['v2board.email_verify_nudge_enable' => 0]);
        Storage::fake('local');
        Http::preventStrayRequests();
        $group = new ServerGroup();
        $group->name = '基础';
        $group->save();
        $this->groupId = $group->id;
    }

    private function plan(string $name, int $monthly, ?int $capacity, array $attributes = []): Plan
    {
        return Plan::create($attributes + [
            'name' => $name, 'group_id' => $this->groupId, 'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => null, 'reset_traffic_method' => 1,
            'prices' => ['monthly' => $monthly], 'capacity_limit' => $capacity,
        ]);
    }

    private function user(Plan $plan, ?int $expiredAt): User
    {
        return User::create([
            'email' => Str::random(10) . '@example.test', 'password' => 'x', 'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'plan_id' => $plan->id, 'group_id' => $plan->group_id, 'balance' => 0,
            'transfer_enable' => 100 * self::GB, 'u' => 0, 'd' => 0, 'device_limit' => 2,
            'remind_expire' => 1, 'remind_traffic' => 0, 'expired_at' => $expiredAt,
        ]);
    }

    /** n 个占位用户：未到期的、不限期的都算，已到期的不算 */
    private function fill(Plan $plan, int $active, int $expired = 0): void
    {
        for ($i = 0; $i < $active; $i++) {
            $this->user($plan, $i % 2 ? null : time() + 86400);
        }
        for ($i = 0; $i < $expired; $i++) {
            $this->user($plan, time() - 86400);
        }
    }

    public function test_batch_remaining_matches_the_ordering_rule(): void
    {
        $unlimited = $this->plan('不限', 10, null);
        $zero = $this->plan('零席位', 10, 0);
        $room = $this->plan('还有位', 10, 3);
        $full = $this->plan('刚好满', 10, 2);
        $over = $this->plan('超卖', 10, 2);
        $this->fill($unlimited, 5);
        $this->fill($room, 2, 4);          // 到期的 4 个不占位
        $this->fill($full, 2, 1);
        $this->fill($over, 3);              // 线上 503/500 那种：续费不受席位限制，可能超过上限

        $plans = Plan::orderBy('id')->get();
        $remaining = PlanCapacity::remaining($plans);
        $this->assertSame([
            $unlimited->id => null, $zero->id => 0, $room->id => 1, $full->id => 0, $over->id => 0,
        ], $remaining);

        foreach ($plans as $plan) {
            $this->assertSame(
                (new PlanService($plan))->hasCapacity($plan),
                $remaining[$plan->id] !== 0,
                $plan->name . '：邮件推荐和下单校验必须同一个口径'
            );
        }
        $this->assertSame([$unlimited->id, $room->id], PlanCapacity::available($plans)->pluck('id')->all());
        $this->assertSame([], PlanCapacity::remaining([]));
    }

    public function test_alternatives_skip_full_plans_and_never_carry_seat_counts(): void
    {
        $kddi = $this->plan('日本 KDDI', 30, 2);
        $starlink = $this->plan('日本星链', 32, 2);   // 价位最接近，但满员
        $att = $this->plan('美国 AT&T', 45, 5);
        $this->plan('普通上网', 10, null);
        $this->plan('隐藏套餐', 31, null, ['show' => false]);
        $this->fill($kddi, 2);
        $this->fill($starlink, 3);
        $this->fill($att, 1);
        $user = $this->user($kddi, time() - 86400);

        $alternatives = app(BillingDocumentService::class)->alternatives($user, $kddi);
        $this->assertSame(['美国 AT&T', '普通上网'], array_column($alternatives, 'name'), '满员的、自己的、隐藏的都不推荐');
        foreach ($alternatives as $alt) {
            $this->assertSame(['name', 'price', 'details', 'url'], array_keys($alt), '推荐项不带席位数');
        }

        // 有人到期腾出位子，星链回到推荐里，按价位排第一
        User::where('plan_id', $starlink->id)->limit(2)->update(['expired_at' => time() - 60]);
        $alternatives = app(BillingDocumentService::class)->alternatives($user, $kddi);
        $this->assertSame(['日本星链', '美国 AT&T', '普通上网'], array_column($alternatives, 'name'));
    }

    public function test_configured_recommendations_drop_full_plans_and_fall_back_when_all_are_full(): void
    {
        $kddi = $this->plan('日本 KDDI', 30, null);
        $starlink = $this->plan('日本星链', 32, 1);
        $att = $this->plan('美国 AT&T', 50, 1);
        $this->plan('普通上网', 10, null);
        $this->fill($starlink, 1);
        $user = $this->user($kddi, time() - 86400);
        $service = app(BillingDocumentService::class);

        config(['v2board.billing_recommend_plan_ids' => "{$starlink->id},{$att->id}"]);
        $this->assertSame(['美国 AT&T'], array_column($service->alternatives($user, $kddi), 'name'), '指定的里面满员的跳过');

        $this->fill($att, 1);
        $this->assertSame(['普通上网'], array_column($service->alternatives($user, $kddi), 'name'), '指定的全满员：退回按价位挑');
    }

    public function test_winback_mail_does_not_recommend_a_full_plan(): void
    {
        $kddi = $this->plan('日本 KDDI', 30, 2);
        $starlink = $this->plan('日本星链', 32, 2);
        $this->plan('普通上网', 10, null);
        $this->fill($kddi, 2);
        $this->fill($starlink, 3);
        $user = $this->user($kddi, time() - 7 * 86400 - 3600);

        (new SendBillingMailJob(SendBillingMailJob::KIND_WINBACK, $user->id, '2', (int) $user->expired_at))
            ->handle(app(BillingDocumentService::class), app(BillingArchive::class));
        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $html = $messages->first()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('普通上网', $html);
        $this->assertStringNotContainsString('日本星链', $html);
        $this->assertStringNotContainsString('/plans?plan=' . $starlink->id . '"', $html);
    }
}
