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
use App\Services\ObjectStorage\S3ObjectClient;
use App\Services\OrderService;
use App\Utils\Helper;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 归档的存储位置（本地 / S3 兼容对象存储）与保留期清理：收据过期只删文件可重建、旧账单连记录删、
 * 邮件日志按保留期清、对象存储写失败退回本地、后台的存储测试 / 搬迁 / 占用统计。
 */
class BillingStorageRetentionTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1073741824;
    private const S3 = [
        'v2board.billing_storage_driver' => 's3',
        'v2board.billing_s3_endpoint' => 'https://acct.r2.cloudflarestorage.com',
        'v2board.billing_s3_region' => 'auto',
        'v2board.billing_s3_bucket' => 'xb-billing',
        'v2board.billing_s3_access_key' => 'AKIAEXAMPLE',
        'v2board.billing_s3_secret_key' => 'secret',
        'v2board.billing_s3_path_style' => 1,
        'v2board.billing_s3_prefix' => 'billing/documents',
    ];

    private Plan $plan;
    private MockHandler $s3;
    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface, response: ?\Psr\Http\Message\ResponseInterface}> */
    private array $s3History = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
        // Setting 与订阅模板缓存都写死 Cache::store('redis')，而 CI 的 tests / mysql-upgrade 都没起 Redis：
        // 把 redis 缓存桩成内存驱动，/config/fetch 读回才不会 500（也顺带避免设置缓存在用例间串台）
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        $group = new ServerGroup();
        $group->name = '基础';
        $group->save();
        $this->plan = Plan::create([
            'name' => '静态家宽拼车', 'group_id' => $group->id, 'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => null, 'reset_traffic_method' => 1,
            'prices' => ['monthly' => 20, 'quarterly' => 57],
        ]);

        // 所有 S3 请求都打到这个 MockHandler，没排队响应就直接报错（不会有真实网络请求）
        $this->s3 = new MockHandler();
        $stack = HandlerStack::create($this->s3);
        $stack->push(Middleware::history($this->s3History));
        app()->instance(S3ObjectClient::HTTP_BINDING, new Client(['handler' => $stack, 'http_errors' => false]));
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

    /** 下单并开通：收据立刻归档 */
    private function receipt(User $user): BillingDocument
    {
        $order = Order::create([
            'user_id' => $user->id, 'plan_id' => $this->plan->id, 'period' => 'monthly', 'type' => Order::TYPE_NEW_PURCHASE,
            'trade_no' => Helper::generateOrderNo(), 'total_amount' => 2000, 'status' => Order::STATUS_PROCESSING, 'paid_at' => time(),
        ]);
        (new OrderService($order))->open();
        return BillingDocument::where('order_id', $order->id)->firstOrFail();
    }

    /** 给到期前 5 天的用户发首张账单：账单归档 */
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

    private function lastRequest(): \Psr\Http\Message\RequestInterface
    {
        return end($this->s3History)['request'];
    }

    // ───────────────────────── 保留期清理 ─────────────────────────

    public function test_old_receipt_files_are_pruned_but_records_stay_and_rebuild_on_download(): void
    {
        $user = $this->user();
        $doc = $this->receipt($user);
        Storage::disk('local')->assertExists($doc->path);
        $this->assertSame(BillingDocument::DISK_LOCAL, $doc->disk);

        // 没过保留期：不动
        $this->artisan('billing:prune-documents')->expectsOutputToContain('收据文件 0')->assertExitCode(0);
        Storage::disk('local')->assertExists($doc->path);

        // 过了 365 天：dry-run 只数不删，真跑只删文件、记录留着
        BillingDocument::where('id', $doc->id)->update(['created_at' => time() - 400 * 86400]);
        $this->artisan('billing:prune-documents', ['--dry-run' => true])->expectsOutputToContain('[dry-run] 收据文件 1')->assertExitCode(0);
        Storage::disk('local')->assertExists($doc->path);
        $this->artisan('billing:prune-documents')->expectsOutputToContain('收据文件 1（保留 365 天）')->assertExitCode(0);
        Storage::disk('local')->assertMissing($doc->path);
        $pruned = $doc->fresh();
        $this->assertNotNull($pruned, '收据记录保留');
        $this->assertSame(0, (int) $pruned->size);

        // 面板里照常列出，下载时按订单重建并补回文件
        Sanctum::actingAs($user);
        $list = $this->getJson('/api/v1/user/billing/documents')->assertStatus(200)->json('data');
        $this->assertCount(1, $list);
        $resp = $this->download($doc)->assertStatus(200);
        $this->assertStringStartsWith('%PDF', $resp->getContent());
        Storage::disk('local')->assertExists($doc->path);
        $this->assertSame(strlen($resp->getContent()), (int) $doc->fresh()->size);

        // 重建出来的文件下一轮照样会被清；保留期设 0 就永久保留
        $this->artisan('billing:prune-documents')->expectsOutputToContain('收据文件 1')->assertExitCode(0);
        $this->assertSame(0, (int) $doc->fresh()->size);
        $this->download($doc)->assertStatus(200);
        config(['v2board.billing_receipt_retention_days' => 0]);
        $this->artisan('billing:prune-documents')->expectsOutputToContain('保留 永久')->assertExitCode(0);
        Storage::disk('local')->assertExists($doc->path);
        $this->assertGreaterThan(0, (int) $doc->fresh()->size);
    }

    public function test_settled_and_void_invoices_are_pruned_after_the_period_but_open_ones_stay(): void
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

        // 当期：到期日在未来
        $current = $this->invoice($this->user(['expired_at' => time() + 5 * 86400]));

        config(['v2board.billing_invoice_retention_days' => 7]);
        $this->artisan('billing:prune-documents', ['--dry-run' => true])->expectsOutputToContain('旧账单 2')->assertExitCode(0);
        $this->assertSame(4, BillingDocument::count());

        $this->artisan('billing:prune-documents')
            ->expectsOutputToContain('旧账单 2（周期结束后保留 7 天，待付款跳过 1）')
            ->assertExitCode(0);
        $this->assertNull($settled->fresh(), '已结清的旧账单连记录删除');
        $this->assertNull($void->fresh(), '已失效的旧账单连记录删除');
        Storage::disk('local')->assertMissing($settled->path);
        Storage::disk('local')->assertMissing($void->path);
        $this->assertNotNull($open->fresh(), '待付款的账单保留');
        $this->assertNotNull($current->fresh(), '当期账单保留');
        Storage::disk('local')->assertExists($open->path);

        // 默认 90 天：10 天前到期的那张还远没到期限
        config(['v2board.billing_invoice_retention_days' => 90]);
        $this->artisan('billing:prune-documents')->expectsOutputToContain('旧账单 0')->assertExitCode(0);
        $this->assertSame(2, BillingDocument::count());
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

    // ───────────────────────── 对象存储 ─────────────────────────

    public function test_documents_go_to_s3_when_configured_and_are_read_back_from_it(): void
    {
        config(self::S3);
        $user = $this->user();

        $this->s3->append(new Response(200));
        $doc = $this->receipt($user);
        $this->assertSame(BillingDocument::DISK_S3, $doc->disk);
        $this->assertSame("billing/documents/{$user->id}/{$doc->doc_no}.pdf", $doc->path);
        $this->assertSame(0, count(Storage::disk('local')->allFiles()), '本地盘上没有文件');
        $put = $this->lastRequest();
        $this->assertSame('PUT', $put->getMethod());
        $this->assertSame("https://acct.r2.cloudflarestorage.com/xb-billing/billing/documents/{$user->id}/{$doc->doc_no}.pdf", (string) $put->getUri());
        $this->assertStringStartsWith('AWS4-HMAC-SHA256 Credential=AKIAEXAMPLE/', $put->getHeaderLine('Authorization'));
        $this->assertSame('application/pdf', $put->getHeaderLine('Content-Type'));
        $pdf = (string) $put->getBody();
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame(strlen($pdf), (int) $doc->size);

        // 下载从对象存储读，不重渲染
        $this->s3->append(new Response(200, ['Content-Type' => 'application/pdf'], $pdf));
        $resp = $this->download($doc)->assertStatus(200);
        $this->assertSame($pdf, $resp->getContent());
        $this->assertSame('GET', $this->lastRequest()->getMethod());

        // 保留期清理删对象存储里的文件；之后下载重建并写回对象存储
        BillingDocument::where('id', $doc->id)->update(['created_at' => time() - 400 * 86400]);
        $this->s3->append(new Response(204));
        $this->artisan('billing:prune-documents')->expectsOutputToContain('收据文件 1')->assertExitCode(0);
        $this->assertSame('DELETE', $this->lastRequest()->getMethod());
        $this->assertSame((string) $put->getUri(), (string) $this->lastRequest()->getUri());
        $this->assertSame(0, (int) $doc->fresh()->size);

        $this->s3->append(new Response(200));
        $this->download($doc)->assertStatus(200);
        $this->assertSame('PUT', $this->lastRequest()->getMethod());
        $this->assertSame(BillingDocument::DISK_S3, $doc->fresh()->disk);
        $this->assertGreaterThan(0, (int) $doc->fresh()->size);

        // 对象存储里的文件丢了（404）：同样按订单重建
        BillingDocument::where('id', $doc->id)->update(['created_at' => time()]);
        $this->s3->append(new Response(404, [], '<Error><Code>NoSuchKey</Code></Error>'));
        $this->s3->append(new Response(200));
        $this->download($doc)->assertStatus(200);
        $this->assertSame('PUT', $this->lastRequest()->getMethod());
    }

    public function test_s3_write_failure_falls_back_to_local_so_the_mail_still_goes_out(): void
    {
        config(self::S3);
        $user = $this->user();

        $this->s3->append(new Response(500, [], '<Error><Code>InternalError</Code><Message>boom</Message></Error>'));
        $doc = $this->receipt($user);
        $this->assertSame(BillingDocument::DISK_LOCAL, $doc->disk);
        $this->assertSame("billing/documents/{$user->id}/{$doc->doc_no}.pdf", $doc->path);
        Storage::disk('local')->assertExists($doc->path);
        $this->assertNotNull($doc->sent_at, '收据邮件照发');
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());

        // 本地的文件按记录里的位置读，不去碰对象存储
        $this->download($doc)->assertStatus(200);
        $this->assertCount(1, $this->s3History, '下载没有发 S3 请求');

        // 之后 S3 恢复，搬迁命令把它搬过去
        $this->s3->append(new Response(200));
        $this->artisan('billing:migrate-storage')->expectsOutputToContain('目标 s3：搬迁 1，原文件缺失 0，失败 0')->assertExitCode(0);
        $moved = $doc->fresh();
        $this->assertSame(BillingDocument::DISK_S3, $moved->disk);
        Storage::disk('local')->assertMissing($doc->path);
        $this->assertSame('PUT', $this->lastRequest()->getMethod());
        $this->assertStringStartsWith('%PDF', (string) $this->lastRequest()->getBody());
    }

    public function test_migrate_storage_skips_pruned_documents_and_counts_missing_files(): void
    {
        $user = $this->user();
        $kept = $this->receipt($user);
        $pruned = $this->receipt($this->user());
        BillingDocument::where('id', $pruned->id)->update(['size' => 0]);
        Storage::disk('local')->delete($pruned->path);
        $lost = $this->receipt($this->user());
        Storage::disk('local')->delete($lost->path);   // 记录说有文件、盘上没有

        config(self::S3);
        $this->artisan('billing:migrate-storage', ['--dry-run' => true])->expectsOutputToContain('[dry-run] 目标 s3：搬迁 2')->assertExitCode(0);
        $this->assertSame(BillingDocument::DISK_LOCAL, $kept->fresh()->disk);

        $this->s3->append(new Response(200));
        $this->artisan('billing:migrate-storage')->expectsOutputToContain('搬迁 1，原文件缺失 1，失败 0')->assertExitCode(0);
        $this->assertSame(BillingDocument::DISK_S3, $kept->fresh()->disk);
        $this->assertSame(BillingDocument::DISK_LOCAL, $pruned->fresh()->disk, '已清理的没有文件可搬');
        $this->assertSame(BillingDocument::DISK_LOCAL, $lost->fresh()->disk, '文件丢了的记录不动，下载时重建');

        // 上传失败：记录不动，命令报失败
        config(['v2board.billing_storage_driver' => 'local']);
        $this->s3->append(new Response(403, [], '<Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>'));
        $this->artisan('billing:migrate-storage')->assertExitCode(0);   // 目标 local，s3 上的 kept 要搬回来：GET
        $this->assertSame(BillingDocument::DISK_S3, $kept->fresh()->disk, '读不出来就当缺失，记录不动');
    }

    // ───────────────────────── 后台 ─────────────────────────

    public function test_admin_storage_test_reports_success_and_failure(): void
    {
        Sanctum::actingAs($this->user(['is_admin' => 1]));

        $local = $this->postJson($this->adminPath('/config/testBillingStorage'))->assertStatus(200)->json('data');
        $this->assertSame('local', $local['driver']);
        $this->assertSame('storage/app/billing/documents', $local['location']);
        $this->assertSame(0, count(Storage::disk('local')->allFiles()), '探针文件已删除');

        // 用表单里尚未保存的 S3 配置探测：PUT → HEAD → DELETE
        $override = [
            'billing_storage_driver' => 's3', 'billing_s3_endpoint' => 'https://minio.example.test:9000', 'billing_s3_region' => 'us-east-1',
            'billing_s3_bucket' => 'xb', 'billing_s3_access_key' => 'ak', 'billing_s3_secret_key' => 'sk', 'billing_s3_prefix' => 'bills',
        ];
        $this->s3->append(new Response(200), new Response(200), new Response(204));
        $ok = $this->postJson($this->adminPath('/config/testBillingStorage'), $override)->assertStatus(200)->json('data');
        $this->assertSame('s3', $ok['driver']);
        $this->assertSame('xb', $ok['bucket']);
        $this->assertSame('xb/bills', $ok['location']);
        $methods = array_map(fn ($h) => $h['request']->getMethod(), $this->s3History);
        $this->assertSame(['PUT', 'HEAD', 'DELETE'], $methods);
        $this->assertMatchesRegularExpression('#^https://minio\.example\.test:9000/xb/bills/\.probe-[a-f0-9]{16}\.txt$#', (string) $this->s3History[0]['request']->getUri());

        $this->s3->append(new Response(403, [], '<Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>'));
        $fail = $this->postJson($this->adminPath('/config/testBillingStorage'), $override)->assertStatus(400)->json('message');
        $this->assertStringContainsString('AccessDenied', $fail);

        $this->postJson($this->adminPath('/config/testBillingStorage'), ['billing_storage_driver' => 's3'])->assertStatus(400);

        // 保存时的字段校验（config/save 会写 Redis 缓存，这里直接拿规则验）
        $rules = (new ConfigSave())->rules();
        $this->assertFalse(Validator::make($override + ['billing_receipt_retention_days' => 730, 'billing_invoice_retention_days' => 30, 'mail_log_retention_days' => 90, 'billing_s3_path_style' => 0], $rules)->fails());
        $this->assertTrue(Validator::make(['billing_s3_endpoint' => 'minio.example.test'], $rules)->fails(), 'Endpoint 必须带协议');
        $this->assertTrue(Validator::make(['billing_s3_prefix' => 'a b'], $rules)->fails());
        $this->assertTrue(Validator::make(['billing_storage_driver' => 'ftp'], $rules)->fails());
        $this->assertTrue(Validator::make(['billing_receipt_retention_days' => 99999], $rules)->fails());
        $this->assertTrue(Validator::make(['mail_log_retention_days' => -1], $rules)->fails());

        // 读回：email 组带上存储与保留期的键
        config(['v2board.billing_storage_driver' => 's3', 'v2board.billing_s3_bucket' => 'xb', 'v2board.billing_s3_secret_key' => 'sk',
            'v2board.billing_receipt_retention_days' => 730, 'v2board.billing_invoice_retention_days' => 30, 'v2board.mail_log_retention_days' => 90]);
        $email = $this->getJson($this->adminPath('/config/fetch?key=email'))->assertStatus(200)->json('data.email');
        $this->assertSame('s3', $email['billing_storage_driver']);
        $this->assertSame('xb', $email['billing_s3_bucket']);
        $this->assertSame('sk', $email['billing_s3_secret_key']);
        $this->assertSame('billing/documents', $email['billing_s3_prefix']);
        $this->assertSame(730, $email['billing_receipt_retention_days']);
        $this->assertSame(30, $email['billing_invoice_retention_days']);
        $this->assertSame(90, $email['mail_log_retention_days']);
    }

    public function test_admin_document_list_carries_the_archive_summary(): void
    {
        $kept = $this->receipt($this->user());
        $pruned = $this->receipt($this->user());
        BillingDocument::where('id', $pruned->id)->update(['size' => 0]);

        Sanctum::actingAs($this->user(['is_admin' => 1]));
        $json = $this->postJson($this->adminPath('/billing/document/fetch'))->assertStatus(200)->json();
        $this->assertSame(2, $json['summary']['total']);
        $this->assertSame((int) $kept->size, $json['summary']['bytes']);
        $this->assertSame(1, $json['summary']['pruned']);
        $this->assertSame('local', $json['summary']['driver']);
        $this->assertSame('storage/app/billing/documents', $json['summary']['location']);
        $this->assertSame(365, $json['summary']['receipt_retention_days']);
        $this->assertSame(90, $json['summary']['invoice_retention_days']);
        $this->assertSame('local', $json['data'][0]['disk']);
        $this->assertSame(0, $json['data'][0]['size']);
    }
}
