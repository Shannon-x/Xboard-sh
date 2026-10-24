<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\EmailVerification;
use App\Services\TicketAttachment\Storage\S3AttachmentStorage;
use App\Support\Setting;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 2026-10-23 渗透第四波的上传样本逐条回放：HTML 改名 .txt / .log、a.php.txt、GIF89a<?php 多态图、
 * 开不了工单的小号照样能上传、存储里原样写着 text/html；以及注册时的示例域名 / 本站域名 / 一次性邮箱。
 *
 * Setting 用内存桩替换（同 TicketAttachmentTest），local 盘用 Storage::fake。
 */
class TicketUploadHardeningTest extends TestCase
{
    use RefreshDatabase;

    /** 1x1 透明 PNG */
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private array $settings = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

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
            'ticket_attachment_enable' => 1,
            'ticket_attachment_driver' => 'local',
            'ticket_attachment_max_size_mb' => 1,
            'ticket_attachment_max_count' => 5,
            'ticket_attachment_daily_quota_mb' => 5,
        ];
    }

    protected function tearDown(): void
    {
        EmailVerification::$mxResolver = null;
        parent::tearDown();
    }

    private function makeUser(array $overrides = []): User
    {
        $user = User::create($overrides + [
            'email' => 'harden-' . Str::random(6) . '@example.com',
            'password' => 'x',
            'uuid' => Str::uuid()->toString(),
            'token' => bin2hex(random_bytes(16)),
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'expired_at' => 0,
            'plan_id' => null,
            'group_id' => null,
        ]);
        Sanctum::actingAs($user);
        return $user;
    }

    /**
     * 走 JSON base64 通道（前端主题就是这么传的）：服务端落临时文件后用 finfo 按真实内容探测类型。
     * UploadedFile::fake() 的 getMimeType() 只按扩展名猜，测不出「HTML 改名 .txt」这类伪装。
     */
    private function upload(string $name, string $content)
    {
        return $this->postJson('/api/v1/user/ticket/attachment/upload', [
            'name' => $name,
            'content' => base64_encode($content),
        ]);
    }

    // ---------------------------------------------------------------- 上传门槛

    public function test_upload_is_gated_like_opening_a_ticket(): void
    {
        $this->settings['ticket_active_subscription_required'] = 1;
        $user = $this->makeUser();

        // 没订阅、没余额、没下过单：开不了工单，也传不了附件
        $this->upload('shot.png', base64_decode(self::PNG_BASE64))->assertStatus(400);
        $this->assertSame(0, TicketAttachment::count());

        // 已经有未关闭的工单（开单时过了门槛）：给自己的工单补截图照常放行
        Ticket::create([
            'user_id' => $user->id, 'subject' => '补图', 'level' => 1,
            'status' => Ticket::STATUS_OPENING, 'reply_status' => Ticket::REPLY_STATUS_PENDING,
        ]);
        $this->upload('shot.png', base64_decode(self::PNG_BASE64))->assertStatus(200);
    }

    public function test_subscribers_and_gate_off_can_upload(): void
    {
        $this->settings['ticket_active_subscription_required'] = 1;
        $this->makeUser(['plan_id' => 1, 'expired_at' => time() + 30 * 86400]);
        $this->upload('shot.png', base64_decode(self::PNG_BASE64))->assertStatus(200);

        $this->settings['ticket_active_subscription_required'] = 0;
        $this->makeUser();
        $this->upload('shot.png', base64_decode(self::PNG_BASE64))->assertStatus(200);
    }

    // ---------------------------------------------------------------- 内容与文件名

    public function test_html_disguised_as_text_or_log_is_rejected(): void
    {
        $this->makeUser();

        $this->upload('i8587x.txt', '<html><body><script>alert(document.domain)</script>hi</body></html>')->assertStatus(400);
        $this->upload('i9671c.log', "<script>document.title='XSS'</script><b>log</b>")->assertStatus(400);
        // finfo 认成 text/plain 的也不行：内容扫描兜底
        $this->upload('notes.txt', "see below\n\n<iframe src=//evil.example></iframe>")->assertStatus(400);
        $this->assertSame(0, TicketAttachment::count());

        // 正常的纯文本日志照收
        $this->upload('client.log', "2026-10-24 12:00:00 dial tcp: i/o timeout\n")
            ->assertStatus(200)
            ->assertJsonPath('data.mime', 'text/plain');
    }

    public function test_double_extension_is_rejected_for_files_and_stripped_for_images(): void
    {
        $this->makeUser();

        $this->upload('i8587.php.txt', 'hello')->assertStatus(400);
        $this->upload('report.html.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n")->assertStatus(400);
        $this->assertSame(0, TicketAttachment::count());

        // 图片的扩展名按内容改写，展示名里的伪装段去掉即可
        $this->upload('shot.php.png', base64_decode(self::PNG_BASE64))
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'shot.png');
    }

    public function test_image_polyglot_carrying_code_is_rejected(): void
    {
        $this->makeUser();

        $gifHeader = "GIF89a\x01\x00\x01\x00\x00\x00\x00";
        $this->upload('a.gif', $gifHeader . '<?php echo md5(1);?>')->assertStatus(400);
        $this->upload('b.gif', $gifHeader . '<script>alert(1)</script>')->assertStatus(400);
        $this->assertSame(0, TicketAttachment::count());
    }

    public function test_bmp_and_avif_are_accepted_as_inline_images(): void
    {
        $this->settings['ticket_attachment_allowed_extensions'] = 'jpg,jpeg,png,gif,webp,bmp,avif,pdf';
        $this->makeUser();

        if (!function_exists('imagebmp')) {
            $this->markTestSkipped('GD 未编译 BMP 支持');
        }
        $img = imagecreatetruecolor(2, 3);
        ob_start();
        imagebmp($img);
        $bmp = (string) ob_get_clean();
        $this->upload('scan.bmp', $bmp)
            ->assertStatus(200)
            ->assertJsonPath('data.is_image', true)
            ->assertJsonPath('data.mime', 'image/bmp')
            ->assertJsonPath('data.width', 2)
            ->assertJsonPath('data.height', 3)
            ->assertJsonPath('data.name', 'scan.bmp');

        if (function_exists('imageavif')) {
            ob_start();
            $ok = @imageavif($img);
            $avif = (string) ob_get_clean();
            if ($ok && $avif !== '') {
                $this->upload('photo.avif', $avif)
                    ->assertStatus(200)
                    ->assertJsonPath('data.is_image', true)
                    ->assertJsonPath('data.mime', 'image/avif');
            }
        }
    }

    // ---------------------------------------------------------------- 存储与下载

    public function test_download_never_echoes_a_stored_html_mime(): void
    {
        $user = $this->makeUser();
        $ticket = Ticket::create([
            'user_id' => $user->id, 'subject' => 'x', 'level' => 1,
            'status' => Ticket::STATUS_OPENING, 'reply_status' => Ticket::REPLY_STATUS_PENDING,
        ]);
        $message = TicketMessage::create(['user_id' => $user->id, 'ticket_id' => $ticket->id, 'message' => 'x']);
        // 历史数据：修复前收下的 HTML 伪装 .txt，库里记着 text/html
        Storage::disk('local')->put('ticket-attachments/2026/10/old.txt', '<html><script>alert(1)</script></html>');
        $attachment = TicketAttachment::create([
            'user_id' => $user->id, 'ticket_id' => $ticket->id, 'ticket_message_id' => $message->id,
            'driver' => 'local', 'path' => 'ticket-attachments/2026/10/old.txt', 'original_name' => 'old.txt',
            'mime' => 'text/html', 'size' => 39, 'is_image' => false, 'access_key' => bin2hex(random_bytes(16)),
        ]);

        $resp = $this->get("/api/v1/guest/ticket/attachment/{$attachment->id}/{$attachment->access_key}")->assertOk();
        $this->assertStringStartsWith('application/octet-stream', (string) $resp->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment', (string) $resp->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $resp->headers->get('X-Content-Type-Options'));
    }

    public function test_s3_objects_for_non_images_carry_an_attachment_disposition(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200), new Response(200)]));
        $stack->push(Middleware::history($history));
        $storage = new S3AttachmentStorage([
            'endpoint' => 'https://s3.example.test', 'region' => 'auto', 'bucket' => 'b',
            'access_key' => 'ak', 'secret_key' => 'sk', 'path_style' => true, 'prefix' => 'p', 'public_url' => '',
        ], new Client(['handler' => $stack, 'http_errors' => false]));

        $tmp = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($tmp, 'x');
        try {
            $storage->put('p/a.txt', $tmp, 'application/octet-stream');
            $storage->put('p/b.png', $tmp, 'image/png');
        } finally {
            @unlink($tmp);
        }

        $this->assertSame('application/octet-stream', $history[0]['request']->getHeaderLine('content-type'));
        $this->assertSame('attachment', $history[0]['request']->getHeaderLine('content-disposition'));
        $this->assertSame('image/png', $history[1]['request']->getHeaderLine('content-type'));
        $this->assertFalse($history[1]['request']->hasHeader('content-disposition'));
    }

    // ---------------------------------------------------------------- 注册邮箱域名

    public function test_blocked_email_domains(): void
    {
        $this->settings['app_url'] = 'https://www.sufe.us';
        $this->settings['email_verify_mx_check'] = 1;
        EmailVerification::$mxResolver = fn (string $domain) => [
            'maxxspace.com' => ['in.mail.tm'],
            'gmail.com' => ['gmail-smtp-in.l.google.com'],
            'nullmx.org' => [''],
            'linshiyou.com' => ['isaac.mx.cloudflare.net'],
        ][$domain] ?? null;

        foreach (['a@example.com', 'a@mx.example.org', 'admin@sufe.us', 'support@mail.sufe.us', 'x@mail.tm', 'x@maxxspace.com', 'x@nullmx.org'] as $email) {
            $this->assertTrue(EmailVerification::domainBlocked($email), "{$email} 应被拒");
        }
        // 正常邮箱、学校邮箱、DNS 查不到的、Cloudflare 收信的个人域名都放行
        foreach (['x@gmail.com', 'x@sjtu.edu.cn', 'x@mail.ustc.edu.cn', 'x@stanford.edu', 'x@dnsdown.org', 'x@linshiyou.com', 'x@sufe.us.evil.org'] as $email) {
            $this->assertFalse(EmailVerification::domainBlocked($email), "{$email} 不应被拒");
        }

        $this->settings['email_blocked_domains'] = 'linshiyou.com, @Burner.example';
        $this->assertTrue(EmailVerification::domainBlocked('x@linshiyou.com'));
        $this->assertTrue(EmailVerification::domainBlocked('x@burner.example'));

        // 关掉 MX 检查后只按域名名单拦
        $this->settings['email_verify_mx_check'] = 0;
        $this->assertFalse(EmailVerification::domainBlocked('x@maxxspace.com'));
        $this->assertTrue(EmailVerification::domainBlocked('x@example.com'));
    }
}
