<?php

namespace Tests\Feature;

use App\Jobs\SendBillingMailJob;
use App\Jobs\SendEmailJob;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\Billing\BillingArchive;
use App\Services\Billing\BillingDocumentService;
use App\Services\MailService;
use App\Services\Notification\NotificationPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 流量提醒的频率：同一周期「用到阈值」「用完」各一封，用量回落后重新武装；
 * 以前靠 24 小时的 Redis 标记，和每日扫描同周期，80%–99% 的用户每天都收一封。
 */
class TrafficReminderTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1073741824;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        app()->forgetScopedInstances();
        $group = new ServerGroup();
        $group->name = '基础';
        $group->save();
        $this->plan = Plan::create([
            'name' => '静态家宽拼车', 'group_id' => $group->id, 'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => null, 'reset_traffic_method' => 1,
            'prices' => ['monthly' => 20],
        ]);
    }

    private function user(array $attributes = []): User
    {
        return User::create($attributes + [
            'email' => Str::random(10) . '@example.test', 'password' => 'x', 'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'plan_id' => $this->plan->id, 'group_id' => $this->plan->group_id, 'balance' => 0,
            'transfer_enable' => 100 * self::GB, 'u' => 0, 'd' => 0, 'device_limit' => 2,
            'remind_expire' => 0, 'remind_traffic' => 1, 'expired_at' => time() + 30 * 86400,
            'next_reset_at' => time() + 10 * 86400,
        ]);
    }

    private function scan(): array
    {
        return (new MailService())->processUsersInChunks(100);
    }

    private function trafficJobs(): array
    {
        $jobs = [];
        Bus::assertDispatched(SendBillingMailJob::class, function (SendBillingMailJob $job) use (&$jobs) {
            if ($job->kind === SendBillingMailJob::KIND_TRAFFIC) {
                $jobs[] = $job;
            }
            return true;
        });
        return $jobs;
    }

    public function test_warn_mail_goes_out_once_per_cycle_not_once_per_day(): void
    {
        Bus::fake([SendBillingMailJob::class, SendEmailJob::class]);
        $user = $this->user(['u' => 50 * self::GB, 'd' => 35 * self::GB]);   // 85%

        $this->assertSame(1, $this->scan()['traffic_emails']);
        $this->assertSame(1, (int) $user->fresh()->traffic_notified_level);
        $this->assertNotNull($user->fresh()->traffic_notified_at);

        // 第二天用量 90%，还在同一周期：不再发
        $user->update(['d' => 40 * self::GB]);
        $this->assertSame(0, $this->scan()['traffic_emails']);
        $this->assertSame(0, $this->scan()['traffic_emails']);

        $jobs = $this->trafficJobs();
        $this->assertCount(1, $jobs);
        $this->assertSame(BillingDocumentService::TRAFFIC_WARN, $jobs[0]->stage);
        $this->assertSame((int) $user->id, $jobs[0]->id);
    }

    public function test_exhausted_mail_follows_warn_once_and_rearms_after_reset(): void
    {
        Bus::fake([SendBillingMailJob::class, SendEmailJob::class]);
        $user = $this->user(['u' => 80 * self::GB, 'd' => 5 * self::GB]);   // 85%
        $this->assertSame(1, $this->scan()['traffic_emails']);

        // 用完：再发一封「用完」，之后天天停在 100% 也不再发
        $user->update(['d' => 20 * self::GB]);
        $this->assertSame(1, $this->scan()['traffic_emails']);
        $this->assertSame(2, (int) $user->fresh()->traffic_notified_level);
        $user->update(['d' => 30 * self::GB]);
        $this->assertSame(0, $this->scan()['traffic_emails']);

        // 月初重置：标记清零，下个周期用到 80% 再发
        $user->update(['u' => 0, 'd' => 0]);
        $this->assertSame(0, $this->scan()['traffic_emails']);
        $this->assertSame(0, (int) $user->fresh()->traffic_notified_level);
        $user->update(['u' => 81 * self::GB]);
        $this->assertSame(1, $this->scan()['traffic_emails']);

        $stages = array_map(fn (SendBillingMailJob $j) => $j->stage, $this->trafficJobs());
        $this->assertSame(['warn', 'exhausted', 'warn'], $stages);
    }

    public function test_jumping_straight_to_100_percent_sends_only_the_exhausted_mail(): void
    {
        Bus::fake([SendBillingMailJob::class, SendEmailJob::class]);
        $user = $this->user(['u' => 100 * self::GB]);
        $this->assertSame(1, $this->scan()['traffic_emails']);
        $this->assertSame(2, (int) $user->fresh()->traffic_notified_level);
        $this->assertSame(0, $this->scan()['traffic_emails']);
        $jobs = $this->trafficJobs();
        $this->assertCount(1, $jobs);
        $this->assertSame(BillingDocumentService::TRAFFIC_EXHAUSTED, $jobs[0]->stage);
    }

    public function test_threshold_and_exhausted_switch_are_configurable(): void
    {
        Bus::fake([SendBillingMailJob::class, SendEmailJob::class]);
        config(['v2board.remind_traffic_percent' => 90, 'v2board.remind_traffic_exhausted_enable' => 0]);
        $at85 = $this->user(['u' => 85 * self::GB]);
        $at95 = $this->user(['u' => 95 * self::GB]);
        $full = $this->user(['u' => 100 * self::GB]);

        $this->assertSame(1, $this->scan()['traffic_emails']);
        $this->assertSame(0, (int) $at85->fresh()->traffic_notified_level);
        $this->assertSame(1, (int) $at95->fresh()->traffic_notified_level);
        $this->assertSame(0, (int) $full->fresh()->traffic_notified_level, '关掉「用完」通知后 100% 的用户什么都不收');
    }

    public function test_upgrade_that_drops_usage_below_threshold_rearms_the_reminder(): void
    {
        Bus::fake([SendBillingMailJob::class, SendEmailJob::class]);
        $user = $this->user(['u' => 85 * self::GB]);
        $this->assertSame(1, $this->scan()['traffic_emails']);
        $user->update(['transfer_enable' => 300 * self::GB]);   // 升级后 28%
        $this->assertSame(0, $this->scan()['traffic_emails']);
        $this->assertSame(0, (int) $user->fresh()->traffic_notified_level);
        $user->update(['u' => 250 * self::GB]);   // 83%
        $this->assertSame(1, $this->scan()['traffic_emails']);
    }

    public function test_warn_waits_a_day_when_a_billing_mail_already_went_out(): void
    {
        Bus::fake([SendBillingMailJob::class, SendEmailJob::class]);
        // 到期前 24 小时内：当天先发最后一张账单，流量预警明天再说；「用完」不等
        $warn = $this->user(['remind_expire' => 1, 'expired_at' => time() + 3600, 'u' => 85 * self::GB]);
        $full = $this->user(['remind_expire' => 1, 'expired_at' => time() + 3600, 'u' => 100 * self::GB]);

        $stats = $this->scan();
        $this->assertSame(2, $stats['expire_emails']);
        $this->assertSame(1, $stats['traffic_emails']);
        $this->assertSame(0, (int) $warn->fresh()->traffic_notified_level);
        $this->assertSame(2, (int) $full->fresh()->traffic_notified_level);

        $this->assertSame(1, $this->scan()['traffic_emails']);
        $this->assertSame(1, (int) $warn->fresh()->traffic_notified_level);
    }

    public function test_users_who_turned_usage_mails_off_get_nothing(): void
    {
        Bus::fake([SendBillingMailJob::class, SendEmailJob::class]);
        $this->user(['u' => 90 * self::GB, 'remind_traffic' => 0]);
        $prefOff = $this->user(['u' => 90 * self::GB]);
        NotificationPreference::set($prefOff, NotificationPreference::USAGE, false, \App\Models\UserNotificationPref::SOURCE_PANEL);
        $this->assertSame(0, (int) $prefOff->fresh()->remind_traffic);
        $this->assertSame(0, $this->scan()['traffic_emails']);
        Bus::assertNotDispatched(SendBillingMailJob::class);
    }

    public function test_job_renders_usage_mail_and_rechecks_usage_at_send_time(): void
    {
        $user = $this->user(['u' => 60 * self::GB, 'd' => 25 * self::GB]);   // 85%
        $job = new SendBillingMailJob(SendBillingMailJob::KIND_TRAFFIC, (int) $user->id, BillingDocumentService::TRAFFIC_WARN);
        $job->handle(app(BillingDocumentService::class), app(BillingArchive::class));
        $this->assertSame(SendBillingMailJob::RESULT_EMAIL, $job->result);

        $messages = app('mailer')->getSymfonyTransport()->messages()->all();
        $this->assertCount(1, $messages);
        $mail = $messages[0]->getOriginalMessage();
        $this->assertStringContainsString('85%', $mail->getSubject());
        $html = $mail->getHtmlBody();
        $this->assertStringContainsString('85 GB / 100 GB', $html);
        $this->assertStringContainsString('15 GB', $html);
        $this->assertStringContainsString(date('Y-m-d', (int) $user->next_reset_at), $html);
        $this->assertStringContainsString('/notify/', $html, '页脚带免登录偏好链接');
        $this->assertFalse($mail->getHeaders()->has('List-Unsubscribe'), '用量提醒不是批量邮件，不带一键退订头');

        // 派发后流量重置了：到执行时不再发
        $user->update(['u' => 0, 'd' => 0]);
        $job = new SendBillingMailJob(SendBillingMailJob::KIND_TRAFFIC, (int) $user->id, BillingDocumentService::TRAFFIC_WARN);
        $job->handle(app(BillingDocumentService::class), app(BillingArchive::class));
        $this->assertSame(SendBillingMailJob::RESULT_SKIPPED, $job->result);
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages()->all());

        // 用完那封：主题与正文都是「用完」，带套餐推荐入口
        $user->update(['u' => 100 * self::GB]);
        $job = new SendBillingMailJob(SendBillingMailJob::KIND_TRAFFIC, (int) $user->id, BillingDocumentService::TRAFFIC_EXHAUSTED);
        $job->handle(app(BillingDocumentService::class), app(BillingArchive::class));
        $this->assertSame(SendBillingMailJob::RESULT_EMAIL, $job->result);
        $mail = app('mailer')->getSymfonyTransport()->messages()->all()[1]->getOriginalMessage();
        $this->assertStringContainsString('用完', $mail->getSubject());
        $this->assertStringContainsString('/plans', $mail->getHtmlBody());
    }

    public function test_legacy_template_is_used_when_billing_mails_are_off(): void
    {
        Bus::fake([SendBillingMailJob::class, SendEmailJob::class]);
        config(['v2board.billing_receipt_enable' => 0]);
        $warn = $this->user(['u' => 85 * self::GB]);
        $full = $this->user(['u' => 100 * self::GB]);
        $this->assertSame(2, $this->scan()['traffic_emails']);
        Bus::assertNotDispatched(SendBillingMailJob::class);
        // 老模板只有 80% 的文案：预警走老模板，「用完」没有可用模板就不发，但标记照打，不会天天重试
        Bus::assertDispatchedTimes(SendEmailJob::class, 1);
        $this->assertSame(1, (int) $warn->fresh()->traffic_notified_level);
        $this->assertSame(2, (int) $full->fresh()->traffic_notified_level);
        $this->assertSame(0, $this->scan()['traffic_emails']);
    }
}
