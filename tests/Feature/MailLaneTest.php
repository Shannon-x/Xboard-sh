<?php

namespace Tests\Feature;

use App\Jobs\SendBillingMailJob;
use App\Jobs\SendEmailJob;
use App\Models\MailLog;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\Mail\DeliveryMonitor;
use App\Services\Mail\MailLane;
use App\Services\PlanCapacity;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 邮件三条通道：验证码 / 登录链接进即时通道且带有效期，收据进交易通道，定时批量进批量通道；
 * 过期的即时邮件不发、不计入用户失败；Horizon 里批量 worker 碰不到即时和交易队列；席位统计在批量扫描里复用。
 */
class MailLaneTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'someone@gmail.com';

    protected function setUp(): void
    {
        parent::setUp();
        // admin_setting() 写入时清 Setting 的 Redis 缓存；CI 没有 Redis，换成数组存储（同 AccountEmailExactMatchTest）
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        app()->forgetScopedInstances();
        PlanCapacity::flushMemo();
    }

    public function test_verification_code_goes_to_instant_lane_with_expiry(): void
    {
        Queue::fake();
        $this->postJson('/api/v1/passport/comm/sendEmailVerify', ['email' => self::EMAIL])->assertOk();

        Queue::assertPushedOn(MailLane::INSTANT, SendEmailJob::class, function (SendEmailJob $job) {
            $notAfter = (int) ($this->params($job)['not_after'] ?? 0);
            return $notAfter >= time() + 290 && $notAfter <= time() + 300;
        });
    }

    public function test_mail_link_login_goes_to_instant_lane(): void
    {
        Queue::fake();
        admin_setting(['login_with_mail_link_enable' => 1]);
        $this->makeUser(self::EMAIL);

        $this->postJson('/api/v1/passport/auth/loginWithMailLink', ['email' => self::EMAIL])->assertOk();

        Queue::assertPushedOn(MailLane::INSTANT, SendEmailJob::class, fn (SendEmailJob $job) => isset($this->params($job)['not_after']));
    }

    public function test_send_email_job_defaults_to_transactional_and_maps_legacy_mass_queue_to_bulk(): void
    {
        $default = new SendEmailJob(['email' => self::EMAIL]);
        $this->assertSame(MailLane::TRANSACTIONAL, $default->queue);
        $this->assertSame(10, $default->timeout);

        $mass = new SendEmailJob(['email' => self::EMAIL], 'send_email_mass');
        $this->assertSame(MailLane::BULK, $mass->queue);
        $this->assertSame(60, $mass->timeout, '批量限速可能让 worker 等 20 秒，10 秒超时会被杀');

        $this->assertSame(MailLane::INSTANT, (new SendEmailJob(['email' => self::EMAIL], MailLane::INSTANT))->queue);
    }

    public function test_billing_mail_lanes_by_kind(): void
    {
        $this->assertSame(MailLane::TRANSACTIONAL, SendBillingMailJob::laneFor(SendBillingMailJob::KIND_RECEIPT));
        $this->assertSame(MailLane::TRANSACTIONAL, SendBillingMailJob::laneFor(SendBillingMailJob::KIND_PAYG_FLOOR));
        $this->assertSame(MailLane::TRANSACTIONAL, SendBillingMailJob::laneFor(SendBillingMailJob::KIND_WITHDRAWAL));
        foreach ([SendBillingMailJob::KIND_INVOICE, SendBillingMailJob::KIND_EXPIRED, SendBillingMailJob::KIND_WINBACK, SendBillingMailJob::KIND_TRAFFIC] as $kind) {
            $this->assertSame(MailLane::BULK, SendBillingMailJob::laneFor($kind), $kind);
        }
        // 后台手动重发：管理员在等，走交易通道
        $this->assertSame(MailLane::TRANSACTIONAL, SendBillingMailJob::laneFor(SendBillingMailJob::KIND_INVOICE, true));

        $this->assertSame(MailLane::BULK, (new SendBillingMailJob(SendBillingMailJob::KIND_INVOICE, 1, 'first'))->queue);
        $this->assertSame(MailLane::TRANSACTIONAL, (new SendBillingMailJob(SendBillingMailJob::KIND_RECEIPT, 1))->queue);
    }

    public function test_expired_instant_mail_is_dropped_and_not_counted_against_user(): void
    {
        $user = $this->makeUser(self::EMAIL);
        $job = new SendEmailJob([
            'email' => self::EMAIL,
            'user_id' => $user->id,
            'subject' => '验证码',
            'template_name' => 'verify',
            'template_value' => ['name' => 'XBoard', 'code' => '123456', 'url' => 'https://panel.example.test'],
            'not_after' => time() - 120,
        ], MailLane::INSTANT);

        $job->handle();

        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
        $log = MailLog::where('email', self::EMAIL)->sole();
        $this->assertSame(DeliveryMonitor::EXPIRED, $log->category);
        $this->assertSame(0, (int) $log->status);
        $user->refresh();
        $this->assertSame(0, (int) ($user->mail_failed_count ?? 0));
        $this->assertNull($user->mail_suppressed_at);
    }

    public function test_instant_mail_within_expiry_is_sent(): void
    {
        $job = new SendEmailJob([
            'email' => self::EMAIL,
            'subject' => '验证码',
            'template_name' => 'verify',
            'template_value' => ['name' => 'XBoard', 'code' => '123456', 'url' => 'https://panel.example.test'],
            'not_after' => time() + 200,
        ], MailLane::INSTANT);

        $job->handle();

        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
        $this->assertNull(MailLog::where('email', self::EMAIL)->sole()->category);
    }

    public function test_expired_instant_mail_is_sent_when_dropping_is_turned_off(): void
    {
        config(['mail_lanes.drop_expired_instant' => false]);
        $job = new SendEmailJob([
            'email' => self::EMAIL,
            'subject' => '验证码',
            'template_name' => 'verify',
            'template_value' => ['name' => 'XBoard', 'code' => '123456', 'url' => 'https://panel.example.test'],
            'not_after' => time() - 120,
        ], MailLane::INSTANT);

        $job->handle();

        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_horizon_bulk_workers_never_take_instant_or_transactional_mail(): void
    {
        $supervisors = config('horizon.environments.production');
        $this->assertSame(['mail_instant'], $supervisors['mail-instant']['queue']);
        $this->assertSame(['mail_transactional'], $supervisors['mail-transactional']['queue']);
        $this->assertSame(['mail_bulk'], $supervisors['mail-bulk']['queue']);
        // 升级时排在旧队列里的存量要有人接，而且不占批量 / 即时的进程
        $this->assertSame(MailLane::LEGACY_QUEUES, $supervisors['mail-legacy']['queue']);
        // 其他 supervisor 不再碰任何邮件队列
        foreach ($supervisors as $name => $supervisor) {
            if (str_starts_with($name, 'mail-')) {
                continue;
            }
            $this->assertEmpty(array_intersect($supervisor['queue'], array_merge(MailLane::ALL, MailLane::LEGACY_QUEUES)), $name);
        }
        $this->assertSame($supervisors, config('horizon.environments.local'));
    }

    public function test_plan_capacity_reuses_occupancy_only_when_asked(): void
    {
        $group = new ServerGroup();
        $group->name = '基础';
        $group->save();
        $plan = Plan::create([
            'name' => '静态家宽拼车', 'group_id' => $group->id, 'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => null, 'reset_traffic_method' => 1,
            'prices' => ['monthly' => 20], 'capacity_limit' => 2,
        ]);
        $this->makeUser('a@gmail.com', ['plan_id' => $plan->id, 'expired_at' => time() + 86400]);
        $this->assertSame([$plan->id => 1], PlanCapacity::remaining([$plan], PlanCapacity::MAIL_CACHE_SECONDS));

        // 批量场景：60 秒内同一组套餐不再查库（新占的位要等缓存过期才算进去，只影响邮件推荐）
        $this->makeUser('b@gmail.com', ['plan_id' => $plan->id, 'expired_at' => time() + 86400]);
        DB::enableQueryLog();
        $this->assertSame([$plan->id => 1], PlanCapacity::remaining([$plan], PlanCapacity::MAIL_CACHE_SECONDS));
        $this->assertCount(0, DB::getQueryLog());

        // 不传缓存秒数（前台套餐列表、到货提醒等）：照旧实时查
        $this->assertSame([$plan->id => 0], PlanCapacity::remaining([$plan]));
        DB::disableQueryLog();
    }

    private function makeUser(string $email, array $attrs = []): User
    {
        $user = new User();
        $user->email = $email;
        $user->password = Hash::make('old-password');
        $user->uuid = Helper::guid(true);
        $user->token = Str::random(32);
        foreach ($attrs as $k => $v) {
            $user->{$k} = $v;
        }
        $user->save();
        return $user;
    }

    private function params(SendEmailJob $job): array
    {
        $prop = (new \ReflectionObject($job))->getProperty('params');
        $prop->setAccessible(true);
        return (array) $prop->getValue($job);
    }
}
