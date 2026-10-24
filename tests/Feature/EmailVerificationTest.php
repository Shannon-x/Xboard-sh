<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\EmailVerification;
use App\Services\Mail\DeliveryMonitor;
use App\Services\OrderService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 邮箱软验证：注册 / 付款后发一次性链接，面板重发与换邮箱，宽限期过后的限制，到期前提醒，注册时的 MX 检查。
 */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1073741824;
    private const APP_URL = 'https://panel.example.test';

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        app()->forgetScopedInstances();
        config([
            'v2board.app_name' => '苏菲家宽', 'v2board.app_url' => self::APP_URL, 'v2board.email_template' => 'editorial',
            'v2board.billing_receipt_enable' => 0,
        ]);
        EmailVerification::$dnsResolver = fn (string $domain) => true;
        $group = new ServerGroup();
        $group->name = '基础';
        $group->save();
        $this->plan = Plan::create([
            'name' => '静态家宽拼车', 'group_id' => $group->id, 'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => null, 'reset_traffic_method' => 1,
            'prices' => ['monthly' => 20],
        ]);
    }

    protected function tearDown(): void
    {
        EmailVerification::$dnsResolver = null;
        EmailVerification::$mxResolver = null;
        parent::tearDown();
    }

    /** @return \Illuminate\Support\Collection<int, \Symfony\Component\Mailer\SentMessage> */
    private function sent()
    {
        return app('mailer')->getSymfonyTransport()->messages();
    }

    private function lastLinkToken(): string
    {
        $html = $this->sent()->last()->getOriginalMessage()->getHtmlBody();
        $this->assertMatchesRegularExpression('#/verify-email/([a-f0-9]{32})#', $html);
        preg_match('#/verify-email/([a-f0-9]{32})#', $html, $m);
        return $m[1];
    }

    /** 邮件正文里那个 6 位验证码（三套模板都把它单独放在一个标签里） */
    private function lastCode(): string
    {
        $html = $this->sent()->last()->getOriginalMessage()->getHtmlBody();
        $this->assertMatchesRegularExpression('#>\s*([0-9]{6})\s*<#', $html);
        preg_match('#>\s*([0-9]{6})\s*<#', $html, $m);
        return $m[1];
    }

    private function user(array $attributes = []): User
    {
        return User::create($attributes + [
            'email' => Str::random(10) . '@example.test', 'password' => Hash::make('secret-pass'), 'uuid' => (string) Str::uuid(), 'token' => bin2hex(random_bytes(16)),
            'plan_id' => $this->plan->id, 'group_id' => $this->plan->group_id, 'balance' => 0,
            'transfer_enable' => 100 * self::GB, 'u' => 0, 'd' => 0, 'device_limit' => 2,
            'remind_expire' => 1, 'remind_traffic' => 1, 'expired_at' => time() + 30 * 86400,
        ]);
    }

    private function order(User $user): Order
    {
        return Order::create([
            'user_id' => $user->id, 'plan_id' => $this->plan->id, 'period' => 'monthly', 'type' => Order::TYPE_NEW_PURCHASE,
            'trade_no' => Helper::generateOrderNo(), 'total_amount' => 2000, 'status' => Order::STATUS_PROCESSING, 'paid_at' => time(),
        ]);
    }

    // ---------------------------------------------------------------- 注册

    public function test_register_enrolls_the_user_and_the_link_verifies_once(): void
    {
        $this->postJson('/api/v1/passport/auth/register', ['email' => 'new@example.test', 'password' => 'secret-pass'])->assertOk();
        $user = User::byEmail('new@example.test')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertNotNull($user->email_verify_started_at);
        $this->assertSame('register', $user->email_verify_source);
        $this->assertCount(1, $this->sent());
        $message = $this->sent()->last()->getOriginalMessage();
        $this->assertSame('new@example.test', $message->getTo()[0]->getAddress());
        $this->assertStringContainsString('请验证', $message->getSubject());
        $this->assertStringContainsString('限制状态', $message->getHtmlBody());
        $this->assertStringContainsString(date('Y-m-d', (int) $user->email_verify_started_at + 14 * 86400), $message->getHtmlBody());
        $token = $this->lastLinkToken();
        $this->assertSame(hash('sha256', $token), $user->email_verify_token);

        // 面板看到的状态
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/user/info')->assertOk()
            ->assertJsonPath('data.email_verification.verified', false)
            ->assertJsonPath('data.email_verification.pending', true)
            ->assertJsonPath('data.email_verification.restricted', false)
            ->assertJsonPath('data.email_verification.days_left', 14);

        // 免登录确认；同一条链接再点（页面二次加载）幂等地回「已验证」，不再 404
        $this->postJson('/api/v1/guest/email-verify/confirm', ['token' => $token])->assertOk()
            ->assertJsonPath('data.changed', false);
        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($user->email_verify_token);
        $this->postJson('/api/v1/guest/email-verify/confirm', ['token' => $token])->assertOk()->assertJsonPath('data.changed', false);
        $this->postJson('/api/v1/guest/email-verify/confirm', ['token' => str_repeat('0', 32)])->assertStatus(404);
        $this->getJson('/api/v1/user/info')->assertOk()->assertJsonPath('data.email_verification.verified', true);
    }

    // ---------------------------------------------------------------- 验证码

    public function test_the_verification_mail_also_carries_a_code_that_verifies_from_the_panel(): void
    {
        $this->postJson('/api/v1/passport/auth/register', ['email' => 'coder@example.test', 'password' => 'secret-pass'])->assertOk();
        $user = User::byEmail('coder@example.test')->firstOrFail();
        $html = $this->sent()->last()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('验证码', $html);
        $code = $this->lastCode();
        $this->assertSame(hash('sha256', $user->id . ':' . $code), $user->email_verify_code);
        $this->assertSame(0, (int) $user->email_verify_code_attempts);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/user/info')->assertOk()
            ->assertJsonPath('data.email_verification.code_sent', true)
            ->assertJsonPath('data.email_verification.code_attempts_left', 5);

        // 格式不对不计次；输错计一次并告知剩余次数
        $this->postJson('/api/v1/user/email-verify/confirm-code', ['code' => 'abc'])->assertStatus(422);
        $this->assertSame(0, (int) $user->fresh()->email_verify_code_attempts);
        $wrong = $code === '000000' ? '000001' : '000000';
        $this->postJson('/api/v1/user/email-verify/confirm-code', ['code' => $wrong])->assertStatus(422)
            ->assertJsonPath('message', '验证码不正确，还可以再试 4 次');
        $this->assertSame(1, (int) $user->fresh()->email_verify_code_attempts);
        $this->getJson('/api/v1/user/info')->assertOk()->assertJsonPath('data.email_verification.code_attempts_left', 4);

        // 输对：验证通过，验证码与链接一起作废
        $this->postJson('/api/v1/user/email-verify/confirm-code', ['code' => ' ' . $code . ' '])->assertOk()
            ->assertJsonPath('data.changed', false)
            ->assertJsonPath('data.status.verified', true)
            ->assertJsonPath('data.status.code_sent', false);
        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->email_verify_code);
        // 验证码作废；同一封邮件里的链接再点只会看到「已验证」
        $this->postJson('/api/v1/guest/email-verify/confirm', ['token' => $this->lastLinkToken()])->assertOk()->assertJsonPath('data.changed', false);
        $this->postJson('/api/v1/user/email-verify/confirm-code', ['code' => $code])->assertStatus(400);
        $this->getJson('/api/v1/user/info')->assertOk()->assertJsonPath('data.email_verification.verified', true);
    }

    public function test_the_code_is_voided_after_five_wrong_attempts_and_when_the_link_expires(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/user/email-verify/send')->assertOk();
        $code = $this->lastCode();
        $wrong = $code === '000000' ? '000001' : '000000';
        for ($i = 1; $i <= 4; $i++) {
            $this->postJson('/api/v1/user/email-verify/confirm-code', ['code' => $wrong])->assertStatus(422);
        }
        $this->postJson('/api/v1/user/email-verify/confirm-code', ['code' => $wrong])->assertStatus(400);
        $user->refresh();
        $this->assertNull($user->email_verify_code);
        $this->assertSame(5, (int) $user->email_verify_code_attempts);
        $this->getJson('/api/v1/user/info')->assertOk()->assertJsonPath('data.email_verification.code_sent', false);
        // 作废之后连正确的也不认；链接本身不受影响
        $this->postJson('/api/v1/user/email-verify/confirm-code', ['code' => $code])->assertStatus(400);
        $this->assertNull($user->email_verified_at);

        // 重新发送：新验证码从零计次
        User::where('id', $user->id)->update(['email_verify_sent_at' => time() - 120]);
        $this->postJson('/api/v1/user/email-verify/send')->assertOk()->assertJsonPath('data.status.code_attempts_left', 5);
        $fresh = $this->lastCode();
        $this->assertCount(2, $this->sent());

        // 链接周期过期后验证码一起过期
        User::where('id', $user->id)->update(['email_verify_token_expires_at' => time() - 1]);
        $this->postJson('/api/v1/user/email-verify/confirm-code', ['code' => $fresh])->assertStatus(400);
        $this->getJson('/api/v1/user/info')->assertOk()->assertJsonPath('data.email_verification.code_sent', false);
        User::where('id', $user->id)->update(['email_verify_token_expires_at' => time() + 3600]);
        $this->postJson('/api/v1/user/email-verify/confirm-code', ['code' => $fresh])->assertOk()->assertJsonPath('data.status.verified', true);
    }

    public function test_a_used_link_keeps_answering_verified_until_it_expires(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/user/email-verify/send')->assertOk();
        $token = $this->lastLinkToken();
        $this->postJson('/api/v1/guest/email-verify/confirm', ['token' => $token])->assertOk();
        $verifiedAt = (int) $user->fresh()->email_verified_at;
        // 邮件 App / QQ 内置浏览器把页面加载两次、用户再点一次：仍回成功，什么都不改
        $this->postJson('/api/v1/guest/email-verify/confirm', ['token' => $token])->assertOk()->assertJsonPath('data.changed', false);
        $this->assertSame($verifiedAt, (int) $user->fresh()->email_verified_at);
        $this->getJson('/api/v1/user/info')->assertOk()->assertJsonPath('data.email_verification.verified', true);
        // 到期后不再认
        User::where('id', $user->id)->update(['email_verify_token_expires_at' => time() - 1]);
        $this->postJson('/api/v1/guest/email-verify/confirm', ['token' => $token])->assertStatus(404);
    }

    public function test_the_change_email_code_swaps_the_address_like_the_link_does(): void
    {
        $user = $this->user(['email_verify_started_at' => time() - 86400]);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/user/email-verify/change', ['email' => 'moved@example.test', 'password' => 'secret-pass'])->assertOk();
        $this->assertSame('moved@example.test', $this->sent()->last()->getOriginalMessage()->getTo()[0]->getAddress());
        $this->postJson('/api/v1/user/email-verify/confirm-code', ['code' => $this->lastCode()])->assertOk()
            ->assertJsonPath('data.changed', true)
            ->assertJsonPath('data.email', 'moved@example.test');
        $user->refresh();
        $this->assertSame('moved@example.test', $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->email_verify_pending_email);
    }

    // ---------------------------------------------------------------- 已验证的用户换邮箱（#3582）

    /**
     * 已验证的账号换邮箱：链接点开要真的换过去，不能被「已验证就直接回」拦住（#3582：之前永远换不成，页面却显示成功）；
     * 换完同一条链接再点，待确认地址已清空，仍幂等地回「已验证」、邮箱不再变。
     */
    public function test_a_verified_user_can_change_email_via_the_link_and_a_second_click_stays_idempotent(): void
    {
        $user = $this->user(['email_verified_at' => time() - 3 * 86400, 'email_verify_started_at' => time() - 4 * 86400, 'email_verify_source' => 'register']);
        $old = $user->email;
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/user/email-verify/change', ['email' => 'moved@example.test', 'password' => 'secret-pass'])->assertOk()
            ->assertJsonPath('data.status.verified', true)
            ->assertJsonPath('data.status.pending', false)
            ->assertJsonPath('data.status.pending_email', 'mo***@example.test')
            // 验证码发在新地址那封邮件里，已验证的人也要看得到输入框
            ->assertJsonPath('data.status.code_sent', true)
            ->assertJsonPath('data.status.code_attempts_left', 5);
        $this->assertSame('moved@example.test', $this->sent()->last()->getOriginalMessage()->getTo()[0]->getAddress());
        // 点开之前什么都没变
        $this->assertSame($old, $user->fresh()->email);

        $token = $this->lastLinkToken();
        $this->postJson('/api/v1/guest/email-verify/confirm', ['token' => $token])->assertOk()
            ->assertJsonPath('data.changed', true)
            ->assertJsonPath('data.email', 'mo***@example.test');
        $user->refresh();
        $this->assertSame('moved@example.test', $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->email_verify_pending_email);
        $this->assertNull($user->email_verify_code);
        $this->assertFalse(User::byEmail($old)->exists());

        // 邮件 App 把页面加载两次 / 用户再点一次：没有待确认地址了，幂等地回「已验证」，邮箱不变
        $this->postJson('/api/v1/guest/email-verify/confirm', ['token' => $token])->assertOk()
            ->assertJsonPath('data.changed', false)
            ->assertJsonPath('data.email', 'mo***@example.test');
        $this->assertSame('moved@example.test', $user->fresh()->email);
        $this->getJson('/api/v1/user/info')->assertOk()
            ->assertJsonPath('data.email', 'moved@example.test')
            ->assertJsonPath('data.email_verification.verified', true)
            ->assertJsonPath('data.email_verification.pending_email', null)
            ->assertJsonPath('data.email_verification.code_sent', false);
    }

    /** 已验证的账号换邮箱：在面板里填新地址那封邮件的 6 位验证码，效果与链接相同；换完验证码立刻作废 */
    public function test_a_verified_user_can_change_email_via_the_code(): void
    {
        $user = $this->user(['email_verified_at' => time() - 86400, 'email_verify_started_at' => time() - 2 * 86400]);
        $old = $user->email;
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/user/email-verify/change', ['email' => 'moved@example.test', 'password' => 'secret-pass'])->assertOk();
        $code = $this->lastCode();

        // 输错照样计次、不换邮箱
        $wrong = $code === '000000' ? '000001' : '000000';
        $this->postJson('/api/v1/user/email-verify/confirm-code', ['code' => $wrong])->assertStatus(422);
        $this->assertSame($old, $user->fresh()->email);
        $this->getJson('/api/v1/user/email-verify/status')->assertOk()
            ->assertJsonPath('data.code_sent', true)
            ->assertJsonPath('data.code_attempts_left', 4);

        $this->postJson('/api/v1/user/email-verify/confirm-code', ['code' => $code])->assertOk()
            ->assertJsonPath('data.changed', true)
            ->assertJsonPath('data.email', 'moved@example.test')
            ->assertJsonPath('data.status.verified', true)
            ->assertJsonPath('data.status.pending_email', null)
            ->assertJsonPath('data.status.code_sent', false);
        $user->refresh();
        $this->assertSame('moved@example.test', $user->email);
        $this->assertNull($user->email_verify_pending_email);
        $this->assertNull($user->email_verify_code);
        // 换完再输同一枚验证码：已验证、也没有待确认地址 → 还是「已验证」
        $this->postJson('/api/v1/user/email-verify/confirm-code', ['code' => $code])->assertStatus(400);
    }

    /** 已验证的账号想换到一个发完链接后被别人注册掉的地址：点开时拒绝，自己的邮箱与已验证状态都不动 */
    public function test_a_verified_user_changing_to_a_taken_address_is_refused_at_confirm(): void
    {
        $user = $this->user(['email_verified_at' => time() - 86400, 'email_verify_started_at' => time() - 2 * 86400]);
        $old = $user->email;
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/user/email-verify/change', ['email' => 'late@example.test', 'password' => 'secret-pass'])->assertOk();
        $this->user(['email' => 'late@example.test']);

        $this->postJson('/api/v1/guest/email-verify/confirm', ['token' => $this->lastLinkToken()])->assertStatus(422);
        $this->postJson('/api/v1/user/email-verify/confirm-code', ['code' => $this->lastCode()])->assertStatus(422);
        $user->refresh();
        $this->assertSame($old, $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_every_template_points_its_button_at_the_link_and_shows_the_code(): void
    {
        foreach (['editorial', 'classic', 'default'] as $i => $template) {
            config(['v2board.email_template' => $template]);
            $user = $this->user();
            Sanctum::actingAs($user);
            $this->postJson('/api/v1/user/email-verify/send')->assertOk();
            // 模板渲染失败时发信任务只记日志不抛错，last() 会拿到上一套模板的邮件：必须按封数确认这一套真的发出去了
            $this->assertCount($i + 1, $this->sent(), $template);
            $html = $this->sent()->last()->getOriginalMessage()->getHtmlBody();
            $link = self::APP_URL . '/verify-email/' . $this->lastLinkToken();
            $this->assertStringContainsString('href="' . $link . '"', $html, $template);
            // 主按钮 / 主链接之外，不能再有指向站点首页的「验证」入口（classic 的按钮曾经指向首页）
            $this->assertDoesNotMatchRegularExpression('#href="' . preg_quote(self::APP_URL, '#') . '/?"\s+class="btn-primary"#', $html, $template);
            $this->assertStringContainsString($this->lastCode(), $html, $template);
            $this->assertStringContainsString('验证码', $html, $template);
        }
    }

    public function test_register_with_email_code_is_verified_immediately_and_disabled_switch_sends_nothing(): void
    {
        config(['v2board.email_verify' => 1]);
        Cache::put(\App\Utils\CacheKey::get('EMAIL_VERIFY_CODE', 'coded@example.test'), '123456', 300);
        $this->postJson('/api/v1/passport/auth/register', ['email' => 'coded@example.test', 'password' => 'secret-pass', 'email_code' => '123456'])->assertOk();
        $this->assertNotNull(User::byEmail('coded@example.test')->value('email_verified_at'));
        $this->assertCount(0, $this->sent());

        config(['v2board.email_verify' => 0, 'v2board.email_verify_nudge_enable' => 0]);
        $this->postJson('/api/v1/passport/auth/register', ['email' => 'off@example.test', 'password' => 'secret-pass'])->assertOk();
        $user = User::byEmail('off@example.test')->firstOrFail();
        $this->assertNull($user->email_verify_started_at);
        $this->assertCount(0, $this->sent());
    }

    public function test_register_rejects_domains_without_mail_servers_but_passes_on_dns_failure(): void
    {
        EmailVerification::$dnsResolver = fn (string $domain) => $domain === 'nomx.example' ? false : null;
        $this->postJson('/api/v1/passport/auth/register', ['email' => 'a@nomx.example', 'password' => 'secret-pass'])
            ->assertStatus(400)->assertJsonPath('message', '这个邮箱的域名无法收信，请检查地址是否写错');
        $this->postJson('/api/v1/passport/auth/register', ['email' => 'a@dnsdown.example', 'password' => 'secret-pass'])->assertOk();

        config(['v2board.email_verify_mx_check' => 0]);
        $this->postJson('/api/v1/passport/auth/register', ['email' => 'b@nomx.example', 'password' => 'secret-pass'])->assertOk();
    }

    public function test_register_rejects_example_own_site_and_disposable_domains(): void
    {
        // 2026-10 渗透号：*@example.com、admin@本站域名、mail.tm 轮换域名（maxxspace.com 的 MX 是 in.mail.tm）
        EmailVerification::$mxResolver = fn (string $domain) => $domain === 'maxxspace.com' ? ['in.mail.tm'] : null;
        foreach (['a@example.com', 'admin@panel.example.test', 'x@maxxspace.com'] as $email) {
            $this->postJson('/api/v1/passport/auth/register', ['email' => $email, 'password' => 'secret-pass'])
                ->assertStatus(400)->assertJsonPath('message', '不支持该邮箱域名，请换一个常用邮箱');
        }
        $this->assertSame(0, User::whereIn('email', ['a@example.com', 'admin@panel.example.test', 'x@maxxspace.com'])->count());
        $this->postJson('/api/v1/passport/auth/register', ['email' => 'student@sjtu.edu.cn', 'password' => 'secret-pass'])->assertOk();
    }

    // ---------------------------------------------------------------- 上线前的老用户

    public function test_users_registered_before_launch_never_have_to_verify(): void
    {
        $old = $this->user();
        $enrolled = $this->user(['email_verify_started_at' => time() - 86400, 'email_verify_source' => 'register']);
        $migration = require database_path('migrations/2026_10_08_000002_exempt_existing_users_from_email_verification.php');
        $migration->up();

        $old->refresh();
        $this->assertNotNull($old->email_verified_at);
        $this->assertSame('legacy', $old->email_verify_source);
        // 已纳入流程的行不碰
        $this->assertNull($enrolled->fresh()->email_verified_at);
        $this->assertSame('register', $enrolled->fresh()->email_verify_source);

        // 付款后不补验、不发信；面板没有横幅、不受限，也不会被「立即验证」拉进流程
        (new OrderService($this->order($old)))->open();
        $this->assertNull($old->fresh()->email_verify_started_at);
        $this->assertCount(0, $this->sent());
        Sanctum::actingAs($old);
        $this->getJson('/api/v1/user/info')->assertOk()
            ->assertJsonPath('data.email_verification.verified', true)
            ->assertJsonPath('data.email_verification.pending', false)
            ->assertJsonPath('data.email_verification.restricted', false);
        $this->postJson('/api/v1/user/email-verify/send')->assertStatus(400);
        $this->assertNull($old->fresh()->email_verify_started_at);
        $this->assertCount(0, $this->sent());

        $migration->down();
        $this->assertNull($old->fresh()->email_verified_at);
        $this->assertNull($old->fresh()->email_verify_source);
    }

    // ---------------------------------------------------------------- 付款开通

    public function test_opening_a_paid_order_enrolls_an_unenrolled_user_once(): void
    {
        $user = $this->user();
        $this->assertNull($user->email_verify_started_at);

        (new OrderService($this->order($user)))->open();
        $user->refresh();
        $this->assertNotNull($user->email_verify_started_at);
        $this->assertSame('order', $user->email_verify_source);
        $this->assertCount(1, $this->sent());

        // 再付一单不再重发、不重置宽限期
        $startedAt = $user->email_verify_started_at;
        (new OrderService($this->order($user)))->open();
        $this->assertSame($startedAt, $user->fresh()->email_verify_started_at);
        $this->assertCount(1, $this->sent());

        // 已验证的用户付款也不纳入
        $verified = $this->user(['email_verified_at' => time()]);
        (new OrderService($this->order($verified)))->open();
        $this->assertNull($verified->fresh()->email_verify_started_at);
        $this->assertCount(1, $this->sent());
    }

    // ---------------------------------------------------------------- 重发与换邮箱

    public function test_resend_has_a_cooldown_and_enrolls_unenrolled_users(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/user/email-verify/send')->assertOk()->assertJsonPath('data.sent', true);
        $this->assertNotNull($user->fresh()->email_verify_started_at);
        $this->assertCount(1, $this->sent());
        $this->postJson('/api/v1/user/email-verify/send')->assertStatus(429);
        $this->assertCount(1, $this->sent());

        // 冷却过后能再发，新链接让旧链接作废
        $old = $this->lastLinkToken();
        User::where('id', $user->id)->update(['email_verify_sent_at' => time() - 120]);
        $this->postJson('/api/v1/user/email-verify/send')->assertOk();
        $this->assertCount(2, $this->sent());
        $this->postJson('/api/v1/guest/email-verify/confirm', ['token' => $old])->assertStatus(404);
        $this->postJson('/api/v1/guest/email-verify/confirm', ['token' => $this->lastLinkToken()])->assertOk();

        $this->postJson('/api/v1/user/email-verify/send')->assertStatus(400);
    }

    public function test_change_email_sends_the_link_to_the_new_address_and_swaps_on_confirm(): void
    {
        $user = $this->user(['email_verify_started_at' => time() - 86400, 'mail_suppressed_at' => time(), 'mail_suppressed_reason' => DeliveryMonitor::SUPPRESSED]);
        $old = $user->email;
        $taken = $this->user();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/user/email-verify/change', ['email' => 'fresh@example.test', 'password' => 'wrong'])->assertStatus(403);
        $this->postJson('/api/v1/user/email-verify/change', ['email' => $taken->email, 'password' => 'secret-pass'])->assertStatus(422);
        $this->postJson('/api/v1/user/email-verify/change', ['email' => 'not-an-email', 'password' => 'secret-pass'])->assertStatus(422);
        $this->postJson('/api/v1/user/email-verify/change', ['email' => $old, 'password' => 'secret-pass'])->assertStatus(422);
        $this->assertCount(0, $this->sent());

        $this->postJson('/api/v1/user/email-verify/change', ['email' => 'Fresh@Example.test', 'password' => 'secret-pass'])->assertOk()
            ->assertJsonPath('data.status.pending_email', 'fr***@example.test');
        $this->assertCount(1, $this->sent());
        $message = $this->sent()->last()->getOriginalMessage();
        $this->assertSame('fresh@example.test', $message->getTo()[0]->getAddress());
        $this->assertStringContainsString('确认', $message->getSubject());
        // 点开之前什么都没变
        $this->assertSame($old, $user->fresh()->email);

        $this->postJson('/api/v1/guest/email-verify/confirm', ['token' => $this->lastLinkToken()])->assertOk()
            ->assertJsonPath('data.changed', true);
        $user->refresh();
        $this->assertSame('fresh@example.test', $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->email_verify_pending_email);
        // 新地址收得到信：退信暂停解除
        $this->assertNull($user->mail_suppressed_at);
    }

    // ---------------------------------------------------------------- 限制

    public function test_features_mode_blocks_tickets_and_commission_but_never_orders(): void
    {
        $user = $this->user(['email_verify_started_at' => time() - 15 * 86400, 'commission_balance' => 5000]);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/user/info')->assertOk()
            ->assertJsonPath('data.email_verification.restricted', true)
            ->assertJsonPath('data.email_verification.days_left', 0)
            ->assertJsonPath('data.email_verification.restrict_mode', 'features');

        // 下单 / 续费永远不拦（shannon：不要影响收钱）
        $this->postJson('/api/v1/user/order/save', ['plan_id' => $this->plan->id, 'period' => 'monthly'])->assertOk();

        // 普通工单要先验证，支付类工单放行
        $blocked = $this->postJson('/api/v1/user/ticket/save', ['subject' => '测试', 'level' => 0, 'message' => '内容']);
        $blocked->assertStatus(403)->assertJsonPath('message', '请先验证您的邮箱')->assertJsonPath('error.reason', 'email_unverified');
        $this->postJson('/api/v1/user/ticket/save', ['subject' => '测试', 'level' => 0, 'message' => '内容', 'category' => 'connection'])->assertStatus(403);
        $this->postJson('/api/v1/user/ticket/save', ['subject' => '付款没到账', 'level' => 0, 'message' => '订单已付款', 'category' => 'billing'])->assertOk();

        // 佣金提现（新旧两个入口）和佣金转余额都挡
        $this->postJson('/api/v1/user/withdraw/apply', ['amount' => 1, 'chain' => 'TRC20', 'address' => 'x'])->assertStatus(403);
        $this->postJson('/api/v1/user/ticket/withdraw', ['withdraw_method' => 'USDT', 'withdraw_account' => 'x'])->assertStatus(403);
        $this->postJson('/api/v1/user/transfer', ['transfer_amount' => 100])->assertStatus(403);
        $this->assertSame(5000, (int) $user->refresh()->commission_balance);
        // 订阅照常
        $this->assertFalse(EmailVerification::blocksSubscribe($user));

        // 宽限期内不挡
        $inGrace = $this->user(['email_verify_started_at' => time() - 86400]);
        Sanctum::actingAs($inGrace);
        $this->postJson('/api/v1/user/ticket/save', ['subject' => '测试', 'level' => 0, 'message' => '内容'])->assertOk();

        // 验证之后解除
        EmailVerification::markVerified($user);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/user/transfer', ['transfer_amount' => 100])->assertOk();
        $this->assertSame(4900, (int) $user->refresh()->commission_balance);

        // 后台关掉限制
        $other = $this->user(['email_verify_started_at' => time() - 15 * 86400]);
        config(['v2board.email_verify_restrict_mode' => 'none']);
        $this->assertFalse(EmailVerification::restricted($other));
    }

    public function test_subscribe_mode_hides_the_user_from_subscription_and_nodes(): void
    {
        config(['v2board.email_verify_restrict_mode' => 'subscribe']);
        $expired = $this->user(['email_verify_started_at' => time() - 15 * 86400]);
        $inGrace = $this->user(['email_verify_started_at' => time() - 86400]);
        $legacy = $this->user();
        $verified = $this->user(['email_verify_started_at' => time() - 15 * 86400, 'email_verified_at' => time()]);

        $this->assertTrue(EmailVerification::blocksSubscribe($expired));
        $this->assertFalse(EmailVerification::blocksSubscribe($inGrace));
        $this->assertFalse(EmailVerification::blocksSubscribe($legacy));
        $this->assertFalse(EmailVerification::blocksSubscribe($verified));

        $ids = EmailVerification::scopeNotBlocked(User::query())->pluck('id')->all();
        $this->assertNotContains($expired->id, $ids);
        $this->assertContains($inGrace->id, $ids);
        $this->assertContains($legacy->id, $ids);
        $this->assertContains($verified->id, $ids);

        $this->get('/api/v1/client/subscribe?token=' . $expired->token)->assertStatus(403);
        $ok = $this->get('/api/v1/client/subscribe?token=' . $inGrace->token);
        $this->assertSame(200, $ok->status(), substr((string) $ok->getContent(), 0, 300));

        config(['v2board.email_verify_restrict_mode' => 'features']);
        $this->get('/api/v1/client/subscribe?token=' . $expired->token)->assertOk();
    }

    // ---------------------------------------------------------------- 提醒

    public function test_reminder_goes_out_once_in_the_last_days_of_the_grace_period(): void
    {
        $due = $this->user(['email_verify_started_at' => time() - 12 * 86400]);      // 还剩 2 天
        $early = $this->user(['email_verify_started_at' => time() - 5 * 86400]);     // 还剩 9 天
        $over = $this->user(['email_verify_started_at' => time() - 20 * 86400]);     // 已过期
        $bounced = $this->user(['email_verify_started_at' => time() - 12 * 86400, 'mail_suppressed_at' => time()]);
        $this->user(['email_verify_started_at' => time() - 12 * 86400, 'email_verified_at' => time()]);

        $this->artisan('email-verify:remind')->assertExitCode(0);
        $this->assertCount(1, $this->sent());
        $this->assertSame($due->email, $this->sent()->last()->getOriginalMessage()->getTo()[0]->getAddress());
        $this->assertStringContainsString('提醒', $this->sent()->last()->getOriginalMessage()->getSubject());
        $this->assertNotNull($due->fresh()->email_verify_reminded_at);
        $this->assertNull($early->fresh()->email_verify_reminded_at);
        $this->assertNull($over->fresh()->email_verify_reminded_at);
        $this->assertNull($bounced->fresh()->email_verify_reminded_at);

        $this->artisan('email-verify:remind')->assertExitCode(0);
        $this->assertCount(1, $this->sent());
        // 提醒里的链接同样能验证
        $this->postJson('/api/v1/guest/email-verify/confirm', ['token' => $this->lastLinkToken()])->assertOk();
    }

    // ---------------------------------------------------------------- 后台

    public function test_admin_can_verify_resend_and_reset(): void
    {
        $admin = $this->user(['is_admin' => 1]);
        $user = $this->user();
        Sanctum::actingAs($admin, ['admin']);
        $path = '/api/v2/' . admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))) . '/user/emailVerify';

        $this->postJson($path, ['id' => $user->id, 'action' => 'send'])->assertOk()->assertJsonPath('data.pending', true);
        $this->assertCount(1, $this->sent());
        $this->postJson($path, ['id' => $user->id, 'action' => 'verify'])->assertOk()->assertJsonPath('data.verified', true);
        $this->postJson($path, ['id' => $user->id, 'action' => 'send'])->assertStatus(400);
        $this->postJson($path, ['id' => $user->id, 'action' => 'reset'])->assertOk()
            ->assertJsonPath('data.verified', false)->assertJsonPath('data.days_left', 14);
        $this->postJson($path, ['id' => $user->id, 'action' => 'nope'])->assertStatus(422);
    }
}
