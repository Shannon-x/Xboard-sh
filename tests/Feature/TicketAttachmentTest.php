<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 工单附件：上传校验 → 随消息绑定 → 免登录下载 → 清理任务。
 *
 * Setting 用内存桩替换（真实实现走 redis 缓存），local 盘用 Storage::fake。
 */
class TicketAttachmentTest extends TestCase
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
            'ticket_attachment_max_count' => 2,
            'ticket_attachment_daily_quota_mb' => 2,
            'ticket_attachment_retention_days' => 30,
        ];
    }

    private function makeUser(): User
    {
        $user = User::create([
            'email' => 'attach-' . Str::random(6) . '@example.com',
            'password' => 'x',
            'uuid' => Str::uuid()->toString(),
            'token' => bin2hex(random_bytes(16)),
            'balance' => 0,
            'transfer_enable' => 0,
            'expired_at' => 0,
            'plan_id' => null,
            'group_id' => null,
        ]);
        Sanctum::actingAs($user);
        return $user;
    }

    private function png(string $name = 'shot.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG_BASE64));
    }

    public function test_upload_is_rejected_when_feature_disabled(): void
    {
        $this->settings['ticket_attachment_enable'] = 0;
        $this->makeUser();

        $this->post('/api/v1/user/ticket/attachment/upload', ['file' => $this->png()])
            ->assertStatus(400);
        $this->assertSame(0, TicketAttachment::count());
    }

    public function test_multipart_upload_stores_pending_image_with_dimensions(): void
    {
        $user = $this->makeUser();

        $resp = $this->post('/api/v1/user/ticket/attachment/upload', ['file' => $this->png()])
            ->assertStatus(200)
            ->assertJsonPath('data.is_image', true)
            ->assertJsonPath('data.width', 1)
            ->assertJsonPath('data.height', 1)
            ->assertJsonPath('data.name', 'shot.png');

        $attachment = TicketAttachment::findOrFail($resp->json('data.id'));
        $this->assertSame($user->id, $attachment->user_id);
        $this->assertNull($attachment->ticket_message_id);
        $this->assertSame('local', $attachment->driver);
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertStringContainsString("/api/v1/guest/ticket/attachment/{$attachment->id}/{$attachment->access_key}", $resp->json('data.path'));
    }

    public function test_base64_json_upload_normalizes_extension_by_content(): void
    {
        $this->makeUser();

        // 剪贴板粘贴常见形态：文件名不可靠，类型以内容为准
        $this->postJson('/api/v1/user/ticket/attachment/upload', [
            'name' => 'image.jpg',
            'content' => 'data:image/png;base64,' . self::PNG_BASE64,
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.mime', 'image/png')
            ->assertJsonPath('data.name', 'image.png');
    }

    public function test_upload_rejects_oversized_disallowed_and_mismatched_files(): void
    {
        $this->makeUser();

        $big = UploadedFile::fake()->createWithContent('big.txt', str_repeat('a', 1024 * 1024 + 1));
        $this->post('/api/v1/user/ticket/attachment/upload', ['file' => $big])->assertStatus(400);

        $exe = UploadedFile::fake()->createWithContent('tool.exe', 'MZ' . str_repeat("\0", 64));
        $this->post('/api/v1/user/ticket/attachment/upload', ['file' => $exe])->assertStatus(400);

        // 顶着 .png 的纯文本
        $fake = UploadedFile::fake()->createWithContent('fake.png', '<html>not an image</html>');
        $this->post('/api/v1/user/ticket/attachment/upload', ['file' => $fake])->assertStatus(400);

        $this->assertSame(0, TicketAttachment::count());
    }

    public function test_pending_count_and_daily_quota_are_enforced(): void
    {
        $user = $this->makeUser();

        $this->post('/api/v1/user/ticket/attachment/upload', ['file' => $this->png('a.png')])->assertStatus(200);
        $this->post('/api/v1/user/ticket/attachment/upload', ['file' => $this->png('b.png')])->assertStatus(200);
        // max_count = 2：第三个待绑定附件被拒
        $this->post('/api/v1/user/ticket/attachment/upload', ['file' => $this->png('c.png')])->assertStatus(400);

        // 把今日额度（2MB）几乎用光后，再传一个 900KB 的文本就超额
        TicketAttachment::create([
            'user_id' => $user->id,
            'ticket_id' => 1,
            'ticket_message_id' => 1,
            'driver' => 'local',
            'path' => 'ticket-attachments/x.txt',
            'original_name' => 'x.txt',
            'mime' => 'text/plain',
            'size' => 1024 * 1024 + 300 * 1024,
            'is_image' => false,
            'access_key' => bin2hex(random_bytes(16)),
        ]);
        TicketAttachment::where('user_id', $user->id)->whereNull('ticket_message_id')->delete();
        $text = UploadedFile::fake()->createWithContent('log.txt', str_repeat('l', 900 * 1024));
        $this->post('/api/v1/user/ticket/attachment/upload', ['file' => $text])->assertStatus(400);
    }

    public function test_attachment_binds_to_message_and_is_downloadable_only_after_sending(): void
    {
        $user = $this->makeUser();

        $id = $this->post('/api/v1/user/ticket/attachment/upload', ['file' => $this->png()])->json('data.id');
        $attachment = TicketAttachment::findOrFail($id);
        $downloadPath = $attachment->downloadPath();

        // 未随消息发出前不可下载
        $this->get($downloadPath)->assertStatus(404);

        $this->postJson('/api/v1/user/ticket/save', [
            'subject' => '截图看不懂',
            'level' => 1,
            'message' => '见附件',
            'attachment_ids' => [$id],
        ])->assertStatus(200);

        $ticket = Ticket::where('user_id', $user->id)->firstOrFail();
        $attachment->refresh();
        $this->assertSame($ticket->id, $attachment->ticket_id);
        $this->assertNotNull($attachment->ticket_message_id);

        // 用户端详情带 attachments
        $detail = $this->getJson('/api/v1/user/ticket/fetch?id=' . $ticket->id)->assertStatus(200);
        $this->assertSame($id, $detail->json('data.message.0.attachments.0.id'));

        // 免登录下载（guest 路由不看登录态），内联图片 + nosniff
        $resp = $this->get($downloadPath);
        $resp->assertStatus(200);
        $resp->assertHeader('Content-Type', 'image/png');
        $resp->assertHeader('X-Content-Type-Options', 'nosniff');

        // 错误的 key 一律 404
        $this->get("/api/v1/guest/ticket/attachment/{$id}/" . str_repeat('0', 32))->assertStatus(404);

        // 已发出的附件不能再由用户撤回
        $this->postJson('/api/v1/user/ticket/attachment/delete', ['id' => $id])->assertStatus(400);
    }

    public function test_reply_allows_attachment_only_message_but_rejects_foreign_attachment(): void
    {
        $owner = $this->makeUser();
        $ticket = Ticket::create(['user_id' => $owner->id, 'subject' => 's', 'level' => 0]);
        TicketMessage::create(['user_id' => $owner->id, 'ticket_id' => $ticket->id, 'message' => 'hi']);

        $id = $this->post('/api/v1/user/ticket/attachment/upload', ['file' => $this->png()])->json('data.id');

        // 只发附件、正文为空
        $this->postJson('/api/v1/user/ticket/reply', ['id' => $ticket->id, 'message' => '', 'attachment_ids' => [$id]])
            ->assertStatus(200);
        $this->assertSame(2, TicketMessage::where('ticket_id', $ticket->id)->count());

        // 别人的（已绑定的）附件 id 不能再被绑定
        $other = $this->makeUser();
        $otherTicket = Ticket::create(['user_id' => $other->id, 'subject' => 's2', 'level' => 0]);
        TicketMessage::create(['user_id' => $other->id, 'ticket_id' => $otherTicket->id, 'message' => 'hi']);
        $this->postJson('/api/v1/user/ticket/reply', ['id' => $otherTicket->id, 'message' => 'x', 'attachment_ids' => [$id]])
            ->assertStatus(400);
    }

    public function test_cleanup_command_removes_expired_pending_and_dangling_attachments(): void
    {
        $user = $this->makeUser();
        $disk = Storage::disk('local');
        $make = function (array $attrs) use ($user, $disk): TicketAttachment {
            $path = 'ticket-attachments/' . Str::random(8) . '.png';
            $disk->put($path, 'x');
            return TicketAttachment::create($attrs + [
                'user_id' => $user->id,
                'driver' => 'local',
                'path' => $path,
                'original_name' => 'a.png',
                'mime' => 'image/png',
                'size' => 1,
                'is_image' => true,
                'access_key' => bin2hex(random_bytes(16)),
            ]);
        };
        $ticket = Ticket::create(['user_id' => $user->id, 'subject' => 's', 'level' => 0]);
        $message = TicketMessage::create(['user_id' => $user->id, 'ticket_id' => $ticket->id, 'message' => 'hi']);

        $fresh = $make(['ticket_id' => $ticket->id, 'ticket_message_id' => $message->id]);
        $expired = $make(['ticket_id' => $ticket->id, 'ticket_message_id' => $message->id, 'created_at' => time() - 31 * 86400]);
        $stalePending = $make(['created_at' => time() - 2 * 86400]);
        $freshPending = $make([]);
        $dangling = $make(['ticket_id' => 999999, 'ticket_message_id' => 999999]);

        $this->artisan('ticket:clean-attachments')->assertExitCode(0);

        $this->assertNotNull(TicketAttachment::find($fresh->id));
        $this->assertNotNull(TicketAttachment::find($freshPending->id));
        foreach ([$expired, $stalePending, $dangling] as $gone) {
            $this->assertNull(TicketAttachment::find($gone->id));
            $disk->assertMissing($gone->path);
        }
        $disk->assertExists($fresh->path);
    }
}
