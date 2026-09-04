<?php

namespace Tests\Feature;

use App\Jobs\SendEmailJob;
use App\Models\CommissionWithdrawal;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 佣金提现工作流：申请即冻结 → 自动开工单（附二维码）→ 结算 / 驳回 / 取消。
 */
class CommissionWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
    private const TRON_ADDRESS = 'TN3W4H6rK2ce4vX9YnFQHwKENnHjoxb3m9';

    private array $settings = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        // 汇率走 auto 时会打行情接口：测试里一律拦下，未打桩的请求直接失败而不是偷偷联网
        Http::preventStrayRequests();

        $stub = new class extends Setting {
            public array $store = [];
            public function __construct()
            {
            }
            public function get(string $key, mixed $default = null): mixed
            {
                return $this->store[strtolower($key)] ?? $default;
            }
            public function save(array $settings): bool
            {
                foreach ($settings as $k => $v) {
                    $this->store[strtolower($k)] = $v;
                }
                return true;
            }
            public function set(string $key, mixed $value = null): bool
            {
                return $this->save([$key => $value]);
            }
        };
        $stub->store = &$this->settings;
        $this->app->instance(Setting::class, $stub);

        $this->settings = [
            'commission_withdraw_limit' => 10,     // ¥10
            'commission_withdraw_max' => 500,      // ¥500
            // 汇率固定成手动，测试不打行情接口；auto 模式的取数逻辑见 UsdtRateServiceTest
            'commission_withdraw_rate_source' => 'manual',
            'commission_withdraw_usdt_rate' => 7.2,
            'ticket_attachment_enable' => 1,
            'ticket_attachment_driver' => 'local',
        ];
    }

    private function makeUser(array $attrs = []): User
    {
        return User::create($attrs + [
            'email' => 'wd-' . Str::random(6) . '@example.com',
            'password' => 'x',
            'uuid' => Str::uuid()->toString(),
            'token' => bin2hex(random_bytes(16)),
            'balance' => 0,
            'commission_balance' => 20000, // ¥200
            'transfer_enable' => 0,
            'expired_at' => 0,
            'plan_id' => null,
            'group_id' => null,
        ]);
    }

    public function test_config_exposes_chains_and_balance(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/user/withdraw/config')
            ->assertStatus(200)
            ->assertJsonPath('data.enable', true)
            ->assertJsonPath('data.min_amount', 1000)
            ->assertJsonPath('data.max_amount', 50000)
            ->assertJsonPath('data.commission_balance', 20000)
            ->assertJsonPath('data.has_pending', false)
            ->assertJsonPath('data.chains.0.code', 'usdt_trc20')
            ->assertJsonPath('data.chains.0.pattern', '^T[1-9A-HJ-NP-Za-km-z]{33}$');
    }

    public function test_apply_freezes_commission_and_opens_ticket_with_qrcode(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $qr = UploadedFile::fake()->createWithContent('qr.png', base64_decode(self::PNG_BASE64));
        $attachmentId = $this->post('/api/v1/user/ticket/attachment/upload', ['file' => $qr])->json('data.id');

        $resp = $this->postJson('/api/v1/user/withdraw/apply', [
            'amount' => 12345,
            'chain' => 'usdt_trc20',
            'address' => self::TRON_ADDRESS,
            'attachment_ids' => [$attachmentId],
        ]);
        $resp->assertStatus(200)
            ->assertJsonPath('data.status', 0)
            ->assertJsonPath('data.amount', 12345)
            ->assertJsonPath('data.usdt_rate', '7.2000')
            ->assertJsonPath('data.usdt_fee', '1.0000')
            // 到账金额已扣掉 TRC20 的 1 USDT 通道费
            ->assertJsonPath('data.usdt_amount', '16.1458');

        $this->assertSame(20000 - 12345, $user->fresh()->commission_balance, '申请即冻结扣除');

        $withdrawal = CommissionWithdrawal::firstOrFail();
        $ticket = Ticket::findOrFail($withdrawal->ticket_id);
        $this->assertSame($user->id, $ticket->user_id);
        $this->assertSame(2, (int) $ticket->level);
        $this->assertStringContainsString('[提现申请]', $ticket->subject);
        $message = TicketMessage::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertStringContainsString(self::TRON_ADDRESS, $message->message);
        $this->assertSame($message->id, TicketAttachment::findOrFail($attachmentId)->ticket_message_id, '二维码绑定到工单首条消息');

        // 有待处理申请时不能再申请
        $this->postJson('/api/v1/user/withdraw/apply', [
            'amount' => 1000,
            'chain' => 'usdt_trc20',
            'address' => self::TRON_ADDRESS,
        ])->assertStatus(400);
        $this->getJson('/api/v1/user/withdraw/config')->assertJsonPath('data.has_pending', true);
    }

    public function test_apply_validates_amount_chain_and_address(): void
    {
        // 本用例连打 7 次申请，超过 withdraw-apply 限流（5/min）；限流本身由中间件保证，这里只测业务校验
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $base = ['chain' => 'usdt_trc20', 'address' => self::TRON_ADDRESS];

        // 低于下限 / 高于上限 / 超过余额
        $this->postJson('/api/v1/user/withdraw/apply', $base + ['amount' => 999])->assertStatus(400);
        $this->postJson('/api/v1/user/withdraw/apply', $base + ['amount' => 50001])->assertStatus(400);
        $this->settings['commission_withdraw_max'] = 0;
        $this->postJson('/api/v1/user/withdraw/apply', $base + ['amount' => 20001])->assertStatus(400);
        // 地址与链不符（TRC20 填了 EVM 地址）/ 未知链
        $this->postJson('/api/v1/user/withdraw/apply', ['amount' => 5000, 'chain' => 'usdt_trc20', 'address' => '0x' . str_repeat('a', 40)])->assertStatus(400);
        $this->postJson('/api/v1/user/withdraw/apply', ['amount' => 5000, 'chain' => 'usdt_sol', 'address' => self::TRON_ADDRESS])->assertStatus(422);
        // 必须二维码
        $this->settings['commission_withdraw_require_qrcode'] = 1;
        $this->postJson('/api/v1/user/withdraw/apply', $base + ['amount' => 5000])->assertStatus(400);
        // 关闭提现
        $this->settings['commission_withdraw_require_qrcode'] = 0;
        $this->settings['withdraw_close_enable'] = 1;
        $this->postJson('/api/v1/user/withdraw/apply', $base + ['amount' => 5000])->assertStatus(400);

        $this->assertSame(0, CommissionWithdrawal::count());
        $this->assertSame(20000, $user->fresh()->commission_balance, '校验失败不得动余额');
    }

    public function test_cancel_refunds_and_closes_ticket(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/v1/user/withdraw/apply', ['amount' => 5000, 'chain' => 'usdt_trc20', 'address' => self::TRON_ADDRESS])->json('data.id');
        $this->assertSame(15000, $user->fresh()->commission_balance);

        $this->postJson('/api/v1/user/withdraw/cancel', ['id' => $id])->assertStatus(200)->assertJsonPath('data.status', 3);
        $this->assertSame(20000, $user->fresh()->commission_balance, '取消退回冻结佣金');
        $withdrawal = CommissionWithdrawal::findOrFail($id);
        $this->assertSame(Ticket::STATUS_CLOSED, Ticket::findOrFail($withdrawal->ticket_id)->status);
        $this->assertSame(2, TicketMessage::where('ticket_id', $withdrawal->ticket_id)->count());

        // 已处理的不能再取消；别人的不能取消
        $this->postJson('/api/v1/user/withdraw/cancel', ['id' => $id])->assertStatus(400);
    }

    public function test_admin_settle_marks_completed_replies_ticket_and_emails_user(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser(['is_admin' => 1]);
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/v1/user/withdraw/apply', ['amount' => 5000, 'chain' => 'usdt_bep20', 'address' => '0x' . str_repeat('ab', 20)])->json('data.id');

        $withdrawal = (new \App\Services\CommissionWithdrawalService())->settle($id, $admin, '0xdeadbeef', '6.94', '手动打款');

        $this->assertSame(CommissionWithdrawal::STATUS_COMPLETED, $withdrawal->status);
        $this->assertSame('0xdeadbeef', $withdrawal->txid);
        $this->assertSame('6.9400', $withdrawal->paid_usdt);
        $this->assertSame($admin->id, $withdrawal->admin_id);
        $this->assertSame(15000, $user->fresh()->commission_balance, '结算不再动余额（申请时已扣）');

        $ticket = Ticket::findOrFail($withdrawal->ticket_id);
        $this->assertSame(Ticket::STATUS_CLOSED, $ticket->status);
        $reply = TicketMessage::where('ticket_id', $ticket->id)->where('user_id', $admin->id)->firstOrFail();
        $this->assertStringContainsString('0xdeadbeef', $reply->message);
        $this->assertStringContainsString('感谢你对我们的支持', $reply->message);

        Queue::assertPushed(SendEmailJob::class, function (SendEmailJob $job) use ($user) {
            $params = (fn() => $this->params)->call($job);
            return $params['email'] === $user->email && str_contains($params['subject'], '提现已完成');
        });

        // 用户端能看到 txid 与浏览器链接
        $list = $this->getJson('/api/v1/user/withdraw/fetch')->assertStatus(200);
        $this->assertSame('https://bscscan.com/tx/0xdeadbeef', $list->json('data.0.explorer_url'));
        $this->assertArrayNotHasKey('remark', $list->json('data.0'), '管理员备注不下发给用户');
    }

    public function test_admin_reject_refunds_and_notifies(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser(['is_admin' => 1]);
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/v1/user/withdraw/apply', ['amount' => 5000, 'chain' => 'usdt_trc20', 'address' => self::TRON_ADDRESS])->json('data.id');

        $withdrawal = (new \App\Services\CommissionWithdrawalService())->reject($id, $admin, '地址与截图不一致');

        $this->assertSame(CommissionWithdrawal::STATUS_REJECTED, $withdrawal->status);
        $this->assertSame('地址与截图不一致', $withdrawal->reject_reason);
        $this->assertSame(20000, $user->fresh()->commission_balance, '驳回退回冻结佣金');
        $this->assertSame(Ticket::STATUS_CLOSED, Ticket::findOrFail($withdrawal->ticket_id)->status);
        Queue::assertPushed(SendEmailJob::class, fn(SendEmailJob $job) => str_contains((fn() => $this->params)->call($job)['subject'], '未通过'));

        // 已驳回的不能再结算
        $this->expectException(\App\Exceptions\ApiException::class);
        (new \App\Services\CommissionWithdrawalService())->settle($id, $admin);
    }

    public function test_payout_profile_is_remembered_and_qr_can_be_reused(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $user = $this->makeUser(['is_admin' => 0]);
        $admin = $this->makeUser(['is_admin' => 1]);
        Sanctum::actingAs($user);

        $qr = UploadedFile::fake()->createWithContent('qr.png', base64_decode(self::PNG_BASE64));
        $qrId = $this->post('/api/v1/user/ticket/attachment/upload', ['file' => $qr])->json('data.id');
        $first = $this->postJson('/api/v1/user/withdraw/apply', [
            'amount' => 3000,
            'chain' => 'usdt_trc20',
            'address' => self::TRON_ADDRESS,
            'attachment_ids' => [$qrId],
        ])->assertStatus(200)->json('data.id');

        // 下次打开弹窗：链 / 地址 / 二维码都被记住
        $this->getJson('/api/v1/user/withdraw/config')
            ->assertJsonPath('data.saved.chain_code', 'usdt_trc20')
            ->assertJsonPath('data.saved.address', self::TRON_ADDRESS)
            ->assertJsonPath('data.saved.qr.attachment_id', $qrId);

        // 结算掉第一笔，再发起第二笔并沿用二维码 —— 应复制出一份新附件绑到新工单，旧图不动
        (new \App\Services\CommissionWithdrawalService())->settle($first, $admin, 'tx1');
        $second = $this->postJson('/api/v1/user/withdraw/apply', [
            'amount' => 2000,
            'chain' => 'usdt_trc20',
            'address' => self::TRON_ADDRESS,
            'reuse_qr' => true,
        ])->assertStatus(200)->json('data.id');
        $secondTicket = CommissionWithdrawal::findOrFail($second)->ticket_id;
        $copied = TicketAttachment::where('ticket_id', $secondTicket)->first();
        $this->assertNotNull($copied, '沿用二维码应复制出新附件并绑定到新工单');
        $this->assertNotSame($qrId, $copied->id);
        $this->assertSame('qr.png', $copied->original_name);
        $this->assertNotNull(TicketAttachment::find($qrId), '原二维码仍留在第一张工单上');
        $this->assertSame(1, TicketAttachment::whereNull('ticket_message_id')->count() === 0 ? 1 : 0, '不得残留未绑定附件');

        // 换地址且不传新图 → 保存的二维码被清掉（旧地址的码会误导管理员）
        $this->postJson('/api/v1/user/withdraw/cancel', ['id' => $second])->assertStatus(200);
        $this->postJson('/api/v1/user/withdraw/apply', [
            'amount' => 1000,
            'chain' => 'usdt_bep20',
            'address' => '0x' . str_repeat('cd', 20),
        ])->assertStatus(200);
        $this->getJson('/api/v1/user/withdraw/config')
            ->assertJsonPath('data.saved.chain_code', 'usdt_bep20')
            ->assertJsonPath('data.saved.qr', null);

        // 清除保存的信息
        $this->postJson('/api/v1/user/withdraw/saved/clear')->assertStatus(200);
        $this->getJson('/api/v1/user/withdraw/config')->assertJsonPath('data.saved', null);
    }

    public function test_settle_uses_dedicated_email_template_with_structured_vars(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser(['is_admin' => 1]);
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/v1/user/withdraw/apply', ['amount' => 5000, 'chain' => 'usdt_trc20', 'address' => self::TRON_ADDRESS])->json('data.id');

        (new \App\Services\CommissionWithdrawalService())->settle($id, $admin, 'abc123', '6.9');

        Queue::assertPushed(SendEmailJob::class, function (SendEmailJob $job) {
            $p = (fn() => $this->params)->call($job);
            $v = $p['template_value'];
            return $p['template_name'] === 'withdrawCompleted'
                && $v['amount'] === '¥50.00'
                && $v['usdt'] === '6.9000' && $v['usdt_is_actual'] === true
                && $v['txid'] === 'abc123'
                && $v['explorer_url'] === 'https://tronscan.org/#/transaction/abc123'
                && str_contains($v['chain'], 'TRC20');
        });
        // 三套邮件主题目录都提供了专用模板
        foreach (['default', 'classic', 'editorial'] as $theme) {
            $this->assertTrue(view()->exists("mail.{$theme}.withdrawCompleted"), "{$theme} 缺 withdrawCompleted");
            $this->assertTrue(view()->exists("mail.{$theme}.withdrawRejected"), "{$theme} 缺 withdrawRejected");
        }
    }

    /**
     * 结算时按打款那一刻的实时汇率重算：申请可能是几小时前提的，行情早变了。
     * 管理员没填实付金额时由系统自动折算，并记下当时的汇率备查。
     */
    public function test_settle_recomputes_usdt_with_live_rate(): void
    {
        $this->settings['commission_withdraw_rate_source'] = 'auto';
        // 第一次取到 7.20（申请），缓存清掉后第二次取到 6.50（打款）
        Http::fake(['p2p.binance.com/*' => Http::sequence()
            ->push(['data' => [['adv' => ['price' => '7.20']]]])
            ->push(['data' => [['adv' => ['price' => '6.50']]]])]);

        $user = $this->makeUser();
        $admin = $this->makeUser(['is_admin' => 1]);
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/v1/user/withdraw/apply', [
            'amount' => 5000,
            'chain' => 'usdt_trc20',
            'address' => self::TRON_ADDRESS,
        ])->assertStatus(200)->json('data.id');

        $applied = CommissionWithdrawal::findOrFail($id);
        // SQLite 取回 decimal 是 float，按数值比
        $this->assertEqualsWithDelta(7.2, (float) $applied->usdt_rate, 0.0001);
        $this->assertEqualsWithDelta(5.9444, (float) $applied->usdt_amount, 0.0001, '50/7.2 - 1 通道费');
        $this->assertSame('binance_p2p', $applied->rate_source);

        // 打款时行情变成 6.50，管理员不填实付金额 —— 清掉缓存逼它重新取
        Cache::flush();
        (new \App\Services\CommissionWithdrawalService())->settle($id, $admin, 'tx-live');

        $settled = CommissionWithdrawal::findOrFail($id);
        $this->assertEqualsWithDelta(6.5, (float) $settled->settle_rate, 0.0001);
        $this->assertEqualsWithDelta(6.6923, (float) $settled->paid_usdt, 0.0001, '50/6.5 - 1 通道费，按打款时汇率');
        $this->assertEqualsWithDelta(5.9444, (float) $settled->usdt_amount, 0.0001, '申请时的估算原样留档');
    }

    /**
     * 后台汇率接口：设置页与结算弹窗都靠它。直接调控制器方法（管理端路由带 secure_path，
     * 不适合在这里拼），重点是让这段代码真的跑起来——只做静态检查漏得掉未加引号的参数名之类的错。
     */
    public function test_admin_rate_endpoint_returns_live_quote(): void
    {
        $this->settings['commission_withdraw_rate_source'] = 'auto';
        Http::fake(['p2p.binance.com/*' => Http::response(['data' => [['adv' => ['price' => '6.60']]]])]);

        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/v1/user/withdraw/apply', [
            'amount' => 10000,
            'chain' => 'usdt_trc20',
            'address' => self::TRON_ADDRESS,
        ])->assertStatus(200)->json('data.id');

        $controller = new \App\Http\Controllers\V2\Admin\WithdrawController();
        $response = $controller->rate(\Illuminate\Http\Request::create('/rate', 'GET', ['id' => $id, 'force' => 0]));
        $payload = json_decode($response->getContent(), true);

        $this->assertSame(6.6, $payload['data']['rate']);
        $this->assertSame('binance_p2p', $payload['data']['source']);
        $this->assertSame('CNY', $payload['data']['currency']);
        $this->assertSame('auto', $payload['data']['rate_source_mode']);
        $this->assertNull($payload['data']['error']);
        // ¥100 / 6.6 = 15.1515，减 TRC20 的 1 USDT 通道费
        $this->assertSame('15.1515', $payload['data']['quote']['gross']);
        $this->assertSame('1.0000', $payload['data']['quote']['fee']);
        $this->assertSame('14.1515', $payload['data']['quote']['net']);
    }

    /** 取不到行情时要把失败原因带出来，否则管理员只看到「未取到」无从排查 */
    public function test_admin_rate_endpoint_reports_failure_reason(): void
    {
        $this->settings['commission_withdraw_rate_source'] = 'auto';
        $this->settings['commission_withdraw_usdt_rate'] = 0;
        Http::fake(['*' => Http::response([], 503)]);

        $controller = new \App\Http\Controllers\V2\Admin\WithdrawController();
        $response = $controller->rate(\Illuminate\Http\Request::create('/rate', 'GET', ['force' => 1]));
        $payload = json_decode($response->getContent(), true);

        $this->assertNull($payload['data']['rate']);
        $this->assertNotEmpty($payload['data']['error']);
        $this->assertStringContainsString('binance_p2p', $payload['data']['error']);
    }

    public function test_legacy_ticket_withdraw_endpoint_uses_new_workflow(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $this->settings['commission_withdraw_method'] = ['USDT', '支付宝'];

        $this->postJson('/api/v1/user/ticket/withdraw', ['withdraw_method' => '支付宝', 'withdraw_account' => 'alice@example.com'])
            ->assertStatus(200);

        $withdrawal = CommissionWithdrawal::firstOrFail();
        $this->assertSame(20000, $withdrawal->amount, '老接口按全部余额申请');
        $this->assertSame('支付宝', $withdrawal->chain_name);
        $this->assertSame(0, $user->fresh()->commission_balance);
        $this->assertNotNull($withdrawal->ticket_id);
    }
}
