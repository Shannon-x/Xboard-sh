<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Tests\TestCase;

/**
 * notify 模板的正文转义只做一次：三套模板都用 nl2br(e($content))，
 * 发件方传纯文本即可。以前群发在 MailService 里先 e() 一遍、投递日报先 nl2br(e()) 一遍，
 * 主机上的旧模板原样输出时没问题；模板换成转义版后，邮件里就出现了 &amp;#039; 和字面的 <br />。
 */
class NotifyMailEscapingTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPLATES = ['default', 'classic', 'editorial'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        app()->forgetScopedInstances();
        config(['v2board.app_name' => '苏菲家宽', 'v2board.app_url' => 'https://www.sufe.test']);
    }

    private function lastHtml(): string
    {
        $messages = app('mailer')->getSymfonyTransport()->messages()->all();
        $this->assertNotEmpty($messages);
        return (string) end($messages)->getOriginalMessage()->getHtmlBody();
    }

    public function test_mass_mail_body_is_escaped_exactly_once_in_every_template(): void
    {
        foreach (self::TEMPLATES as $template) {
            config(['v2board.email_template' => $template]);
            $result = MailService::sendEmail([
                'email' => 'reader@example.test',
                'subject' => '国庆活动',
                'template_name' => 'notify',
                'template_value' => [
                    'name' => '苏菲家宽',
                    'url' => 'https://www.sufe.test',
                    // 后台群发的写法：纯文本 + 占位符
                    'content' => "Tom & Jerry's <b>sale</b> for {{user.email}}\n活动链接 https://www.sufe.test/plans?a=1&b=2",
                    'vars' => ['user.email' => 'reader@example.test'],
                    'content_mode' => 'text',
                ],
            ]);
            $this->assertNull($result['error'], $template);

            $html = $this->lastHtml();
            $this->assertStringContainsString('Tom &amp; Jerry&#039;s &lt;b&gt;sale&lt;/b&gt; for reader@example.test<br />', $html, $template);
            $this->assertStringContainsString('/plans?a=1&amp;b=2', $html, $template);
            $this->assertStringNotContainsString('&amp;amp;', $html, "{$template}: 正文被转义了两次");
            $this->assertStringNotContainsString('&amp;#039;', $html, "{$template}: 正文被转义了两次");
            $this->assertStringNotContainsString('<b>sale</b>', $html, "{$template}: 群发正文不能当 HTML 输出");
        }
    }

    public function test_plain_text_notices_such_as_ticket_replies_render_with_line_breaks(): void
    {
        config(['v2board.email_template' => 'editorial']);
        MailService::sendEmail([
            'email' => 'reader@example.test',
            'subject' => '工单回复',
            'template_name' => 'notify',
            'template_value' => ['name' => '苏菲家宽', 'url' => 'https://www.sufe.test', 'content' => "主题：A & B\r\n回复内容：<script>x</script>"],
        ]);
        $html = $this->lastHtml();
        $this->assertStringContainsString('主题：A &amp; B<br />', $html);
        $this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>x</script>', $html);
    }

    public function test_delivery_digest_mail_shows_real_line_breaks(): void
    {
        config(['v2board.email_template' => 'editorial']);
        User::create([
            'email' => 'admin@example.test', 'password' => 'x', 'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'is_admin' => 1, 'balance' => 0, 'transfer_enable' => 0, 'u' => 0, 'd' => 0,
        ]);

        // QUEUE_CONNECTION=sync：日报的 SendEmailJob 当场执行，邮件进 array transport
        $this->artisan('mail:delivery-digest', ['--force' => true])->assertExitCode(0);

        $html = $this->lastHtml();
        $this->assertStringContainsString('<br />', $html);
        $this->assertStringNotContainsString('&lt;br', $html, '日报正文里不能出现字面的 <br />');
        $this->assertStringNotContainsString('&amp;', $html);
    }

    public function test_reused_smtp_connection_is_pinged_after_a_short_idle(): void
    {
        // 队列进程复用 SMTP 连接：闲置超过阈值先 NOOP，OCI 断开的旧连接会被发现并重连，而不是撞上 421
        $transport = new EsmtpTransport('127.0.0.1', 2525, false);
        Mail::mailer()->setSymfonyTransport($transport);
        MailService::keepSmtpConnectionFresh();
        $threshold = (new \ReflectionProperty(SmtpTransport::class, 'pingThreshold'))->getValue($transport);
        $this->assertSame(MailService::SMTP_PING_THRESHOLD, $threshold);
        $this->assertLessThanOrEqual(10, $threshold);
    }

    public function test_keep_alive_is_a_no_op_for_non_smtp_transports(): void
    {
        MailService::keepSmtpConnectionFresh();   // 测试环境是 array transport
        $this->assertNotInstanceOf(EsmtpTransport::class, Mail::mailer()->getSymfonyTransport());
    }
}
