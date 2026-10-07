<?php

namespace Tests\Feature;

use App\Jobs\SendBillingMailJob;
use App\Jobs\SendEmailJob;
use App\Models\BalanceLog;
use App\Models\BillingDocument;
use App\Models\Coupon;
use App\Models\MailLog;
use App\Models\Order;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\BalanceLedger;
use App\Services\Billing\BillingArchive;
use App\Services\Billing\BillingDocumentService;
use App\Services\Mail\DeliveryMonitor;
use App\Services\MailService;
use App\Services\OrderService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Tests\TestCase;

/**
 * 财务面板补全：收据 / 账单归档与重新下载、邮件投递闭环（分类、暂停投递、Telegram 兜底、日报）、
 * 余额流水、到期后的「已暂停」与挽回邮件。
 */
class FinancePanelTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1073741824;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
        $group = new ServerGroup();
        $group->name = '基础';
        $group->save();
        $this->plan = Plan::create([
            'name' => '静态家宽拼车', 'group_id' => $group->id, 'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => null, 'reset_traffic_method' => 1,
            'prices' => ['monthly' => 20, 'quarterly' => 57],
        ]);
    }

    private function user(array $attributes = []): User
    {
        return User::create($attributes + [
            'email' => Str::random(10) . '@example.test', 'password' => 'x', 'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'plan_id' => $this->plan->id, 'group_id' => $this->plan->group_id, 'balance' => 0,
            'transfer_enable' => 100 * self::GB, 'u' => 0, 'd' => 0, 'device_limit' => 2,
            'remind_expire' => 1, 'remind_traffic' => 0, 'expired_at' => time() + 30 * 86400,
        ]);
    }

    private function order(User $user, array $attributes = []): Order
    {
        return Order::create($attributes + [
            'user_id' => $user->id, 'plan_id' => $this->plan->id, 'period' => 'monthly', 'type' => Order::TYPE_NEW_PURCHASE,
            'trade_no' => Helper::generateOrderNo(), 'total_amount' => 2000, 'status' => Order::STATUS_PROCESSING, 'paid_at' => time(),
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, SentMessage> */
    private function sent()
    {
        return app('mailer')->getSymfonyTransport()->messages();
    }

    private function runJob(string $kind, int $id, ?string $stage = null, ?int $expiry = null, bool $force = false): SendBillingMailJob
    {
        $job = new SendBillingMailJob($kind, $id, $stage, $expiry, $force);
        $job->handle(app(BillingDocumentService::class), app(BillingArchive::class));
        return $job;
    }

    private function adminPath(string $path): string
    {
        return '/api/v2/' . admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))) . $path;
    }

    /** 让 SMTP 层按给定错误失败（返回原来的 array 传输层，便于恢复） */
    private function failMailWith(string $error): TransportInterface
    {
        $original = app('mailer')->getSymfonyTransport();
        app('mailer')->setSymfonyTransport(new class ($error) extends AbstractTransport {
            public function __construct(private string $error)
            {
                parent::__construct();
            }

            protected function doSend(SentMessage $message): void
            {
                throw new TransportException($this->error);
            }

            public function __toString(): string
            {
                return 'failing://';
            }
        });
        return $original;
    }

    // ───────────────────────── 归档与下载 ─────────────────────────

    public function test_receipt_is_archived_and_downloadable_by_signed_link(): void
    {
        $user = $this->user();
        $order = $this->order($user);
        (new OrderService($order))->open();

        $doc = BillingDocument::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(BillingDocument::KIND_RECEIPT, $doc->kind);
        $this->assertSame(2000, (int) $doc->amount);
        $this->assertSame(BillingDocument::CHANNEL_EMAIL, $doc->channel);
        $this->assertNotNull($doc->sent_at);
        $this->assertSame(1, (int) $doc->send_count);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $doc->access_key);
        $this->assertSame($doc->doc_no, $doc->payload['doc_no'], '记下了开具时的内容快照');
        $this->assertSame(0, count(Storage::disk('local')->allFiles()), '不落 PDF 文件');
        $this->assertCount(1, $this->sent());
        $this->assertStringContainsString('/billing', $this->sent()->first()->getOriginalMessage()->getHtmlBody(), '邮件里提示可在面板重新下载');

        $this->assertMatchesRegularExpression('/^(RC)-\d{8}-[0-9A-HJKMNP-TV-Z]{8}$/', $doc->doc_no, '编号是日期 + 8 位码，不是订单 ID');

        $resp = $this->get($doc->downloadPath(time() + 60))->assertStatus(200);
        $resp->assertHeader('Content-Type', 'application/pdf');
        $resp->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString($doc->doc_no . '.pdf', (string) $resp->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $resp->getContent());
        $this->get("/api/v1/guest/billing/document/{$doc->id}/" . str_repeat('0', 40))->assertStatus(404);
        $this->get("/api/v1/guest/billing/document/{$doc->id}/{$doc->access_key}")->assertStatus(404);   // 旧的永久 key 形态不再能下载
        $this->get(str_replace("/{$doc->id}/", '/999999/', $doc->downloadPath(time() + 60)))->assertStatus(404);

        Sanctum::actingAs($user);
        $list = $this->getJson('/api/v1/user/billing/documents')->assertStatus(200)->json('data');
        $this->assertCount(1, $list);
        $this->assertSame('paid', $list[0]['status']);
        $this->assertSame($order->trade_no, $list[0]['order_trade_no']);
        $this->assertSame(BillingDocument::CHANNEL_EMAIL, $list[0]['channel']);
        $this->assertStringStartsWith("/api/v1/guest/billing/document/{$doc->id}/", $list[0]['download_path']);
        $this->assertStringNotContainsString($doc->access_key, $list[0]['download_path'], '链接里只有签名，没有密钥本身');
        $this->assertEqualsWithDelta(time() + BillingDocument::LINK_TTL, $list[0]['download_expires_at'], 5);
        $this->get($list[0]['download_path'])->assertStatus(200);

        // 别人的文档不出现在我的列表里
        Sanctum::actingAs($this->user());
        $this->assertCount(0, $this->getJson('/api/v1/user/billing/documents')->json('data'));
    }

    public function test_a_record_without_snapshot_is_rebuilt_from_the_order_once(): void
    {
        $user = $this->user();
        $order = $this->order($user);
        (new OrderService($order))->open();
        $doc = BillingDocument::where('order_id', $order->id)->firstOrFail();
        BillingDocument::where('id', $doc->id)->update(['payload' => null, 'size' => 0]);   // 升级前开的老记录

        $resp = $this->get($doc->downloadPath(time() + 60))->assertStatus(200);
        $this->assertStringStartsWith('%PDF', $resp->getContent());
        $fresh = $doc->fresh();
        $this->assertSame($doc->doc_no, $fresh->payload['doc_no'], '重建后补存快照，之后固定下来');
        $this->assertGreaterThan(0, (int) $fresh->size);
    }

    public function test_invoice_is_archived_once_per_expiry_and_the_final_stage_refreshes_it(): void
    {
        $user = $this->user(['expired_at' => time() + 5 * 86400]);
        $expiry = (int) $user->expired_at;

        $this->runJob(SendBillingMailJob::KIND_INVOICE, $user->id, BillingDocumentService::STAGE_FIRST, $expiry);
        $first = BillingDocument::where('user_id', $user->id)->where('kind', BillingDocument::KIND_INVOICE)->firstOrFail();
        $this->assertSame($expiry, (int) $first->expired_at);
        $this->assertSame(BillingDocumentService::STAGE_FIRST, $first->stage);
        $this->assertSame(2000, (int) $first->amount);

        $this->runJob(SendBillingMailJob::KIND_INVOICE, $user->id, BillingDocumentService::STAGE_FINAL, $expiry);
        $this->assertSame(1, BillingDocument::where('user_id', $user->id)->count(), '同一到期日只留一份账单');
        $final = $first->fresh();
        $this->assertSame(BillingDocumentService::STAGE_FINAL, $final->stage);
        $this->assertSame(2, (int) $final->send_count);
        $this->assertSame($first->access_key, $final->access_key, '下载链接不变');
        $this->assertCount(2, $this->sent());

        // 面板里的状态：续费前「待付款」，续费后「已结清」
        Sanctum::actingAs($user);
        $this->assertSame('open', $this->getJson('/api/v1/user/billing/documents')->json('data.0.status'));
        $user->update(['expired_at' => $expiry + 30 * 86400]);
        $this->assertSame('settled', $this->getJson('/api/v1/user/billing/documents')->json('data.0.status'));
    }

    public function test_admin_can_list_and_resend_archived_documents(): void
    {
        $user = $this->user();
        $order = $this->order($user);
        (new OrderService($order))->open();
        $doc = BillingDocument::where('order_id', $order->id)->firstOrFail();
        $this->assertCount(1, $this->sent());

        Sanctum::actingAs($this->user(['is_admin' => 1]));
        $rows = $this->postJson($this->adminPath('/billing/document/fetch'), ['email' => $user->email])->assertStatus(200)->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame((int) $doc->id, $rows[0]['id']);
        $this->assertSame($user->email, $rows[0]['email']);
        $this->assertFalse($rows[0]['mail_suppressed']);
        $this->assertSame(1, $rows[0]['send_count']);

        Bus::fake([SendBillingMailJob::class]);
        $this->postJson($this->adminPath('/billing/document/resend'), ['id' => $doc->id])->assertStatus(200);
        Bus::assertDispatched(SendBillingMailJob::class, fn (SendBillingMailJob $job) => $job->kind === SendBillingMailJob::KIND_RECEIPT && $job->id === (int) $order->id && $job->force);

        // 重发忽略「已发过」标记；普通的重复派发（比如队列重投）不会再发
        $this->runJob(SendBillingMailJob::KIND_RECEIPT, $order->id, null, null, true);
        $this->assertCount(2, $this->sent());
        $this->assertSame(2, (int) $doc->fresh()->send_count);
        $this->runJob(SendBillingMailJob::KIND_RECEIPT, $order->id);
        $this->assertCount(2, $this->sent());

        // 普通用户进不了后台接口
        Sanctum::actingAs($user);
        $this->postJson($this->adminPath('/billing/document/fetch'))->assertStatus(403);
    }

    // ───────────────────────── 投递闭环 ─────────────────────────

    public function test_delivery_failures_are_classified(): void
    {
        $this->assertSame(DeliveryMonitor::OK, DeliveryMonitor::classify(null));
        $this->assertSame(DeliveryMonitor::OK, DeliveryMonitor::classify(''));
        $this->assertSame(DeliveryMonitor::SUPPRESSED, DeliveryMonitor::classify('Expected response code "250" but got code "254", with message "254 4.7.1 Recipient address suppressed"'));
        $this->assertSame(DeliveryMonitor::BOUNCE, DeliveryMonitor::classify('550 5.1.1 <nobody@example.test>: Recipient address rejected: User unknown'));
        $this->assertSame(DeliveryMonitor::BOUNCE, DeliveryMonitor::classify('554 5.7.1 Message rejected by policy'));
        $this->assertSame(DeliveryMonitor::CONFIG, DeliveryMonitor::classify('Failed to authenticate on SMTP server with username "x" using the following authenticators: "LOGIN"'));
        $this->assertSame(DeliveryMonitor::CONFIG, DeliveryMonitor::classify('Connection could not be established with host "smtp.example.test:587": stream_socket_client(): Unable to connect'));
        $this->assertSame(DeliveryMonitor::TEMPORARY, DeliveryMonitor::classify('421 4.7.0 Try again later'));
        $this->assertSame(DeliveryMonitor::TEMPORARY, DeliveryMonitor::classify('Expected response code "250" but got an empty response'));

        $this->assertTrue(DeliveryMonitor::isRetryable(DeliveryMonitor::TEMPORARY));
        $this->assertTrue(DeliveryMonitor::isRetryable(DeliveryMonitor::CONFIG));
        $this->assertFalse(DeliveryMonitor::isRetryable(DeliveryMonitor::BOUNCE));
        $this->assertFalse(DeliveryMonitor::isRetryable(DeliveryMonitor::SUPPRESSED));
    }

    public function test_bounced_receipt_marks_the_user_and_goes_out_on_telegram_instead(): void
    {
        config(['v2board.telegram_bot_enable' => 1, 'v2board.telegram_bot_token' => '1:test']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $this->failMailWith('550 5.1.1 User unknown');
        $user = $this->user(['telegram_id' => 4242]);
        $order = $this->order($user);
        (new OrderService($order))->open();   // 同步队列：收据任务在这里执行

        $user->refresh();
        $this->assertNotNull($user->mail_suppressed_at);
        $this->assertSame(DeliveryMonitor::BOUNCE, $user->mail_suppressed_reason);
        $this->assertSame(1, (int) $user->mail_failed_count);
        $log = MailLog::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(0, (int) $log->status);
        $this->assertSame(DeliveryMonitor::BOUNCE, $log->category);
        $this->assertStringContainsString('User unknown', (string) $log->error);

        $doc = BillingDocument::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(BillingDocument::CHANNEL_TELEGRAM, $doc->channel);
        $this->assertNotNull($doc->sent_at);
        $this->assertNotNull($order->fresh()->receipt_sent_at);
        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), '/sendDocument') && $request->hasFile('document'));

        // 之后系统主动发的邮件不再进 SMTP：到期前账单直接走 Telegram，不再记新的失败
        $user->update(['expired_at' => time() + 5 * 86400]);
        $job = $this->runJob(SendBillingMailJob::KIND_INVOICE, $user->id, BillingDocumentService::STAGE_FIRST, (int) $user->fresh()->expired_at);
        $this->assertSame(SendBillingMailJob::RESULT_TELEGRAM, $job->result);
        $this->assertSame(1, MailLog::where('user_id', $user->id)->count());
        Http::assertSentCount(2);
    }

    public function test_temporary_failures_count_once_per_hour_then_suppress_and_a_successful_test_mail_clears_it(): void
    {
        $arrayTransport = $this->failMailWith('421 4.7.0 Try again later');
        $user = $this->user(['expired_at' => time() + 5 * 86400]);
        $expiry = (int) $user->expired_at;

        // 同一封信的 3 次队列重试：每次都交给队列、都记日志，但只算一次失败 —— 一次 OCI 抖动不该把人标上
        for ($i = 1; $i <= 3; $i++) {
            $job = $this->runJob(SendBillingMailJob::KIND_INVOICE, $user->id, BillingDocumentService::STAGE_FIRST, $expiry);
            $this->assertSame(SendBillingMailJob::RESULT_RETRY, $job->result, "第 {$i} 次临时失败交给队列重试");
            $this->assertSame(1, (int) $user->fresh()->mail_failed_count, '一小时内的临时失败只算一次');
            $this->assertNull($user->fresh()->mail_suppressed_at);
        }
        $this->assertSame(3, MailLog::where('user_id', $user->id)->where('status', 0)->count());

        // 不同时段各失败一次（上次计数已过一小时），累计 3 次才标记
        for ($i = 2; $i <= 3; $i++) {
            User::where('id', $user->id)->update(['mail_failed_at' => time() - DeliveryMonitor::TEMPORARY_FAILURE_WINDOW - 60]);
            $job = $this->runJob(SendBillingMailJob::KIND_INVOICE, $user->id, BillingDocumentService::STAGE_FIRST, $expiry);
            $this->assertSame(SendBillingMailJob::RESULT_RETRY, $job->result);
            $this->assertSame($i, (int) $user->fresh()->mail_failed_count);
            $this->assertSame($i >= 3, $user->fresh()->mail_suppressed_at !== null, '不同时段累计 3 次临时失败才标记');
        }
        $this->assertSame(DeliveryMonitor::TEMPORARY, $user->fresh()->mail_suppressed_reason);
        $this->assertSame(5, MailLog::where('user_id', $user->id)->where('status', 0)->count());

        // 已标记且没绑 Telegram：不再碰 SMTP，账单留在面板里等用户自己下载
        $job = $this->runJob(SendBillingMailJob::KIND_INVOICE, $user->id, BillingDocumentService::STAGE_FIRST, $expiry);
        $this->assertSame(SendBillingMailJob::RESULT_SKIPPED, $job->result);
        $this->assertSame(5, MailLog::where('user_id', $user->id)->count());
        $doc = BillingDocument::where('user_id', $user->id)->firstOrFail();
        $this->assertNull($doc->sent_at);
        $this->assertSame(0, (int) $doc->send_count);
        $this->assertNotEmpty($doc->payload, '没送达也记下了，面板里能下载');

        Sanctum::actingAs($user);
        $me = $this->getJson('/api/v1/user/info')->assertStatus(200)->json('data');
        $this->assertNotNull($me['mail_suppressed_at']);
        $this->assertSame(DeliveryMonitor::TEMPORARY, $me['mail_suppressed_reason']);
        $this->assertNull($this->getJson('/api/v1/user/billing/documents')->json('data.0.sent_at'));

        // 邮箱修好后用户点「重新测试」：发成功即自动解除标记；10 分钟内不能再测
        app('mailer')->setSymfonyTransport($arrayTransport);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $result = $this->postJson('/api/v1/user/mail/test')->assertStatus(200)->json('data');
        $this->assertTrue($result['ok']);
        $this->assertCount(1, $this->sent());
        $this->assertStringContainsString('测试', $this->sent()->first()->getOriginalMessage()->getSubject());
        $user->refresh();
        $this->assertNull($user->mail_suppressed_at);
        $this->assertNull($user->mail_suppressed_reason);
        $this->assertSame(0, (int) $user->mail_failed_count);
        $this->postJson('/api/v1/user/mail/test')->assertStatus(400);
    }

    public function test_failed_test_mail_reports_the_category_and_keeps_the_mark(): void
    {
        $this->failMailWith('254 4.7.1 Recipient address suppressed');
        $user = $this->user();
        Sanctum::actingAs($user);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $result = $this->postJson('/api/v1/user/mail/test')->assertStatus(200)->json('data');
        $this->assertFalse($result['ok']);
        $this->assertSame(DeliveryMonitor::SUPPRESSED, $result['category']);
        $this->assertNotSame('', (string) $result['message']);
        $this->assertSame(DeliveryMonitor::SUPPRESSED, $user->fresh()->mail_suppressed_reason);
    }

    public function test_delivery_digest_reaches_admins_and_the_admin_mail_endpoints_work(): void
    {
        config(['v2board.telegram_bot_enable' => 1, 'v2board.telegram_bot_token' => '1:test']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        Bus::fake([SendEmailJob::class, \App\Jobs\SendTelegramJob::class]);
        $admin = $this->user(['is_admin' => 1, 'telegram_id' => 7]);

        // 没有失败就不打扰
        $this->artisan('mail:delivery-digest')->assertExitCode(0);
        Bus::assertNotDispatched(SendEmailJob::class);
        Bus::assertNotDispatched(\App\Jobs\SendTelegramJob::class);

        $victim = $this->user(['mail_suppressed_at' => time() - 60, 'mail_suppressed_reason' => DeliveryMonitor::SUPPRESSED, 'mail_failed_count' => 1]);
        MailLog::create(['email' => $victim->email, 'user_id' => $victim->id, 'subject' => '收据', 'template_name' => 'billing.mail.receipt',
            'error' => '254 4.7.1 Recipient address suppressed', 'status' => 0, 'category' => DeliveryMonitor::SUPPRESSED]);
        MailLog::create(['email' => $admin->email, 'user_id' => $admin->id, 'subject' => '通知', 'template_name' => 'notify', 'error' => null, 'status' => 1]);
        BillingDocument::create(['user_id' => $victim->id, 'kind' => BillingDocument::KIND_RECEIPT, 'doc_no' => 'RC-TEST-1', 'amount' => 100,
            'access_key' => bin2hex(random_bytes(16)), 'payload' => ['doc_no' => 'RC-TEST-1'], 'size' => 1, 'locale' => 'zh-CN', 'send_count' => 0]);

        $this->artisan('mail:delivery-digest')->assertExitCode(0);
        Bus::assertDispatched(\App\Jobs\SendTelegramJob::class, function (\App\Jobs\SendTelegramJob $job) {
            $text = (fn () => $this->text)->call($job);
            return str_contains($text, '收件方抑制名单 1') && str_contains($text, '未绑定 Telegram') && str_contains($text, '1 份收据 / 账单未能投递');
        });
        Bus::assertDispatched(SendEmailJob::class, fn (SendEmailJob $job) => (fn () => $this->params)->call($job)['email'] === $admin->email);
        Bus::assertDispatchedTimes(SendEmailJob::class, 1);

        Sanctum::actingAs($admin);
        $stats = $this->getJson($this->adminPath('/mail/stats'))->assertStatus(200)->json('data');
        $this->assertEquals(1, $stats['last_24h']['failed']);
        $this->assertEquals(1, $stats['last_24h']['sent']);
        $this->assertEquals(1, $stats['last_24h']['by_category']['suppressed']);
        $this->assertEquals(1, $stats['suppressed_users']);
        $this->assertEquals(1, $stats['pending_documents']);
        $this->assertSame($victim->email, $stats['suppressed_recent'][0]['email']);

        $suppressed = $this->postJson($this->adminPath('/mail/suppressed'))->assertStatus(200)->json();
        $this->assertSame(1, $suppressed['total']);
        $this->assertSame((int) $victim->id, $suppressed['data'][0]['id']);
        $this->assertEquals(1, $suppressed['data'][0]['pending_documents']);

        $logs = $this->postJson($this->adminPath('/mail/log/fetch'), ['status' => 0])->assertStatus(200)->json();
        $this->assertSame(1, $logs['total']);
        $this->assertSame($victim->email, $logs['data'][0]['email']);
        $this->assertSame(DeliveryMonitor::SUPPRESSED, $logs['data'][0]['category']);
        $this->assertSame(2, $this->postJson($this->adminPath('/mail/log/fetch'))->json('total'));

        $this->postJson($this->adminPath('/mail/unsuppress'), ['user_id' => $victim->id])->assertStatus(200);
        $this->assertNull($victim->fresh()->mail_suppressed_at);
        $this->assertSame(0, (int) $victim->fresh()->mail_failed_count);
        $this->assertEquals(0, $this->getJson($this->adminPath('/mail/stats'))->json('data.suppressed_users'));
    }

    // ───────────────────────── 余额流水 ─────────────────────────

    public function test_every_balance_change_lands_in_the_ledger_and_is_queryable(): void
    {
        $user = $this->user(['balance' => 0, 'commission_balance' => 3000]);
        $admin = $this->user(['is_admin' => 1]);

        // 后台手工调整：单位元，带操作人与备注
        Sanctum::actingAs($admin);
        $this->postJson($this->adminPath('/billing/balance/adjust'), ['user_id' => $user->id, 'amount' => 50, 'remark' => '补偿'])->assertStatus(200);
        $this->assertSame(5000, (int) $user->fresh()->balance);
        $adjust = BalanceLog::where('user_id', $user->id)->latest('id')->firstOrFail();
        $this->assertSame(BalanceLog::TYPE_ADMIN_ADJUST, $adjust->type);
        $this->assertSame(5000, (int) $adjust->amount);
        $this->assertSame(0, (int) $adjust->balance_before);
        $this->assertSame(5000, (int) $adjust->balance_after);
        $this->assertSame((int) $admin->id, (int) $adjust->operator_id);
        $this->assertSame('补偿', $adjust->remark);
        $this->postJson($this->adminPath('/billing/balance/adjust'), ['user_id' => $user->id, 'amount' => -80])->assertStatus(400);
        $this->postJson($this->adminPath('/billing/balance/adjust'), ['user_id' => $user->id, 'amount' => 0])->assertStatus(422);
        $this->assertSame(5000, (int) $user->fresh()->balance, '扣成负数的调整被拒绝');

        // 佣金划转到余额
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/user/transfer', ['transfer_amount' => 1000])->assertStatus(200);
        $this->assertSame(6000, (int) $user->fresh()->balance);
        $transfer = BalanceLog::where('user_id', $user->id)->where('type', BalanceLog::TYPE_COMMISSION_TRANSFER)->firstOrFail();
        $this->assertSame(1000, (int) $transfer->amount);
        $this->assertSame(5000, (int) $transfer->balance_before);
        $this->assertSame(6000, (int) $transfer->balance_after);

        // 余额支付订单，再取消退回：两条都挂在订单上
        $order = $this->order($user, ['status' => Order::STATUS_PENDING, 'balance_amount' => 2000, 'total_amount' => 0]);
        $this->assertTrue((new UserService())->addBalance($user->id, -2000, BalanceLog::TYPE_ORDER_PAY, BalanceLedger::orderCtx($order, '余额支付')));
        $this->assertSame(4000, (int) $user->fresh()->balance);
        $this->assertTrue((new OrderService($order))->cancel());
        $this->assertSame(6000, (int) $user->fresh()->balance);
        $cancel = BalanceLog::where('user_id', $user->id)->where('type', BalanceLog::TYPE_ORDER_CANCEL)->firstOrFail();
        $this->assertSame(2000, (int) $cancel->amount);
        $this->assertSame((int) $order->id, (int) $cancel->order_id);
        $this->assertSame($order->trade_no, $cancel->ref_id);
        $this->assertSame(4000, (int) $cancel->balance_before);
        $this->assertSame(6000, (int) $cancel->balance_after);

        // 余额不足的扣减不记流水
        $this->assertFalse((new UserService())->addBalance($user->id, -999999, BalanceLog::TYPE_ORDER_PAY));
        $this->assertSame(4, BalanceLog::where('user_id', $user->id)->count());

        // 用户端：分页、带当前余额、订单号
        $page = $this->getJson('/api/v1/user/balance/log?pageSize=2')->assertStatus(200)->json();
        $this->assertSame(4, $page['total']);
        $this->assertSame(6000, $page['balance']);
        $this->assertCount(2, $page['data']);
        $this->assertSame(BalanceLog::TYPE_ORDER_CANCEL, $page['data'][0]['type']);
        $this->assertSame($order->trade_no, $page['data'][0]['order_trade_no']);
        $this->assertSame(BalanceLog::TYPE_ORDER_PAY, $page['data'][1]['type']);
        $this->assertCount(2, $this->getJson('/api/v1/user/balance/log?page=2&pageSize=2')->json('data'));
        $this->assertArrayNotHasKey('operator_id', $page['data'][0], '操作人不下发给用户');

        // 后台按用户查，含变动前后快照和操作人
        Sanctum::actingAs($admin);
        $adminPage = $this->postJson($this->adminPath('/billing/balance/log'), ['user_id' => $user->id])->assertStatus(200)->json();
        $this->assertSame(4, $adminPage['total']);
        $this->assertSame((int) $admin->id, $adminPage['data'][3]['operator_id']);
        $this->assertSame(0, $adminPage['data'][3]['balance_before']);
        $this->postJson($this->adminPath('/billing/balance/log'))->assertStatus(422);
    }

    // ───────────────────────── 到期之后 ─────────────────────────

    public function test_expired_notice_goes_out_once_within_two_days_of_expiry(): void
    {
        Bus::fake([SendBillingMailJob::class, SendEmailJob::class]);
        $justExpired = $this->user(['expired_at' => time() - 3600]);
        $longExpired = $this->user(['expired_at' => time() - 5 * 86400]);   // 错过了 2 天窗口，不补发「已暂停」
        $noPlan = $this->user(['expired_at' => time() - 3600, 'plan_id' => null]);
        $optedOut = $this->user(['expired_at' => time() - 3600, 'remind_expire' => 0, 'remind_traffic' => 1]);

        $stats = (new MailService())->processUsersInChunks(100);
        $this->assertSame(1, $stats['expired_emails']);
        $this->assertSame(0, $stats['winback_emails']);
        Bus::assertDispatched(SendBillingMailJob::class, fn (SendBillingMailJob $job) => $job->kind === SendBillingMailJob::KIND_EXPIRED
            && $job->id === (int) $justExpired->id && $job->stage === '1' && $job->expiry === (int) $justExpired->expired_at);
        $this->assertSame(1, (int) $justExpired->fresh()->lifecycle_stage);
        $this->assertSame((int) $justExpired->expired_at, (int) $justExpired->fresh()->lifecycle_expiry);
        foreach ([$longExpired, $noPlan, $optedOut] as $other) {
            $this->assertSame(0, (int) $other->fresh()->lifecycle_stage);
        }

        $this->assertSame(0, (new MailService())->processUsersInChunks(100)['expired_emails'], '同一到期日只发一次');
        Bus::assertDispatchedTimes(SendBillingMailJob::class, 1);

        // 续费后 expired_at 变了，序列从头开始：下个周期到期再发
        $justExpired->update(['expired_at' => time() + 30 * 86400]);
        $this->assertSame(0, (new MailService())->processUsersInChunks(100)['expired_emails']);
        $justExpired->update(['expired_at' => time() - 7200]);
        $this->assertSame(1, (new MailService())->processUsersInChunks(100)['expired_emails']);
    }

    public function test_winback_mails_follow_the_configured_days(): void
    {
        config(['v2board.billing_winback_days' => '7,30']);
        Bus::fake([SendBillingMailJob::class, SendEmailJob::class]);
        $week = $this->user(['expired_at' => time() - 7 * 86400 - 3600]);
        $month = $this->user(['expired_at' => time() - 30 * 86400 - 3600]);
        $tooLate = $this->user(['expired_at' => time() - 40 * 86400]);   // 两个窗口都过了：不补发

        $stats = (new MailService())->processUsersInChunks(100);
        $this->assertSame(2, $stats['winback_emails']);
        $this->assertSame(0, $stats['expired_emails']);
        Bus::assertDispatched(SendBillingMailJob::class, fn (SendBillingMailJob $job) => $job->kind === SendBillingMailJob::KIND_WINBACK && $job->id === (int) $week->id && $job->stage === '2');
        Bus::assertDispatched(SendBillingMailJob::class, fn (SendBillingMailJob $job) => $job->kind === SendBillingMailJob::KIND_WINBACK && $job->id === (int) $month->id && $job->stage === '3');
        $this->assertSame(2, (int) $week->fresh()->lifecycle_stage);
        $this->assertSame(3, (int) $month->fresh()->lifecycle_stage);
        $this->assertSame(0, (int) $tooLate->fresh()->lifecycle_stage);

        $this->assertSame(0, (new MailService())->processUsersInChunks(100)['winback_emails']);
        Bus::assertDispatchedTimes(SendBillingMailJob::class, 2);

        // 第 7 天那位到了第 30 天再收一封；档位只往前走
        $week->update(['expired_at' => time() - 30 * 86400 - 3600, 'lifecycle_expiry' => time() - 30 * 86400 - 3600, 'lifecycle_stage' => 2]);
        $this->assertSame(1, (new MailService())->processUsersInChunks(100)['winback_emails']);
        $this->assertSame(3, (int) $week->fresh()->lifecycle_stage);
    }

    public function test_expired_notice_waits_for_auto_renew_and_honours_the_switches(): void
    {
        Bus::fake([SendBillingMailJob::class, SendEmailJob::class]);
        $covered = $this->user(['expired_at' => time() - 3600, 'auto_renew' => 1, 'balance' => 5000]);
        $broke = $this->user(['expired_at' => time() - 3600, 'auto_renew' => 1, 'balance' => 100]);

        $stats = (new MailService())->processUsersInChunks(100);
        $this->assertSame(1, $stats['expired_emails']);
        Bus::assertDispatched(SendBillingMailJob::class, fn (SendBillingMailJob $job) => $job->id === (int) $broke->id);
        $this->assertSame(0, (int) $covered->fresh()->lifecycle_stage, '余额够、宽限期内：renew:auto 马上会续上，不发「已暂停」');

        config(['v2board.billing_expired_enable' => 0]);
        $this->user(['expired_at' => time() - 3600]);
        $this->assertSame(0, (new MailService())->processUsersInChunks(100)['expired_emails']);

        config(['v2board.billing_winback_enable' => 0]);
        $this->user(['expired_at' => time() - 7 * 86400 - 3600]);
        $this->assertSame(0, (new MailService())->processUsersInChunks(100)['winback_emails']);
        Bus::assertDispatchedTimes(SendBillingMailJob::class, 1);
    }

    public function test_expired_mail_renders_the_renewal_offer_and_links_the_archived_invoice(): void
    {
        $user = $this->user(['expired_at' => time() - 3600]);
        $expiry = (int) $user->expired_at;
        $doc = BillingDocument::create(['user_id' => $user->id, 'kind' => BillingDocument::KIND_INVOICE, 'stage' => 'final', 'expired_at' => $expiry,
            'doc_no' => 'INV-TEST-1', 'amount' => 2000, 'access_key' => bin2hex(random_bytes(16)), 'payload' => ['doc_no' => 'INV-TEST-1'], 'size' => 1, 'locale' => 'zh-CN']);

        $job = $this->runJob(SendBillingMailJob::KIND_EXPIRED, $user->id, '1', $expiry);
        $this->assertSame(SendBillingMailJob::RESULT_EMAIL, $job->result);
        $this->assertCount(1, $this->sent());
        $message = $this->sent()->first()->getOriginalMessage();
        $this->assertStringContainsString('静态家宽拼车', $message->getSubject());
        $html = $message->getHtmlBody();
        $this->assertStringContainsString('¥20.00', $html, '按原配置续费的金额');
        $this->assertStringContainsString('/pay/' . $doc->payToken(), $html, '这期账单还在宽限期内：按钮去免登录付款页');
        $this->assertStringContainsString('/billing', $html, '链接到归档的账单');
        $this->assertCount(0, $message->getAttachments(), '到期后的通知不再附 PDF');
        $this->assertSame(0, BillingDocument::where('user_id', $user->id)->where('kind', BillingDocument::KIND_RECEIPT)->count());

        // 派发后续了费：作废
        $this->runJob(SendBillingMailJob::KIND_EXPIRED, $user->id, '1', $expiry - 1);
        $user->update(['expired_at' => time() + 30 * 86400]);
        $this->runJob(SendBillingMailJob::KIND_EXPIRED, $user->id, '1', $expiry);
        $this->assertCount(1, $this->sent());
    }

    public function test_winback_mail_carries_the_configured_coupon_only_while_it_is_valid(): void
    {
        $user = $this->user(['expired_at' => time() - 7 * 86400 - 3600]);
        $expiry = (int) $user->expired_at;
        Coupon::create(['code' => 'BACK10', 'name' => '回归优惠', 'type' => 2, 'value' => 10, 'show' => 0,
            'started_at' => time() - 86400, 'ended_at' => time() + 86400]);

        $this->runJob(SendBillingMailJob::KIND_WINBACK, $user->id, '2', $expiry);
        $plain = $this->sent()->first()->getOriginalMessage();
        $this->assertStringNotContainsString('BACK10', $plain->getHtmlBody(), '没配置优惠券时只推荐套餐');
        $this->assertStringContainsString('/plans', $plain->getHtmlBody());

        config(['v2board.billing_winback_coupon' => 'BACK10']);
        $this->runJob(SendBillingMailJob::KIND_WINBACK, $user->id, '2', $expiry);
        $message = $this->sent()->last()->getOriginalMessage();
        $html = $message->getHtmlBody();
        $this->assertStringContainsString('BACK10', $html);
        $this->assertStringContainsString('减 10%', $html);
        $this->assertStringContainsString('/plans?coupon=BACK10', $html);
        $this->assertStringContainsString('减 10%', $message->getSubject());
        $this->assertCount(0, $message->getAttachments());

        // 优惠券过期 / 用完就不再带
        Coupon::where('code', 'BACK10')->update(['ended_at' => time() - 60]);
        $this->runJob(SendBillingMailJob::KIND_WINBACK, $user->id, '2', $expiry);
        $this->assertStringNotContainsString('BACK10', $this->sent()->last()->getOriginalMessage()->getHtmlBody());
        $this->assertCount(3, $this->sent());

        // 被标记暂停投递且没有 Telegram 的用户：挽回邮件直接跳过
        $user->update(['mail_suppressed_at' => time(), 'mail_suppressed_reason' => DeliveryMonitor::BOUNCE]);
        $job = $this->runJob(SendBillingMailJob::KIND_WINBACK, $user->id, '2', $expiry);
        $this->assertSame(SendBillingMailJob::RESULT_SKIPPED, $job->result);
        $this->assertCount(3, $this->sent());
    }
}
