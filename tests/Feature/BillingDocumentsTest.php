<?php

namespace Tests\Feature;

use App\Jobs\SendBillingMailJob;
use App\Jobs\SendEmailJob;
use App\Models\BillingDocument;
use App\Models\CommissionWithdrawal;
use App\Models\Order;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\Billing\BillingArchive;
use App\Services\Billing\BillingDocumentService;
use App\Services\Billing\BrandLogo;
use App\Services\MailService;
use App\Services\OrderService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 收据 / 续费账单邮件：触发时机、同一到期日只发一次、PDF 附件。
 * phpunit.xml 把 MAIL_DRIVER 设成 array，这里直接从 array 传输层取发出的邮件。
 */
class BillingDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1073741824;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        // 这个套件按封数数收据；付款开通顺带发的邮箱验证邮件（EmailVerificationTest 单独测）在这里关掉
        config(['v2board.email_verify_nudge_enable' => 0]);
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

    /** @return \Illuminate\Support\Collection<int, \Symfony\Component\Mailer\SentMessage> */
    private function sent()
    {
        return app('mailer')->getSymfonyTransport()->messages();
    }

    private function runJob(string $kind, int $id, ?string $stage = null, ?int $expiry = null): void
    {
        (new SendBillingMailJob($kind, $id, $stage, $expiry))->handle(app(BillingDocumentService::class), app(BillingArchive::class));
    }

    // ───────────────────────── 收据 ─────────────────────────

    public function test_opening_a_paid_order_sends_one_receipt_with_a_pdf(): void
    {
        $user = $this->user();
        $order = $this->order($user);
        (new OrderService($order))->open();

        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        $this->assertNotNull($order->fresh()->receipt_sent_at);
        $this->assertCount(1, $this->sent());

        $message = $this->sent()->first()->getOriginalMessage();
        $this->assertSame($user->email, $message->getTo()[0]->getAddress());
        $this->assertStringContainsString('RC-', $message->getSubject());
        $this->assertStringContainsString('¥20.00', $message->getHtmlBody());
        $attachments = $message->getAttachments();
        $this->assertCount(1, $attachments);
        $this->assertStringStartsWith('%PDF', $attachments[0]->getBody());
        $this->assertSame('pdf', $attachments[0]->getMediaSubtype());

        // 任务重跑：receipt_sent_at 已打标，不发第二封
        $this->runJob(SendBillingMailJob::KIND_RECEIPT, $order->id);
        $this->assertCount(1, $this->sent());
    }

    public function test_free_orders_and_disabled_switch_send_no_receipt(): void
    {
        $user = $this->user();
        $free = $this->order($user, ['total_amount' => 0]);
        (new OrderService($free))->open();
        $this->assertSame(Order::STATUS_COMPLETED, (int) $free->fresh()->status);
        $this->assertNull($free->fresh()->receipt_sent_at);
        $this->assertCount(0, $this->sent());

        Bus::fake([SendBillingMailJob::class]);
        config(['v2board.billing_receipt_enable' => 0]);
        (new OrderService($this->order($this->user())))->open();
        Bus::assertNotDispatched(SendBillingMailJob::class);
    }

    // ───────────────────────── 续费账单 ─────────────────────────

    public function test_first_invoice_goes_out_once_per_expiry_in_the_lead_window(): void
    {
        Bus::fake([SendBillingMailJob::class, SendEmailJob::class]);
        $user = $this->user(['expired_at' => time() + 5 * 86400]);
        $tooEarly = $this->user(['expired_at' => time() + 20 * 86400]);

        $stats = (new MailService())->processUsersInChunks(100);
        $this->assertSame(1, $stats['invoice_emails']);
        Bus::assertDispatched(SendBillingMailJob::class, fn (SendBillingMailJob $job) => $job->kind === 'invoice'
            && $job->id === $user->id && $job->stage === BillingDocumentService::STAGE_FIRST && $job->expiry === (int) $user->expired_at);
        $this->assertSame((int) $user->expired_at, (int) $user->fresh()->invoice_notified_at);
        $this->assertNull($tooEarly->fresh()->invoice_notified_at);

        $again = (new MailService())->processUsersInChunks(100);
        $this->assertSame(0, $again['invoice_emails']);
        Bus::assertDispatchedTimes(SendBillingMailJob::class, 1);
    }

    public function test_final_reminder_uses_the_invoice_job_and_falls_back_when_disabled(): void
    {
        Bus::fake([SendBillingMailJob::class, SendEmailJob::class]);
        $user = $this->user(['expired_at' => time() + 20 * 3600]);

        $stats = (new MailService())->processUsersInChunks(100);
        $this->assertSame(1, $stats['expire_emails']);
        Bus::assertDispatched(SendBillingMailJob::class, fn (SendBillingMailJob $job) => $job->stage === BillingDocumentService::STAGE_FINAL && $job->id === $user->id);
        Bus::assertNotDispatched(SendEmailJob::class);
        $this->assertSame(0, (new MailService())->processUsersInChunks(100)['expire_emails'], '同一到期日只提醒一次');

        config(['v2board.billing_invoice_enable' => 0]);
        (new MailService())->processUsersInChunks(100);
        Bus::assertDispatched(SendEmailJob::class);   // 旧的 remindExpire 模板
        Bus::assertDispatchedTimes(SendBillingMailJob::class, 1);
    }

    public function test_invoice_job_renders_the_pdf_and_drops_stale_invoices(): void
    {
        $user = $this->user(['expired_at' => time() + 5 * 86400, 'discount' => 10]);
        $expiry = (int) $user->expired_at;

        $this->runJob(SendBillingMailJob::KIND_INVOICE, $user->id, BillingDocumentService::STAGE_FIRST, $expiry);
        $this->assertCount(1, $this->sent());
        $message = $this->sent()->first()->getOriginalMessage();
        $this->assertStringContainsString('INV-', $message->getSubject());
        $this->assertStringContainsString('静态家宽拼车', $message->getSubject());
        $html = $message->getHtmlBody();
        $this->assertStringContainsString('¥18.00', $html, '月付 ¥20 扣 10% 专属折扣');
        // 按钮是这张账单的免登录付款页（BillingPayLinkTest 管细节），不再是要先登录的套餐页
        $doc = BillingDocument::where('user_id', $user->id)->where('kind', BillingDocument::KIND_INVOICE)->firstOrFail();
        $this->assertStringContainsString('/pay/' . $doc->payToken(), $html);
        $this->assertStringNotContainsString('/plans?mode=renew', $html);
        $this->assertCount(1, $message->getAttachments());
        $this->assertStringStartsWith('%PDF', $message->getAttachments()[0]->getBody());

        // 派发后用户续了费（expired_at 变了）：这张账单作废
        $this->runJob(SendBillingMailJob::KIND_INVOICE, $user->id, BillingDocumentService::STAGE_FIRST, $expiry - 1);
        $this->assertCount(1, $this->sent());
    }

    public function test_unrenewable_plan_gets_a_browse_email_without_pdf(): void
    {
        $this->plan->update(['renew' => false]);
        $user = $this->user(['expired_at' => time() + 5 * 86400]);

        $this->runJob(SendBillingMailJob::KIND_INVOICE, $user->id, BillingDocumentService::STAGE_FIRST, (int) $user->expired_at);
        $message = $this->sent()->first()->getOriginalMessage();
        $this->assertCount(0, $message->getAttachments());
        $this->assertStringContainsString('/plans', $message->getHtmlBody());
    }

    public function test_final_reminder_is_skipped_when_auto_renew_will_charge(): void
    {
        $user = $this->user(['expired_at' => time() + 20 * 3600, 'auto_renew' => 1, 'balance' => 5000]);

        $this->runJob(SendBillingMailJob::KIND_INVOICE, $user->id, BillingDocumentService::STAGE_FINAL, (int) $user->expired_at);
        $this->assertCount(0, $this->sent(), '一小时内自动续费就会扣款并发收据，最后提醒只是噪音');

        $this->runJob(SendBillingMailJob::KIND_INVOICE, $user->id, BillingDocumentService::STAGE_FIRST, (int) $user->expired_at);
        $this->assertCount(1, $this->sent());
        $this->assertStringContainsString('自动续费', $this->sent()->first()->getOriginalMessage()->getHtmlBody());
    }

    // ───────────────────────── 品牌 logo ─────────────────────────

    public function test_logo_is_linked_in_the_mail_and_embedded_in_the_pdf(): void
    {
        $url = 'https://cdn.example.test/brand/logo.png';
        config(['v2board.billing_logo' => $url]);
        @unlink(storage_path('app/billing/logo/' . sha1($url) . '.png'));
        $im = imagecreatetruecolor(120, 60);
        imagefill($im, 0, 0, imagecolorallocate($im, 201, 79, 46));
        ob_start();
        imagepng($im);
        Http::fake([$url => Http::response(ob_get_clean(), 200, ['Content-Type' => 'image/png'])]);

        $order = $this->order($this->user());
        (new OrderService($order))->open();
        $message = $this->sent()->first()->getOriginalMessage();
        $html = $message->getHtmlBody();
        $this->assertStringContainsString('<img src="' . $url . '"', $html, '邮件里直接引用 logo URL');
        $this->assertStringContainsString('height="40" width="80"', $html, '高固定 40px，宽按 120:60 等比');
        $this->assertStringContainsString('/Subtype /Image', $message->getAttachments()[0]->getBody(), 'PDF 里嵌进了 logo');

        BrandLogo::dataUri();
        Http::assertSentCount(1);   // 之后走缓存，不再请求

        config(['v2board.billing_logo' => '']);
        $this->runJob(SendBillingMailJob::KIND_RECEIPT, $this->order($this->user())->id);
    }

    public function test_without_a_logo_the_header_is_text_only(): void
    {
        config(['v2board.billing_logo' => '', 'v2board.logo' => '']);
        $order = $this->order($this->user());
        (new OrderService($order))->open();
        $message = $this->sent()->first()->getOriginalMessage();
        $this->assertStringNotContainsString('<img', $message->getHtmlBody());
        $this->assertStringNotContainsString('/Subtype /Image', $message->getAttachments()[0]->getBody());
    }

    // ───────────────────────── 佣金提现 ─────────────────────────

    private function withdrawal(User $user, array $attributes = []): CommissionWithdrawal
    {
        return CommissionWithdrawal::create($attributes + [
            'user_id' => $user->id, 'amount' => 5000, 'currency' => 'CNY', 'chain_code' => 'usdt_trc20', 'chain_name' => 'USDT', 'network' => 'TRC20 (Tron)',
            'address' => 'TXYZabcdefghijklmnopqrstuvwxyz1234', 'usdt_rate' => '7.2000', 'usdt_fee' => '1.0000', 'usdt_amount' => '5.9400',
            'status' => CommissionWithdrawal::STATUS_PENDING,
        ]);
    }

    public function test_withdrawal_result_mails_render_from_the_record(): void
    {
        $user = $this->user();
        $paid = $this->withdrawal($user, ['status' => CommissionWithdrawal::STATUS_COMPLETED, 'txid' => 'abc123', 'paid_usdt' => '5.9000', 'settle_rate' => '7.2500', 'settled_at' => time()]);
        $this->runJob(SendBillingMailJob::KIND_WITHDRAWAL, $paid->id);
        $this->assertCount(1, $this->sent());
        $message = $this->sent()->first()->getOriginalMessage();
        $this->assertStringContainsString('#' . $paid->id, $message->getSubject());
        $html = $message->getHtmlBody();
        $this->assertStringContainsString('¥50.00', $html);
        $this->assertStringContainsString('实付 5.90 USDT', $html, '库里的 5.9000（MySQL）或 5.9（sqlite）都整理成 5.90');
        $this->assertStringContainsString('已扣通道费 1.00 USDT', $html);
        $this->assertStringContainsString('汇率 7.25', $html);
        $this->assertStringContainsString('abc123', $html);
        $this->assertStringContainsString('https://tronscan.org/#/transaction/abc123', $html, '有交易哈希时主按钮指向区块浏览器');
        $this->assertCount(0, $message->getAttachments(), '提现结果不附 PDF');

        $declined = $this->withdrawal($user, ['status' => CommissionWithdrawal::STATUS_REJECTED, 'reject_reason' => '地址与截图不一致', 'settled_at' => time()]);
        $this->runJob(SendBillingMailJob::KIND_WITHDRAWAL, $declined->id);
        $this->assertCount(2, $this->sent());
        $html = $this->sent()->last()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('地址与截图不一致', $html);
        $this->assertStringContainsString('¥50.00', $html, '驳回邮件写明退回的金额');

        // 待处理 / 用户自己取消的申请没有结果可发
        $this->runJob(SendBillingMailJob::KIND_WITHDRAWAL, $this->withdrawal($user)->id);
        $this->runJob(SendBillingMailJob::KIND_WITHDRAWAL, $this->withdrawal($user, ['status' => CommissionWithdrawal::STATUS_CANCELLED])->id);
        $this->assertCount(2, $this->sent());
    }
}
