<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\ConfigSave;
use App\Jobs\SendBillingMailJob;
use App\Jobs\SendEmailJob;
use App\Models\MailLog;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Models\UserNotificationPref;
use App\Services\MailService;
use App\Services\Notification\NotificationPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 用户通知偏好：按类别退订、免登录偏好页、List-Unsubscribe 一键退订、发信侧统一判断、后台统计。
 */
class NotificationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1073741824;
    private const APP_URL = 'https://panel.example.test';

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        app()->forgetScopedInstances();
        config(['v2board.app_name' => '苏菲家宽', 'v2board.app_url' => self::APP_URL . '/', 'v2board.email_template' => 'editorial']);
        $group = new ServerGroup();
        $group->name = '基础';
        $group->save();
        $this->plan = Plan::create([
            'name' => '静态家宽拼车', 'group_id' => $group->id, 'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => null, 'reset_traffic_method' => 1,
            'prices' => ['monthly' => 20, 'quarterly' => 57],
        ]);
    }

    private function admin(string $path): string
    {
        return '/api/v2/' . admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))) . $path;
    }

    /** phpunit.xml 把 MAIL_DRIVER 设成 array：真正走了 Mail::send 的信都在这里 */
    private function sent(): array
    {
        return app('mailer')->getSymfonyTransport()->messages()->all();
    }

    private function user(array $attributes = []): User
    {
        return User::create($attributes + [
            'email' => Str::random(10) . '@example.test', 'password' => 'x', 'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'plan_id' => $this->plan->id, 'group_id' => $this->plan->group_id, 'balance' => 0,
            'transfer_enable' => 100 * self::GB, 'u' => 0, 'd' => 0, 'device_limit' => 2,
            'remind_expire' => 1, 'remind_traffic' => 1, 'expired_at' => time() + 30 * 86400,
        ]);
    }

    // ---------------------------------------------------------------- 存储与镜像

    public function test_defaults_are_all_enabled_and_billing_mirrors_legacy_column(): void
    {
        $user = $this->user();
        $view = NotificationPreference::view($user);
        $this->assertCount(5, $view['categories']);
        foreach ($view['categories'] as $row) {
            $this->assertTrue($row['enabled'], $row['key']);
            $this->assertFalse($row['locked'], $row['key']);
        }

        NotificationPreference::set($user, NotificationPreference::BILLING, false, UserNotificationPref::SOURCE_PANEL, '1.2.3.4');
        $this->assertSame(0, (int) User::where('id', $user->id)->value('remind_expire'));
        $row = UserNotificationPref::where('user_id', $user->id)->where('category', 'billing')->first();
        $this->assertNotNull($row);
        $this->assertFalse($row->enabled);
        $this->assertSame('panel', $row->source);
        $this->assertSame('1.2.3.4', $row->ip);

        // 老接口把列改回去：行跟着变、来源记 legacy
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/user/update', ['remind_expire' => 1])->assertOk();
        $this->assertTrue(NotificationPreference::enabled($user->fresh(), 'billing'));
        $this->assertSame('legacy', UserNotificationPref::where('user_id', $user->id)->where('category', 'billing')->value('source'));
    }

    public function test_user_endpoints_read_and_write_preferences(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/user/notify/prefs')->assertOk()
            ->assertJsonPath('data.categories.0.key', 'billing')
            ->assertJsonPath('data.always', ['receipt', 'withdrawal', 'security']);

        $this->postJson('/api/v1/user/notify/prefs/save', ['prefs' => ['marketing' => false, 'support' => false]])
            ->assertOk()
            ->assertJsonPath('data.categories.2.enabled', false)   // support
            ->assertJsonPath('data.categories.4.enabled', false);  // marketing
        $this->postJson('/api/v1/user/notify/prefs/save', ['prefs' => ['nope' => false]])->assertStatus(422);
        $this->assertFalse(NotificationPreference::allows($user->id, 'marketing'));
        $this->assertTrue(NotificationPreference::allows($user->id, 'billing'));
        $this->assertTrue(NotificationPreference::allows($user->id, null), '交易类永远发');
    }

    public function test_locked_categories_always_send_but_keep_the_users_choice(): void
    {
        $user = $this->user();
        NotificationPreference::set($user, 'marketing', false, 'panel');
        config(['v2board.notify_optional_categories' => 'billing,usage']);
        $this->assertTrue(NotificationPreference::allows($user, 'marketing'), '后台锁定的类别照发');
        $view = NotificationPreference::view($user);
        $marketing = collect($view['categories'])->firstWhere('key', 'marketing');
        $this->assertTrue($marketing['locked']);
        $this->assertFalse($marketing['enabled'], '用户的选择保留，解锁后立即生效');
        config(['v2board.notify_optional_categories' => '']);
        $this->assertFalse(NotificationPreference::allows($user, 'marketing'));
    }

    // ---------------------------------------------------------------- 免登录偏好页与一键退订

    public function test_guest_page_uses_random_key_and_masks_email(): void
    {
        $user = $this->user(['email' => 'shannon@example.test']);
        $key = NotificationPreference::key($user);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $key);
        $this->assertSame($key, NotificationPreference::key($user->fresh()), '第二次拿到同一把');
        $this->assertSame(self::APP_URL . '/notify/' . $key, NotificationPreference::manageUrl($user));

        $this->postJson('/api/v1/guest/notify/fetch', ['token' => $key])->assertOk()
            ->assertJsonPath('data.email', 'sh***@example.test')
            ->assertJsonPath('data.categories.4.key', 'marketing');
        $this->postJson('/api/v1/guest/notify/fetch', ['token' => str_repeat('0', 32)])->assertNotFound();
        $this->postJson('/api/v1/guest/notify/fetch', ['token' => 'abc'])->assertNotFound();

        $this->postJson('/api/v1/guest/notify/update', ['token' => $key, 'prefs' => ['marketing' => false]])->assertOk()
            ->assertJsonPath('data.categories.4.enabled', false);
        $this->assertSame('email_link', UserNotificationPref::where('user_id', $user->id)->where('category', 'marketing')->value('source'));

        // 换钥匙后旧链接全部失效
        NotificationPreference::rotate($user);
        $this->postJson('/api/v1/guest/notify/fetch', ['token' => $key])->assertNotFound();
    }

    public function test_one_click_unsubscribe_posts_and_get_redirects(): void
    {
        $user = $this->user();
        $key = NotificationPreference::key($user);
        $url = "/api/v1/guest/notify/unsubscribe/{$key}/marketing";

        $this->get($url)->assertRedirect(self::APP_URL . '/notify/' . $key . '?from=marketing');
        $this->assertTrue(NotificationPreference::enabled($user, 'marketing'), 'GET 不改状态');

        $this->post($url, ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertSee('OK');
        $this->assertFalse(NotificationPreference::enabled($user->fresh(), 'marketing'));
        $this->assertSame('list_unsubscribe', UserNotificationPref::where('user_id', $user->id)->where('category', 'marketing')->value('source'));

        $this->post("/api/v1/guest/notify/unsubscribe/{$key}/receipt")->assertNotFound();
        $this->post('/api/v1/guest/notify/unsubscribe/' . str_repeat('f', 32) . '/marketing')->assertNotFound();
    }

    // ---------------------------------------------------------------- 发信侧统一判断

    public function test_send_email_job_skips_opted_out_category_and_adds_headers_for_bulk(): void
    {
        $user = $this->user();

        (new SendEmailJob([
            'email' => $user->email, 'user_id' => $user->id, 'category' => 'marketing',
            'subject' => '活动', 'template_name' => 'notify',
            'template_value' => ['name' => '苏菲家宽', 'url' => self::APP_URL, 'content' => 'hi'],
        ]))->handle();
        $this->assertCount(1, $this->sent());
        $this->assertSame(1, MailLog::where('subject', '活动')->where('status', 1)->count());

        // 用户关掉营销后：不发、不记日志
        NotificationPreference::set($user, 'marketing', false, 'panel');
        $result = MailService::sendEmail([
            'email' => $user->email, 'user_id' => $user->id, 'category' => 'marketing',
            'subject' => '活动 2', 'template_name' => 'notify',
            'template_value' => ['name' => '苏菲家宽', 'url' => self::APP_URL, 'content' => 'hi'],
        ]);
        $this->assertTrue($result['skipped'] ?? false);
        $this->assertCount(1, $this->sent());
        $this->assertSame(0, MailLog::where('subject', '活动 2')->count());

        // 不传 user_id 也按邮箱反查得到同一个人
        $result = MailService::sendEmail([
            'email' => $user->email, 'category' => 'marketing',
            'subject' => '活动 3', 'template_name' => 'notify',
            'template_value' => ['name' => '苏菲家宽', 'url' => self::APP_URL, 'content' => 'hi'],
        ]);
        $this->assertTrue($result['skipped'] ?? false);

        // 交易类（没有类别）照发，哪怕用户把能关的都关了
        foreach (NotificationPreference::CATEGORIES as $category) {
            NotificationPreference::set($user, $category, false, 'panel');
        }
        $result = MailService::sendEmail([
            'email' => $user->email, 'user_id' => $user->id,
            'subject' => '验证码', 'template_name' => 'verify',
            'template_value' => ['name' => '苏菲家宽', 'url' => self::APP_URL, 'code' => '123456'],
        ]);
        $this->assertNull($result['error']);
        $this->assertCount(2, $this->sent());
    }

    public function test_deliver_adds_list_unsubscribe_headers_only_for_bulk_categories(): void
    {
        $user = $this->user();
        $data = ['name' => '苏菲家宽', 'url' => self::APP_URL, 'content' => 'hi'];

        MailService::deliver($user->email, '活动', 'mail.editorial.notify', $data, [], $user->id, 'marketing');
        MailService::deliver($user->email, '账单', 'mail.editorial.notify', $data, [], $user->id, 'billing');
        MailService::deliver($user->email, '收据', 'mail.editorial.notify', $data, [], $user->id, null);

        $messages = $this->sent();
        $this->assertCount(3, $messages);
        $key = (string) $user->fresh()->notify_key;
        $this->assertNotSame('', $key);

        $marketing = $messages[0]->getOriginalMessage();
        $this->assertSame('<' . self::APP_URL . "/api/v1/guest/notify/unsubscribe/{$key}/marketing>", $marketing->getHeaders()->get('List-Unsubscribe')->getBodyAsString());
        $this->assertSame('List-Unsubscribe=One-Click', $marketing->getHeaders()->get('List-Unsubscribe-Post')->getBodyAsString());
        $this->assertStringContainsString(self::APP_URL . '/notify/' . $key, $marketing->getHtmlBody(), '页脚链接指向免登录偏好页');

        $billing = $messages[1]->getOriginalMessage();
        $this->assertFalse($billing->getHeaders()->has('List-Unsubscribe'), '账单类不带一键退订头');
        $this->assertStringContainsString('/notify/' . $key, $billing->getHtmlBody());

        $receipt = $messages[2]->getOriginalMessage();
        $this->assertFalse($receipt->getHeaders()->has('List-Unsubscribe'));
        $this->assertStringNotContainsString('/notify/' . $key, $receipt->getHtmlBody(), '交易类没有偏好链接');

        // 后台关掉 List-Unsubscribe 头、改页脚文案
        config(['v2board.notify_list_unsubscribe_enable' => 0, 'v2board.notify_footer_label' => '邮件设置']);
        MailService::deliver($user->email, '活动', 'mail.editorial.notify', $data, [], $user->id, 'marketing');
        $again = $this->sent()[3]->getOriginalMessage();
        $this->assertFalse($again->getHeaders()->has('List-Unsubscribe'));
        $this->assertStringContainsString('邮件设置', $again->getHtmlBody());
    }

    public function test_billing_job_respects_billing_and_marketing_categories(): void
    {
        $user = $this->user(['expired_at' => time() - 86400, 'lifecycle_stage' => 1, 'lifecycle_expiry' => time() - 86400]);

        $job = new SendBillingMailJob(SendBillingMailJob::KIND_WINBACK, $user->id, '2', (int) $user->expired_at);
        $job->handle(app(\App\Services\Billing\BillingDocumentService::class), app(\App\Services\Billing\BillingArchive::class));
        $this->assertSame(SendBillingMailJob::RESULT_EMAIL, $job->result);

        NotificationPreference::set($user, 'marketing', false, 'email_link');
        $job = new SendBillingMailJob(SendBillingMailJob::KIND_WINBACK, $user->id, '2', (int) $user->expired_at);
        $job->handle(app(\App\Services\Billing\BillingDocumentService::class), app(\App\Services\Billing\BillingArchive::class));
        $this->assertSame(SendBillingMailJob::RESULT_SKIPPED, $job->result, '关了营销：挽回邮件不发');

        // 关了营销但没关账单：「服务已暂停」照发
        $job = new SendBillingMailJob(SendBillingMailJob::KIND_EXPIRED, $user->id, '1', (int) $user->expired_at);
        $job->handle(app(\App\Services\Billing\BillingDocumentService::class), app(\App\Services\Billing\BillingArchive::class));
        $this->assertSame(SendBillingMailJob::RESULT_EMAIL, $job->result);

        NotificationPreference::set($user, 'billing', false, 'panel');
        $job = new SendBillingMailJob(SendBillingMailJob::KIND_EXPIRED, $user->id, '1', (int) $user->expired_at);
        $job->handle(app(\App\Services\Billing\BillingDocumentService::class), app(\App\Services\Billing\BillingArchive::class));
        $this->assertSame(SendBillingMailJob::RESULT_SKIPPED, $job->result);
    }

    public function test_remind_scan_sends_winback_by_marketing_preference(): void
    {
        Bus::fake([SendBillingMailJob::class, SendEmailJob::class]);
        config(['v2board.billing_winback_days' => '7,30', 'v2board.billing_expired_enable' => 1]);
        $expired = time() - 7 * 86400 - 3600;
        $keeps = $this->user(['expired_at' => $expired, 'lifecycle_stage' => 1, 'lifecycle_expiry' => $expired]);
        $optedOut = $this->user(['expired_at' => $expired, 'lifecycle_stage' => 1, 'lifecycle_expiry' => $expired]);
        NotificationPreference::set($optedOut, 'marketing', false, 'panel');

        app(MailService::class)->processUsersInChunks(100);

        Bus::assertDispatched(SendBillingMailJob::class, fn (SendBillingMailJob $job) => $job->id === $keeps->id && $job->kind === SendBillingMailJob::KIND_WINBACK);
        Bus::assertNotDispatched(SendBillingMailJob::class, fn (SendBillingMailJob $job) => $job->id === $optedOut->id);
        $this->assertSame(1, (int) User::where('id', $optedOut->id)->value('lifecycle_stage'), '没发就不推进档位');
    }

    // ---------------------------------------------------------------- 后台

    public function test_admin_stats_log_and_save(): void
    {
        $admin = $this->user(['is_admin' => 1]);
        $a = $this->user();
        $b = $this->user();
        NotificationPreference::set($a, 'marketing', false, 'list_unsubscribe', '9.9.9.9');
        NotificationPreference::set($b, 'marketing', false, 'email_link');
        NotificationPreference::set($b, 'support', false, 'panel');
        Sanctum::actingAs($admin);

        $stats = $this->getJson($this->admin('/notify/stats'))->assertOk()->json('data');
        $marketing = collect($stats['categories'])->firstWhere('category', 'marketing');
        $this->assertSame(2, $marketing['disabled']);
        $this->assertEqualsCanonicalizing(['list_unsubscribe' => 1, 'email_link' => 1], $marketing['by_source']);
        $this->assertSame(2, $stats['users_with_optout']);
        $this->assertSame(3, $stats['recent_30d']);

        $this->getJson($this->admin('/notify/fetch?email=' . $b->email))->assertOk()
            ->assertJsonPath('data.user_id', $b->id)
            ->assertJsonPath('data.categories.2.enabled', false);

        $log = $this->getJson($this->admin('/notify/log?category=marketing'))->assertOk()->json();
        $this->assertSame(2, $log['total']);
        $this->assertSame('9.9.9.9', collect($log['data'])->firstWhere('user_id', $a->id)['ip']);

        $this->postJson($this->admin('/notify/save'), ['user_id' => $a->id, 'prefs' => ['marketing' => true]])->assertOk();
        $this->assertTrue(NotificationPreference::enabled($a->fresh(), 'marketing'));
        $this->assertSame('admin', UserNotificationPref::where('user_id', $a->id)->where('category', 'marketing')->value('source'));

        $this->postJson($this->admin('/user/sendMail'), ['subject' => 's', 'content' => 'c', 'category' => 'bogus'])->assertStatus(422);
        $this->assertTrue(Validator::make([
            'notify_optional_categories' => 'billing,usage,marketing',
            'notify_footer_label' => '邮件设置',
            'notify_list_unsubscribe_enable' => 0,
        ], ConfigSave::RULES)->passes());
        $this->assertTrue(Validator::make(['notify_optional_categories' => 'drop table'], ConfigSave::RULES)->fails());
    }

    public function test_mass_mail_tags_category_and_skips_opted_out_users(): void
    {
        $admin = $this->user(['is_admin' => 1]);
        $in = $this->user();
        $out = $this->user();
        NotificationPreference::set($out, 'announcement', false, 'panel');
        Sanctum::actingAs($admin);

        $this->postJson($this->admin('/user/sendMail'), ['subject' => '维护公告', 'content' => '今晚维护', 'scope' => 'all'])->assertOk();
        // QUEUE_CONNECTION=sync：任务已同步执行
        $to = array_map(fn ($m) => $m->getOriginalMessage()->getTo()[0]->getAddress(), $this->sent());
        $this->assertContains($in->email, $to);
        $this->assertNotContains($out->email, $to);
    }
}
