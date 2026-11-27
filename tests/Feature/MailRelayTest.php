<?php

namespace Tests\Feature;

use App\Jobs\SendEmailJob;
use App\Models\BillingDocument;
use App\Models\MailLog;
use App\Models\Order;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\Mail\DeliveryMonitor;
use App\Services\Mail\MailLane;
use App\Services\Mail\MailRelay;
use App\Services\Mail\MailRelayResults;
use App\Services\OrderService;
use App\Services\PlanCapacity;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Rust 投递服务的面板这一半：默认不开、开了的通道只入队、出错退回直发、结果回来后记账与回写收据状态。
 * 投递服务本身（SMTP、重试、限速）的测试在 mail-relay/ 的 cargo test 里。
 */
class MailRelayTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{0: string, 1: array}> */
    private array $queued = [];

    private string $spool;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        app()->forgetScopedInstances();
        PlanCapacity::flushMemo();
        config(['v2board.email_verify_nudge_enable' => 0]);
        MailRelay::reset();
        $this->queued = [];
        MailRelay::$sink = function (string $lane, array $fields) {
            $this->queued[] = [$lane, $fields];
        };
        $this->spool = sys_get_temp_dir() . '/mail-spool-' . Str::random(8);
        config(['mail_lanes.relay.spool_dir' => $this->spool]);
    }

    protected function tearDown(): void
    {
        MailRelay::$sink = null;
        MailRelay::reset();
        foreach (glob($this->spool . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->spool);
        parent::tearDown();
    }

    private function sent()
    {
        return app('mailer')->getSymfonyTransport()->messages();
    }

    private function notifyJob(string $lane, string $email = 'someone@example.test'): SendEmailJob
    {
        return new SendEmailJob(['email' => $email, 'subject' => '测试通知', 'template_name' => 'notify', 'template_value' => ['name' => 'X', 'url' => 'https://x.test', 'content' => 'hello']], $lane);
    }

    private function user(array $attributes = []): User
    {
        return User::create($attributes + [
            'email' => Str::random(10) . '@example.test', 'password' => 'x', 'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'balance' => 0, 'transfer_enable' => 0, 'u' => 0, 'd' => 0, 'expired_at' => time() + 86400,
        ]);
    }

    public function test_off_by_default_every_lane_sends_directly(): void
    {
        $this->assertSame([], MailRelay::lanes());
        foreach (MailLane::ALL as $lane) {
            $this->notifyJob($lane)->handle();
        }
        $this->assertCount(3, $this->sent());
        $this->assertSame([], $this->queued);
        $this->assertSame(3, MailLog::where('status', 1)->count());
    }

    public function test_only_listed_lanes_are_handed_to_the_relay(): void
    {
        config(['mail_lanes.relay.lanes' => 'bulk, nonsense']);
        $this->assertSame(['bulk'], MailRelay::lanes());

        $this->notifyJob(MailLane::BULK, 'bulk@example.test')->handle();
        $this->notifyJob('send_email_mass', 'legacy@example.test')->handle();   // 旧队列名也算批量
        $this->notifyJob(MailLane::TRANSACTIONAL, 'tx@example.test')->handle();

        $this->assertCount(2, $this->queued);
        [$lane, $fields] = $this->queued[0];
        $this->assertSame('bulk', $lane);
        $this->assertSame('bulk@example.test', $fields['to']);
        $this->assertSame((string) config('mail.from.address'), $fields['from']);
        $this->assertSame('0', $fields['attempt']);
        $this->assertStringContainsString('To: bulk@example.test', $fields['eml']);
        $this->assertStringContainsString('Subject: =?utf-8?', $fields['eml']);
        $this->assertStringContainsString('hello', quoted_printable_decode($fields['eml']));
        $meta = json_decode($fields['meta'], true);
        $this->assertSame('bulk@example.test', $meta['email']);
        $this->assertSame('mail.default.notify', $meta['view']);

        // 入队的不记 v2_mail_log（结果回来再记），直发的照记
        $this->assertCount(1, $this->sent());
        $this->assertSame('tx@example.test', $this->sent()->first()->getOriginalMessage()->getTo()[0]->getAddress());
        $this->assertSame(1, MailLog::count());
    }

    public function test_instant_mail_carries_its_deadline(): void
    {
        config(['mail_lanes.relay.lanes' => 'instant']);
        $job = new SendEmailJob(['email' => 'a@example.test', 'subject' => 'code', 'template_name' => 'verify', 'template_value' => ['name' => 'X', 'code' => '123456', 'url' => 'https://x.test'], 'not_after' => time() + 300], MailLane::INSTANT);
        $job->handle();
        $this->assertCount(1, $this->queued);
        $this->assertEqualsWithDelta(time() + 300, (int) $this->queued[0][1]['not_after'], 2);
    }

    public function test_relay_errors_fall_back_to_direct_send(): void
    {
        config(['mail_lanes.relay.lanes' => 'bulk']);
        MailRelay::$sink = function () {
            throw new \RuntimeException('redis down');
        };
        $this->notifyJob(MailLane::BULK)->handle();
        $this->assertCount(1, $this->sent(), 'Redis 写不进就照旧直接发，不丢信');
    }

    public function test_receipt_goes_through_spool_and_is_marked_sent_when_the_result_arrives(): void
    {
        config(['mail_lanes.relay.lanes' => 'transactional', 'mail_lanes.relay.inline_max_bytes' => 1024]);
        $group = new ServerGroup();
        $group->name = '基础';
        $group->save();
        $plan = Plan::create([
            'name' => '静态家宽拼车', 'group_id' => $group->id, 'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => null, 'reset_traffic_method' => 1, 'prices' => ['monthly' => 20],
        ]);
        $user = $this->user(['plan_id' => $plan->id, 'group_id' => $group->id]);
        $order = Order::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'period' => 'monthly', 'type' => Order::TYPE_NEW_PURCHASE,
            'trade_no' => Helper::generateOrderNo(), 'total_amount' => 2000, 'status' => Order::STATUS_PROCESSING, 'paid_at' => time(),
        ]);
        (new OrderService($order))->open();

        $this->assertCount(0, $this->sent());
        $this->assertCount(1, $this->queued);
        [$lane, $fields] = $this->queued[0];
        $this->assertSame('transactional', $lane);
        $this->assertArrayNotHasKey('eml', $fields);
        $this->assertSame($fields['id'] . '.eml', $fields['eml_path']);
        $raw = file_get_contents($this->spool . '/' . $fields['eml_path']);
        $this->assertStringContainsString('application/pdf', $raw);
        $this->assertNotNull($order->fresh()->receipt_sent_at, '交给投递服务就算处理过，重复派发不再排第二封');

        $doc = BillingDocument::where('order_id', $order->id)->firstOrFail();
        $this->assertNull($doc->sent_at, '还没真正发出去');

        $category = app(MailRelayResults::class)->handle(['id' => $fields['id'], 'status' => 'sent', 'class' => 'ok', 'code' => '250', 'attempts' => '1', 'meta' => $fields['meta']]);
        $this->assertSame(DeliveryMonitor::OK, $category);
        $doc->refresh();
        $this->assertNotNull($doc->sent_at);
        $this->assertSame(BillingDocument::CHANNEL_EMAIL, $doc->channel);
        $this->assertSame(1, MailLog::where('email', $user->email)->where('status', 1)->count());
    }

    public function test_results_are_recorded_like_direct_sends(): void
    {
        $results = app(MailRelayResults::class);
        $bounce = $this->user();
        $meta = fn (User $u) => json_encode(['email' => $u->email, 'subject' => 's', 'view' => 'mail.default.notify', 'user_id' => $u->id, 'not_after' => 0]);

        $this->assertSame(DeliveryMonitor::BOUNCE, $results->handle(['id' => 'a', 'status' => 'failed', 'class' => 'permanent', 'code' => '550', 'error' => 'permanent error (550): 5.1.1 user unknown', 'meta' => $meta($bounce)]));
        $this->assertNotNull($bounce->fresh()->mail_suppressed_at, '退信一次即标记，同直发');

        $config = $this->user();
        $this->assertSame(DeliveryMonitor::CONFIG, $results->handle(['id' => 'b', 'status' => 'failed', 'class' => 'config', 'error' => 'no SMTP config yet', 'meta' => $meta($config)]));
        $this->assertSame(0, (int) $config->fresh()->mail_failed_count, '我们自己的配置问题不算在用户头上');

        $late = $this->user();
        $this->assertSame(DeliveryMonitor::EXPIRED, $results->handle(['id' => 'c', 'status' => 'expired', 'class' => 'expired', 'at' => (string) time(), 'meta' => $meta($late)]));
        $this->assertSame(1, MailLog::where('email', $late->email)->where('category', DeliveryMonitor::EXPIRED)->count());

        $this->assertSame('', $results->handle(['id' => 'd', 'status' => 'sent', 'meta' => 'not json']));
    }

    public function test_bad_spool_names_are_refused_by_the_relay_contract(): void
    {
        // 文件名就是 ULID + .eml：投递服务只接受不带路径的文件名（mail-relay/src/relay.rs load_eml）
        config(['mail_lanes.relay.lanes' => 'bulk', 'mail_lanes.relay.inline_max_bytes' => 10]);
        $this->notifyJob(MailLane::BULK)->handle();
        $name = $this->queued[0][1]['eml_path'];
        $this->assertMatchesRegularExpression('/^[0-9A-Z]{26}\.eml$/', $name);
        $this->assertFileExists($this->spool . '/' . $name);
    }
}
