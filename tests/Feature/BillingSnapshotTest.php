<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\ConfigSave;
use App\Jobs\SendBillingMailJob;
use App\Models\BillingDocument;
use App\Models\MailLog;
use App\Models\Order;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\Billing\BillingArchive;
use App\Services\Billing\BillingDocumentService;
use App\Services\OrderService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 收据 / 账单只存内容快照、下载时现生成 PDF：事后改邮箱 / 套餐 / 站点信息不影响已开出的单据，
 * 续费后账单仍可下载；账单记录默认永久保留、可按天数清理；邮件日志按保留期清理；后台统计与设置。
 */
class BillingSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1073741824;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
        // Setting 与订阅模板缓存都写死 Cache::store('redis')，而 CI 没起 Redis：桩成内存驱动，/config/fetch 才不会 500
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        // 启动时已按真 Redis 建好的 Setting 实例丢掉：本机开着 Redis 时也不会读到开发库缓存的站点设置
        app()->forgetScopedInstances();
        config(['v2board.app_name' => '苏菲家宽', 'v2board.app_url' => 'https://old.example.test']);
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

    /** 下单并开通：收据随即开出 */
    private function receipt(User $user): BillingDocument
    {
        $order = Order::create([
            'user_id' => $user->id, 'plan_id' => $this->plan->id, 'period' => 'monthly', 'type' => Order::TYPE_NEW_PURCHASE,
            'trade_no' => Helper::generateOrderNo(), 'total_amount' => 2000, 'status' => Order::STATUS_PROCESSING, 'paid_at' => time(),
        ]);
        (new OrderService($order))->open();
        return BillingDocument::where('order_id', $order->id)->firstOrFail();
    }

    /** 给即将到期的用户发首张账单 */
    private function invoice(User $user): BillingDocument
    {
        $job = new SendBillingMailJob(SendBillingMailJob::KIND_INVOICE, $user->id, BillingDocumentService::STAGE_FIRST, (int) $user->expired_at);
        $job->handle(app(BillingDocumentService::class), app(BillingArchive::class));
        return BillingDocument::where('user_id', $user->id)->where('kind', BillingDocument::KIND_INVOICE)->firstOrFail();
    }

    private function adminPath(string $path): string
    {
        return '/api/v2/' . admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))) . $path;
    }

    private function download(BillingDocument $doc)
    {
        return $this->get("/api/v1/guest/billing/document/{$doc->id}/{$doc->access_key}");
    }

    /** 把 PDF 渲染换成「渲染时的语言 + 渲染数据」的明文，直接断言 PDF 里会印出什么 */
    private function fakePdf(): void
    {
        $this->partialMock(BillingDocumentService::class, function ($mock) {
            $mock->shouldReceive('pdf')->andReturnUsing(
                fn (array $data) => '%PDF-FAKE ' . App::getLocale() . "\n" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        });
    }

    // ───────────────────────── 快照 ─────────────────────────

    public function test_receipt_keeps_what_was_issued_after_email_plan_and_site_changes(): void
    {
        $user = $this->user(['email' => 'old@example.test']);
        $doc = $this->receipt($user);
        $until = $doc->payload['items'][0]['until'];

        // 存的是不转义中文的 JSON，size 就是库里的字节数，logo 不进快照
        $raw = (string) DB::table('v2_billing_document')->where('id', $doc->id)->value('payload');
        $this->assertStringContainsString('静态家宽拼车', $raw);
        $this->assertSame(strlen($raw), (int) $doc->size);
        $this->assertLessThan(4096, strlen($raw), '一份快照只有一两 KB');
        $this->assertArrayNotHasKey('logo_data', $doc->payload);
        $this->assertArrayNotHasKey('logo_url', $doc->payload);
        $this->assertSame(0, count(Storage::disk('local')->allFiles()), '不落 PDF 文件');

        // 快照经过一次 JSON 往返照样能渲染出真 PDF
        $this->assertStringStartsWith('%PDF', $this->download($doc)->assertStatus(200)->getContent());

        // 之后改了邮箱、套餐名、站点名和域名，用户也续了费
        $user->update(['email' => 'new@example.test', 'expired_at' => (int) $user->expired_at + 90 * 86400]);
        $this->plan->update(['name' => '旗舰家宽']);
        config(['v2board.app_name' => '新站名', 'v2board.app_url' => 'https://new.example.test']);

        $this->fakePdf();
        $pdf = $this->download($doc)->assertStatus(200)->getContent();
        $this->assertStringContainsString('old@example.test', $pdf, '账单寄往：开具时的邮箱');
        $this->assertStringContainsString('静态家宽拼车', $pdf, '开具时的套餐名');
        $this->assertStringContainsString('苏菲家宽', $pdf, '开具时的站点名');
        $this->assertStringContainsString('"until":"' . $until . '"', $pdf, '开具时的服务期');
        $this->assertStringNotContainsString('new@example.test', $pdf);
        $this->assertStringNotContainsString('旗舰家宽', $pdf);
        $this->assertStringNotContainsString('新站名', $pdf);
        // 站内链接跟着当前域名走，旧域名不再出现
        $this->assertStringContainsString('https://new.example.test/dashboard', $pdf);
        $this->assertStringNotContainsString('old.example.test', $pdf);
        $this->assertSame($raw, (string) DB::table('v2_billing_document')->where('id', $doc->id)->value('payload'), '下载不改快照');
    }

    public function test_snapshot_is_rendered_in_the_language_it_was_issued_in(): void
    {
        config(['v2board.billing_locale' => 'en-US']);
        $doc = $this->receipt($this->user());
        $this->assertSame('en-US', $doc->locale);
        $this->assertSame('RECEIPT', strtoupper((string) $doc->payload['doc_title']));

        config(['v2board.billing_locale' => 'zh-CN']);
        $before = App::getLocale();
        $this->fakePdf();
        $pdf = $this->download($doc)->assertStatus(200)->getContent();
        $this->assertStringStartsWith('%PDF-FAKE en-US', $pdf, '表头标签用开具时的语言，和快照里的文案对得上');
        $this->assertSame($before, App::getLocale(), '渲染完恢复原语言');
    }

    public function test_admin_resend_sends_the_issued_receipt_to_the_current_address(): void
    {
        $user = $this->user(['email' => 'old@example.test']);
        $doc = $this->receipt($user);
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());

        $user->update(['email' => 'new@example.test']);
        $this->plan->update(['name' => '旗舰家宽']);
        $this->fakePdf();

        Sanctum::actingAs($this->user(['is_admin' => 1]));
        $this->postJson($this->adminPath('/billing/document/resend'), ['id' => $doc->id])->assertStatus(200);

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(2, $messages);
        $email = $messages->last()->getOriginalMessage();
        $this->assertSame('new@example.test', $email->getTo()[0]->getAddress(), '发到用户现在的邮箱');
        $this->assertStringContainsString('静态家宽拼车', $email->getHtmlBody(), '正文照开具时的内容');
        $attachment = $email->getAttachments()[0]->getBody();
        $this->assertStringContainsString('静态家宽拼车', $attachment);
        $this->assertStringContainsString('old@example.test', $attachment, '收据本身不改');
        $this->assertStringNotContainsString('旗舰家宽', $attachment);

        $fresh = $doc->fresh();
        $this->assertSame(1, BillingDocument::count(), '没有另开一张');
        $this->assertSame($doc->doc_no, $fresh->doc_no);
        $this->assertSame($doc->payload, $fresh->payload);
        $this->assertSame(2, (int) $fresh->send_count);
    }

    public function test_invoice_stays_downloadable_after_renewal_and_after_it_lapses(): void
    {
        $renewed = $this->user(['expired_at' => time() + 5 * 86400]);
        $invoice = $this->invoice($renewed);
        $this->assertSame(2000, (int) $invoice->amount);
        $renewed->update(['expired_at' => (int) $renewed->expired_at + 30 * 86400]);

        $this->assertStringStartsWith('%PDF', $this->download($invoice)->assertStatus(200)->getContent(), '续费后照样能下载当初那张');
        Sanctum::actingAs($renewed);
        $this->assertSame('settled', $this->getJson('/api/v1/user/billing/documents')->json('data.0.status'));

        // 到期 40 天仍没续费：已失效，但还在列表里、还能下载
        $lapsed = $this->user(['expired_at' => time() + 5 * 86400]);
        $old = $this->invoice($lapsed);
        $gone = time() - 40 * 86400;
        BillingDocument::where('id', $old->id)->update(['expired_at' => $gone]);
        $lapsed->update(['expired_at' => $gone]);
        $this->download($old)->assertStatus(200);
        Sanctum::actingAs($lapsed);
        $this->assertSame('void', $this->getJson('/api/v1/user/billing/documents')->json('data.0.status'));

        // 内容照开具时，状态跟着现状走：已续费 / 已失效的盖对应的章、不再催付款
        $open = $this->invoice($this->user(['expired_at' => time() + 5 * 86400]));
        $this->fakePdf();
        $renewedPdf = $this->download($invoice)->getContent();
        $this->assertStringContainsString('"stamp":"已续费"', $renewedPdf);
        $this->assertStringContainsString('"closed_note":"这个周期已经续费', $renewedPdf);
        $this->assertStringContainsString('"alternatives":[]', $renewedPdf, '开具时的套餐推荐不再带');
        $this->assertStringContainsString('"balance_due":2000', $renewedPdf, '金额照开具时');
        $voidPdf = $this->download($old)->getContent();
        $this->assertStringContainsString('"stamp":"已失效"', $voidPdf);
        $this->assertStringContainsString('"alternatives":[]', $voidPdf);
        $openPdf = $this->download($open)->getContent();
        $this->assertStringContainsString('"stamp":"待付款"', $openPdf);
        $this->assertStringNotContainsString('closed_note', $openPdf);
        $this->assertSame('待付款', $invoice->fresh()->payload['stamp'], '快照本身不改');

        // 没有快照的老账单（升级前开的）续费后重建不出来：404
        BillingDocument::where('id', $invoice->id)->update(['payload' => null, 'size' => 0]);
        $this->download($invoice)->assertStatus(404);
    }

    public function test_downloads_are_rate_limited_per_ip(): void
    {
        $doc = $this->receipt($this->user());
        $this->fakePdf();
        for ($i = 0; $i < 30; $i++) {
            $this->download($doc)->assertStatus(200);
        }
        $this->download($doc)->assertStatus(429);
    }

    // ───────────────────────── 保留 ─────────────────────────

    public function test_invoices_are_kept_forever_by_default_and_pruned_only_when_settled_or_void(): void
    {
        $oldExpiry = time() - 100 * 86400;

        // 已续费：账单的到期日早已过去，用户现在的到期日在后面
        $settledUser = $this->user(['expired_at' => time() + 5 * 86400]);
        $settled = $this->invoice($settledUser);
        BillingDocument::where('id', $settled->id)->update(['expired_at' => $oldExpiry]);
        $settledUser->update(['expired_at' => time() + 30 * 86400]);

        // 已失效：到期 100 天仍没续费
        $voidUser = $this->user(['expired_at' => time() + 5 * 86400]);
        $void = $this->invoice($voidUser);
        BillingDocument::where('id', $void->id)->update(['expired_at' => $oldExpiry]);
        $voidUser->update(['expired_at' => $oldExpiry]);

        // 待付款：到期 10 天、还在 30 天的付款窗口里（保留期设得比 30 天短时才会撞上）
        $openUser = $this->user(['expired_at' => time() + 5 * 86400]);
        $open = $this->invoice($openUser);
        BillingDocument::where('id', $open->id)->update(['expired_at' => time() - 10 * 86400]);
        $openUser->update(['expired_at' => time() - 10 * 86400]);

        // 当期账单，和一张十年前的收据
        $current = $this->invoice($this->user(['expired_at' => time() + 5 * 86400]));
        $receipt = $this->receipt($this->user());
        BillingDocument::where('id', $receipt->id)->update(['created_at' => time() - 3650 * 86400]);

        // 默认 0：什么都不删
        $this->artisan('billing:prune-documents')->expectsOutputToContain('账单记录永久保留')->assertExitCode(0);
        $this->assertSame(5, BillingDocument::count());

        config(['v2board.billing_invoice_retention_days' => 7]);
        $this->artisan('billing:prune-documents', ['--dry-run' => true])->expectsOutputToContain('[dry-run] 旧账单 2')->assertExitCode(0);
        $this->assertSame(5, BillingDocument::count());

        $this->artisan('billing:prune-documents')
            ->expectsOutputToContain('旧账单 2（到期后保留 7 天，待付款跳过 1）')
            ->assertExitCode(0);
        $this->assertNull($settled->fresh(), '已结清的旧账单删除');
        $this->assertNull($void->fresh(), '已失效的旧账单删除');
        $this->assertNotNull($open->fresh(), '待付款的账单保留');
        $this->assertNotNull($current->fresh(), '当期账单保留');
        $this->assertNotNull($receipt->fresh(), '收据始终保留');

        config(['v2board.billing_invoice_retention_days' => 90]);
        $this->artisan('billing:prune-documents')->expectsOutputToContain('旧账单 0')->assertExitCode(0);
        $this->assertSame(3, BillingDocument::count());

        // 越界的设置值被夹住
        config(['v2board.billing_invoice_retention_days' => 99999]);
        $this->assertSame(3650, BillingDocumentService::invoiceRetentionDays());
        config(['v2board.billing_invoice_retention_days' => -5]);
        $this->assertSame(0, BillingDocumentService::invoiceRetentionDays());
    }

    public function test_reset_log_prunes_mail_logs_by_retention(): void
    {
        $make = fn (int $age) => MailLog::create([
            'email' => 'a@example.test', 'subject' => '提醒', 'template_name' => 'remindExpire', 'error' => null, 'status' => 1,
            'created_at' => time() - $age * 86400, 'updated_at' => time() - $age * 86400,
        ]);
        $old = $make(200);
        $recent = $make(10);

        $this->artisan('reset:log')->assertExitCode(0);
        $this->assertNull($old->fresh(), '超过 180 天的投递日志删除');
        $this->assertNotNull($recent->fresh());

        config(['v2board.mail_log_retention_days' => 0]);
        $old = $make(200);
        $this->artisan('reset:log')->assertExitCode(0);
        $this->assertNotNull($old->fresh(), '0 = 永久保留');

        config(['v2board.mail_log_retention_days' => 5]);
        $this->artisan('reset:log')->assertExitCode(0);
        $this->assertNull($recent->fresh());
        $this->assertNull($old->fresh());
    }

    // ───────────────────────── 后台 ─────────────────────────

    public function test_admin_sees_counts_and_snapshot_size_and_the_file_storage_settings_are_gone(): void
    {
        $a = $this->receipt($this->user());
        $b = $this->receipt($this->user());
        $c = $this->invoice($this->user(['expired_at' => time() + 5 * 86400]));

        Sanctum::actingAs($this->user(['is_admin' => 1]));
        $json = $this->postJson($this->adminPath('/billing/document/fetch'))->assertStatus(200)->json();
        $this->assertSame([
            'total' => 3,
            'receipts' => 2,
            'invoices' => 1,
            'bytes' => (int) $a->size + (int) $b->size + (int) $c->size,
            'invoice_retention_days' => 0,
        ], $json['summary']);
        foreach (['size', 'disk', 'path', 'payload'] as $key) {
            $this->assertArrayNotHasKey($key, $json['data'][0]);
        }

        // 设置：只剩账单记录与投递日志的保留天数
        $email = $this->getJson($this->adminPath('/config/fetch?key=email'))->assertStatus(200)->json('data.email');
        $this->assertSame(0, $email['billing_invoice_retention_days']);
        $this->assertSame(180, $email['mail_log_retention_days']);
        foreach (['billing_storage_driver', 'billing_s3_bucket', 'billing_s3_secret_key', 'billing_receipt_retention_days'] as $key) {
            $this->assertArrayNotHasKey($key, $email);
        }
        config(['v2board.billing_invoice_retention_days' => 30, 'v2board.mail_log_retention_days' => 90]);
        $email = $this->getJson($this->adminPath('/config/fetch?key=email'))->assertStatus(200)->json('data.email');
        $this->assertSame(30, $email['billing_invoice_retention_days']);
        $this->assertSame(90, $email['mail_log_retention_days']);

        // 保存时的校验（config/save 会写 Redis 缓存，这里直接拿规则验）
        $rules = (new ConfigSave())->rules();
        $this->assertFalse(Validator::make(['billing_invoice_retention_days' => 365, 'mail_log_retention_days' => 0], $rules)->fails());
        $this->assertTrue(Validator::make(['billing_invoice_retention_days' => 99999], $rules)->fails());
        $this->assertTrue(Validator::make(['mail_log_retention_days' => -1], $rules)->fails());
        foreach (array_keys($rules) as $key) {
            $this->assertDoesNotMatchRegularExpression('/^billing_(s3_|storage_|receipt_retention)/', $key);
        }

        $this->postJson($this->adminPath('/config/testBillingStorage'))->assertStatus(404);
    }
}
