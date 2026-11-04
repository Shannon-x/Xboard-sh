<?php

namespace Tests\Feature;

use App\Jobs\SendEmailJob;
use App\Models\BillingDocument;
use App\Models\PaygLog;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\StatUser;
use App\Models\User;
use App\Services\Billing\BillingDocumentService;
use App\Services\Payg\PaygConfig;
use App\Services\Payg\PaygService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 上网按量月度保底的扣费凭证：真的补扣了（金额 > 0）才开，每笔一份，按收据存（kind = receipt、状态 paid），
 * 出现在「账单与收据」里、能下载 PDF、像收据一样投递；开具失败不影响扣费。
 * phpunit.xml 的队列是 sync、邮件是 array 传输：派发即执行，从传输层取发出的邮件。
 */
class PaygFloorReceiptTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1073741824;

    private Plan $plan;
    private Carbon $last;

    protected function setUp(): void
    {
        parent::setUp();
        config(['v2board.email_verify_nudge_enable' => 0]);
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        app()->forgetScopedInstances();
        Storage::fake('local');
        Http::preventStrayRequests();
        Bus::fake([SendEmailJob::class]);
        $group = new ServerGroup();
        $group->name = '不限时套餐中';
        $group->save();
        // ¥15 / 100 GB：单价 15 分/GB
        $this->plan = Plan::create([
            'name' => '上网按量', 'group_id' => $group->id, 'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 100, 'device_limit' => 3, 'speed_limit' => null,
            'reset_traffic_method' => Plan::RESET_TRAFFIC_NEVER, 'prices' => ['onetime' => 15],
        ]);
        config(['v2board.payg_plan_ids' => [$this->plan->id]]);
        $this->last = Carbon::now()->startOfMonth()->subMonth();
    }

    /** 上上个月开的按量钱包，余额 $remainingGb */
    private function wallet(int $remainingGb = 100): User
    {
        $user = User::create([
            'email' => Str::random(12) . '@example.test', 'password' => 'test',
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'plan_id' => $this->plan->id, 'group_id' => $this->plan->group_id, 'balance' => 0,
            'transfer_enable' => $remainingGb * self::GB, 'u' => 0, 'd' => 0, 'expired_at' => null,
        ]);
        PaygLog::create(['user_id' => $user->id, 'plan_id' => $this->plan->id, 'type' => PaygLog::TYPE_RECHARGE,
            'opening' => 1, 'amount' => 1500, 'bytes' => 100 * self::GB]);
        PaygLog::where('user_id', $user->id)->update(['created_at' => Carbon::now()->startOfMonth()->subMonths(2)->timestamp]);
        return $user->fresh();
    }

    private function usage(User $user, int $bytes): void
    {
        StatUser::create(['user_id' => $user->id, 'server_rate' => 1, 'u' => 0, 'd' => $bytes,
            'record_type' => 'd', 'record_at' => strtotime($this->last->copy()->addDays(3)->format('Y-m-d'))]);
    }

    /** @return \Illuminate\Support\Collection<int, \Symfony\Component\Mailer\SentMessage> */
    private function sent()
    {
        return app('mailer')->getSymfonyTransport()->messages();
    }

    public function test_a_charged_floor_issues_exactly_one_statement_and_mails_it_like_a_receipt(): void
    {
        $user = $this->wallet();
        $this->usage($user, 6 * self::GB);   // 6 GB × ¥0.15 = ¥0.90，补扣 ¥4.10

        $this->artisan('payg:settle-floor')->assertSuccessful();

        $charged = PaygConfig::bytesFor(15, 410);
        $log = PaygLog::where('type', PaygLog::TYPE_FLOOR)->sole();
        $this->assertSame(100 * self::GB - $charged, (int) $user->fresh()->transfer_enable);

        $doc = BillingDocument::sole();
        $this->assertSame([BillingDocument::KIND_RECEIPT, (int) $user->id, 410, null, (int) $log->id],
            [$doc->kind, (int) $doc->user_id, (int) $doc->amount, $doc->order_id, (int) $doc->payg_log_id]);
        $this->assertTrue($doc->isPaygFloor());
        $this->assertSame('paid', $doc->statusFor($user));
        $this->assertMatchesRegularExpression('/^FL-\d{8}-[0-9A-HJKMNP-TV-Z]{8}$/', $doc->doc_no);
        $this->assertSame('payg_floor', $doc->payload['kind']);
        $this->assertSame($this->last->format('Y-m'), $doc->payload['month']);
        $this->assertSame(6 * self::GB, $doc->payload['usage_bytes']);
        $this->assertSame(90, $doc->payload['usage_amount']);
        $this->assertSame(500, $doc->payload['floor']);
        $this->assertSame($charged, $doc->payload['charged_bytes']);
        $this->assertSame(100 * self::GB - $charged, $doc->payload['balance_after']);
        $this->assertSame('¥4.10', $doc->payload['total_fmt']);
        $rows = array_column($doc->payload['rows'], 1, 0);
        $this->assertSame('6 GB（已按节点倍率计）', $rows['当月计费用量']);
        $this->assertSame('¥0.90（单价 ¥0.15 / GB）', $rows['按单价折合']);
        $this->assertSame('¥5.00', $rows['月最低消费']);
        $this->assertSame('¥4.10 = 折合 27.33 GB，从按量余额中扣除', $rows['本次补扣金额']);
        $this->assertSame('72.67 GB', $rows['扣除后剩余流量']);
        $this->assertStringContainsString('不是一笔新的付款', implode("\n", $doc->payload['notes']));

        // 像收据一样投递：一封带 PDF 的邮件，记下已送达
        $this->assertCount(1, $this->sent());
        $message = $this->sent()->first()->getOriginalMessage();
        $this->assertSame($user->email, $message->getTo()[0]->getAddress());
        $this->assertStringContainsString($doc->doc_no, $message->getSubject());
        $this->assertStringContainsString('¥4.10', $message->getHtmlBody());
        $this->assertStringContainsString('不是一笔新的付款', $message->getHtmlBody());
        $this->assertStringStartsWith('%PDF', $message->getAttachments()[0]->getBody());
        $this->assertSame($doc->doc_no . '.pdf', $message->getAttachments()[0]->getFilename());
        $this->assertNotNull($doc->sent_at);
        $this->assertSame(BillingDocument::CHANNEL_EMAIL, $doc->channel);

        // 重跑结算：这个月已结算，不另开、不再发
        $this->artisan('payg:settle-floor')->assertSuccessful();
        $this->assertSame('settled', app(PaygService::class)->settleFloor($user->fresh(), $this->last)['status']);
        $this->assertSame(1, BillingDocument::count());
        $this->assertCount(1, $this->sent());
    }

    public function test_a_zero_amount_settlement_or_disabled_receipts_issue_no_statement(): void
    {
        // 余额已经是 0：记一行金额 0 的保底，不开凭证
        $empty = $this->wallet(remainingGb: 0);
        $this->usage($empty, 1 * self::GB);
        $result = app(PaygService::class)->settleFloor($empty, $this->last);
        $this->assertSame(['status' => 'charged', 'amount' => 0, 'bytes' => 0], $result);
        $this->assertSame(0, (int) PaygLog::where('user_id', $empty->id)->where('type', PaygLog::TYPE_FLOOR)->value('amount'));

        // 收据开关关着：照扣，但不开凭证（与订单收据同一个开关）
        config(['v2board.billing_receipt_enable' => 0]);
        $off = $this->wallet();
        $this->usage($off, 1 * self::GB);
        $this->assertSame(485, app(PaygService::class)->settleFloor($off, $this->last)['amount']);

        $this->assertSame(0, BillingDocument::count());
        $this->assertCount(0, $this->sent());
    }

    public function test_a_short_wallet_statement_says_the_rest_is_not_collected(): void
    {
        $user = $this->wallet(remainingGb: 5);
        $this->usage($user, 1 * self::GB);
        // 差 ¥4.85，余额只剩 5 GB：扣到 0，按扣掉的 5 GB 记 ¥0.75
        $this->assertSame(75, app(PaygService::class)->settleFloor($user, $this->last)['amount']);
        $doc = BillingDocument::sole();
        $this->assertSame(75, (int) $doc->amount);
        $this->assertSame(0, $doc->payload['balance_after']);
        $this->assertStringContainsString('未扣足的 ¥4.10 不再追收', implode("\n", $doc->payload['notes']));
    }

    public function test_the_statement_is_listed_as_a_paid_receipt_and_downloads_as_a_pdf(): void
    {
        $user = $this->wallet();
        $this->usage($user, 6 * self::GB);
        $this->artisan('payg:settle-floor')->assertSuccessful();
        $doc = BillingDocument::sole();

        Sanctum::actingAs($user);
        $list = $this->getJson('/api/v1/user/billing/documents')->assertStatus(200)->json('data');
        $this->assertCount(1, $list);
        $this->assertSame([BillingDocument::KIND_RECEIPT, 'paid', 410, $doc->doc_no, null],
            [$list[0]['kind'], $list[0]['status'], $list[0]['amount'], $list[0]['doc_no'], $list[0]['order_trade_no']]);
        $this->assertSame(BillingDocument::TYPE_PAYG_FLOOR, $list[0]['type']);
        $this->assertSame('保底扣费凭证', $list[0]['title']);
        $this->assertNull($list[0]['pay_path']);

        $resp = $this->get($list[0]['download_path'])->assertStatus(200);
        $resp->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString($doc->doc_no . '.pdf', (string) $resp->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $resp->getContent());

        // PDF 正文（mPDF 压缩了内容流，这里断言喂给它的 HTML）
        $html = view('billing.pdf.document', $doc->payload + ['logo_data' => null])->render();
        foreach (['保底扣费凭证', $this->last->format('Y-m'), '当月计费用量', '月最低消费', '¥5.00',
            '¥4.10 = 折合 27.33 GB，从按量余额中扣除', '扣除后剩余流量', '72.67 GB', '按量余额（预付流量）', '不是一笔新的付款'] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        // 订单收据的列表字段不受影响
        $this->assertSame(BillingDocument::TYPE_ORDER, (new BillingDocument(['kind' => BillingDocument::KIND_RECEIPT]))->listType());
    }

    public function test_admin_lists_and_resends_the_statement_from_its_snapshot(): void
    {
        $user = $this->wallet();
        $this->usage($user, 6 * self::GB);
        $this->artisan('payg:settle-floor')->assertSuccessful();
        $doc = BillingDocument::sole();
        $this->assertCount(1, $this->sent());

        $admin = User::create(['email' => 'admin@example.test', 'password' => 'x', 'uuid' => (string) Str::uuid(),
            'token' => Str::random(32), 'plan_id' => null, 'group_id' => null, 'balance' => 0,
            'transfer_enable' => 0, 'u' => 0, 'd' => 0, 'expired_at' => null, 'is_admin' => 1]);
        Sanctum::actingAs($admin, ['admin']);
        $path = '/api/v2/' . admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
        $row = collect($this->postJson($path . '/billing/document/fetch')->assertStatus(200)->json('data'))->firstWhere('id', $doc->id);
        $this->assertSame([BillingDocument::KIND_RECEIPT, BillingDocument::TYPE_PAYG_FLOOR, (int) $doc->payg_log_id, 'paid'],
            [$row['kind'], $row['type'], $row['payg_log_id'], $row['status']]);

        $this->postJson($path . '/billing/document/resend', ['id' => $doc->id])->assertStatus(200);
        $this->assertCount(2, $this->sent());
        $this->assertSame(2, (int) $doc->fresh()->send_count);
        $this->assertSame($doc->payload, $doc->fresh()->payload, '照快照重发，不改内容');
    }

    public function test_a_failure_to_issue_the_statement_never_undoes_the_charge(): void
    {
        $this->app->instance(BillingDocumentService::class, new class extends BillingDocumentService {
            public function paygFloor(PaygLog $log, array $context = []): ?array
            {
                throw new \RuntimeException('render failed');
            }
        });
        $user = $this->wallet();
        $this->usage($user, 6 * self::GB);

        $result = app(PaygService::class)->settleFloor($user, $this->last);

        $this->assertSame(['status' => 'charged', 'amount' => 410, 'bytes' => PaygConfig::bytesFor(15, 410)], $result);
        $this->assertSame(100 * self::GB - PaygConfig::bytesFor(15, 410), (int) $user->fresh()->transfer_enable);
        $this->assertSame(1, PaygLog::where('type', PaygLog::TYPE_FLOOR)->count());
        $this->assertSame(0, BillingDocument::count());
    }
}
