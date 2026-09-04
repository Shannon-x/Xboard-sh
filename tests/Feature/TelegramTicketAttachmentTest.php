<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramAttachmentJob;
use App\Jobs\SendTelegramJob;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Plugin\Telegram\Plugin as TelegramPlugin;
use Tests\TestCase;

/**
 * Telegram 插件 × 工单附件：
 *   - 通知把附件逐个排进队列（图片 sendPhoto / 其它 sendDocument）
 *   - 管理员在 Telegram 回复时附带的图片被下载并存为工单附件
 *   - 非管理员不能通过 Telegram 回复工单（越权修复）
 *   - webhook 能解析只有 photo + caption、没有 text 的消息
 *
 * Telegram API 全部 Http::fake；插件直接 new 出来 boot，不走 v2_plugins 表。
 */
class TelegramTicketAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private const BOT_TOKEN = '123456:ABCDEF-bot-token';
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
            'telegram_bot_token' => self::BOT_TOKEN,
            'ticket_attachment_enable' => 1,
            'ticket_attachment_driver' => 'local',
            'ticket_attachment_max_size_mb' => 5,
            'ticket_attachment_max_count' => 5,
            'ticket_attachment_daily_quota_mb' => 30,
        ];
    }

    private function makeUser(array $attrs = []): User
    {
        return User::create($attrs + [
            'email' => 'tg-' . Str::random(6) . '@example.com',
            'password' => 'x',
            'uuid' => Str::uuid()->toString(),
            'token' => bin2hex(random_bytes(16)),
            'balance' => 0,
            'transfer_enable' => 0,
            'expired_at' => 0,
            'plan_id' => null,
            'group_id' => null,
        ]);
    }

    private function makeAttachment(User $owner, Ticket $ticket, TicketMessage $message, array $attrs = []): TicketAttachment
    {
        $path = 'ticket-attachments/' . Str::random(8) . '.png';
        Storage::disk('local')->put($path, base64_decode(self::PNG_BASE64));
        return TicketAttachment::create($attrs + [
            'user_id' => $owner->id,
            'ticket_id' => $ticket->id,
            'ticket_message_id' => $message->id,
            'driver' => 'local',
            'path' => $path,
            'original_name' => 'shot.png',
            'mime' => 'image/png',
            'size' => 70,
            'is_image' => true,
            'width' => 1,
            'height' => 1,
            'access_key' => bin2hex(random_bytes(16)),
        ]);
    }

    private function plugin(): TelegramPlugin
    {
        $plugin = new TelegramPlugin('telegram');
        $plugin->setConfig([]);
        $plugin->boot();
        return $plugin;
    }

    private function fakeTelegramApi(): void
    {
        Http::fake([
            'api.telegram.org/bot*/getFile' => Http::response([
                'ok' => true,
                'result' => ['file_id' => 'PHOTO1', 'file_path' => 'photos/file_12.jpg'],
            ]),
            'api.telegram.org/file/bot*/*' => Http::response(base64_decode(self::PNG_BASE64), 200, ['Content-Type' => 'image/jpeg']),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
    }

    public function test_ticket_notify_queues_html_text_and_one_job_per_attachment_per_admin(): void
    {
        Queue::fake();
        $admin = $this->makeUser(['is_admin' => 1, 'telegram_id' => 1001]);
        $staff = $this->makeUser(['is_staff' => 1, 'telegram_id' => 1002]);
        $this->makeUser(['telegram_id' => 1003]); // 普通用户，不应收到
        $owner = $this->makeUser();

        $ticket = Ticket::create(['user_id' => $owner->id, 'subject' => '<b>截图</b> 看不懂', 'level' => 1]);
        $message = TicketMessage::create(['user_id' => $owner->id, 'ticket_id' => $ticket->id, 'message' => '']);
        $this->makeAttachment($owner, $ticket, $message);
        $this->makeAttachment($owner, $ticket, $message, ['original_name' => 'debug.zip', 'mime' => 'application/zip', 'is_image' => false]);

        $this->plugin()->sendTicketNotify($ticket);

        Queue::assertPushed(SendTelegramJob::class, 2);
        Queue::assertPushed(SendTelegramJob::class, function (SendTelegramJob $job) {
            $text = (fn() => $this->text)->call($job);
            $mode = (fn() => $this->parseMode)->call($job);
            return $mode === 'html'
                && str_contains($text, '&lt;b&gt;截图&lt;/b&gt;') // 用户内容被转义，不会改写排版
                && str_contains($text, '<b>工单提醒</b>')      // 模板标签保留
                && str_contains($text, '没有文字，请看附件')
                && str_contains($text, '附件</b> 2 个');
        });
        // 2 个附件 × 2 个接收者
        Queue::assertPushed(SendTelegramAttachmentJob::class, 4);
        Queue::assertNotPushed(SendTelegramJob::class, function (SendTelegramJob $job) {
            return (fn() => $this->telegramId)->call($job) === 1003;
        });
        $this->assertSame([1001, 1002], (fn() => $this->telegramService)->call($this->plugin())->getAdminChatIds(true));
        unset($admin, $staff);
    }

    public function test_attachment_job_sends_photo_for_png_and_document_for_zip(): void
    {
        $this->fakeTelegramApi();
        $owner = $this->makeUser();
        $ticket = Ticket::create(['user_id' => $owner->id, 'subject' => 's', 'level' => 0]);
        $message = TicketMessage::create(['user_id' => $owner->id, 'ticket_id' => $ticket->id, 'message' => 'hi']);
        $png = $this->makeAttachment($owner, $ticket, $message);
        $zip = $this->makeAttachment($owner, $ticket, $message, ['original_name' => 'debug.zip', 'mime' => 'application/zip', 'is_image' => false]);

        (new SendTelegramAttachmentJob(1001, $png->id, "📎 工单 #1 附件\n工单ID: 1"))->handle();
        (new SendTelegramAttachmentJob(1001, $zip->id, 'zip'))->handle();

        // multipart 请求里的普通字段在 Http::fake 下以 {name, contents} 条目形式暴露
        $hasField = fn($req, string $name, string $value) => collect($req->data())
            ->contains(fn($entry) => ($entry['name'] ?? null) === $name && (string) ($entry['contents'] ?? '') === $value);
        Http::assertSent(fn($req) => str_ends_with($req->url(), '/sendPhoto') && $req->hasFile('photo', null, 'shot.png') && $hasField($req, 'chat_id', '1001'));
        Http::assertSent(fn($req) => str_ends_with($req->url(), '/sendDocument') && $req->hasFile('document', null, 'debug.zip'));
        Http::assertNotSent(fn($req) => str_ends_with($req->url(), '/sendDocument') && $req->hasFile('document', null, 'shot.png'));
    }

    public function test_admin_reply_with_photo_via_telegram_creates_message_with_attachment(): void
    {
        $this->fakeTelegramApi();
        $admin = $this->makeUser(['is_admin' => 1, 'telegram_id' => 1001]);
        $owner = $this->makeUser();
        $ticket = Ticket::create(['user_id' => $owner->id, 'subject' => 's', 'level' => 0]);
        TicketMessage::create(['user_id' => $owner->id, 'ticket_id' => $ticket->id, 'message' => 'hi']);

        $msg = (object) [
            'chat_id' => 1001,
            'message_id' => 55,
            'message_type' => 'reply_message',
            'text' => '',
            'command' => '',
            'args' => [],
            'is_private' => true,
            'reply_text' => "📮 工单提醒 #{$ticket->id}",
            'attachments' => [['kind' => 'photo', 'file_id' => 'PHOTO1', 'file_name' => null, 'mime' => 'image/jpeg', 'size' => 70]],
        ];
        preg_match('/(📮.*?工单(?:提醒|回复).*?#?|工单ID: ?)(\d+)/u', $msg->reply_text, $matches);

        $this->plugin()->handleTicketReply($msg, $matches);

        $reply = TicketMessage::where('ticket_id', $ticket->id)->where('user_id', $admin->id)->first();
        $this->assertNotNull($reply, '管理员回复应写入工单');
        $this->assertSame('', $reply->message);
        $attachment = TicketAttachment::where('ticket_message_id', $reply->id)->first();
        $this->assertNotNull($attachment, '随回复发来的图片应存为附件并绑定');
        $this->assertSame($admin->id, $attachment->user_id);
        $this->assertTrue($attachment->is_image);
        $this->assertSame('file_12.png', $attachment->original_name); // 名字来自 Telegram file_path，扩展名按内容归一
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertSame(Ticket::STATUS_OPENING, $ticket->fresh()->reply_status);

        Http::assertSent(fn($req) => str_ends_with($req->url(), '/getFile') && $req['file_id'] === 'PHOTO1');
        Http::assertSent(fn($req) => str_ends_with($req->url(), '/sendMessage') && str_contains((string) $req['text'], '回复成功') && str_contains((string) $req['text'], '1 个附件'));
    }

    public function test_non_admin_bound_user_cannot_reply_ticket_via_telegram(): void
    {
        $this->fakeTelegramApi();
        $stranger = $this->makeUser(['telegram_id' => 2002]);
        $owner = $this->makeUser();
        $ticket = Ticket::create(['user_id' => $owner->id, 'subject' => 's', 'level' => 0]);
        TicketMessage::create(['user_id' => $owner->id, 'ticket_id' => $ticket->id, 'message' => 'hi']);

        $msg = (object) [
            'chat_id' => 2002,
            'message_id' => 56,
            'message_type' => 'reply_message',
            'text' => '我来冒充管理员',
            'command' => '我来冒充管理员',
            'args' => [],
            'is_private' => true,
            'reply_text' => "工单ID: {$ticket->id}",
            'attachments' => [],
        ];
        preg_match('/(📮.*?工单(?:提醒|回复).*?#?|工单ID: ?)(\d+)/u', $msg->reply_text, $matches);

        $this->plugin()->handleTicketReply($msg, $matches);

        $this->assertSame(1, TicketMessage::where('ticket_id', $ticket->id)->count(), '非管理员不得写入工单');
        $this->assertNull(TicketMessage::where('user_id', $stranger->id)->first());
        Http::assertSent(fn($req) => str_ends_with($req->url(), '/sendMessage') && str_contains((string) $req['text'], '只有管理员或客服'));
    }

    public function test_webhook_parses_photo_reply_without_text(): void
    {
        $this->fakeTelegramApi();
        $captured = null;
        HookManager::registerFilter('telegram.message.handle', function ($handled, array $data) use (&$captured) {
            $captured = $data[0];
            return true;
        }, 5);

        $update = [
            'update_id' => 1,
            'message' => [
                'message_id' => 77,
                'chat' => ['id' => 1001, 'type' => 'private'],
                'photo' => [
                    ['file_id' => 'SMALL', 'file_size' => 100, 'width' => 90, 'height' => 90],
                    ['file_id' => 'LARGE', 'file_size' => 9000, 'width' => 800, 'height' => 800],
                ],
                'caption' => '这是截图',
                'reply_to_message' => [
                    'message_id' => 70,
                    'caption' => "📎 工单 #9 附件 1/1：shot.png（70B）\n工单ID: 9",
                ],
            ],
        ];

        $this->postJson('/api/v1/guest/telegram/webhook?access_token=' . md5(self::BOT_TOKEN), $update)
            ->assertStatus(200);

        $this->assertNotNull($captured, 'webhook 应把 photo+caption 消息交给处理链');
        $this->assertSame('reply_message', $captured->message_type);
        $this->assertSame('这是截图', $captured->text);
        $this->assertStringContainsString('工单ID: 9', $captured->reply_text);
        $this->assertCount(1, $captured->attachments);
        $this->assertSame('LARGE', $captured->attachments[0]['file_id']);
    }
}
