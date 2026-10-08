<?php

namespace Tests\Feature;

use App\Models\TicketMessage;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 工单消息的 is_from_user / is_from_admin 在 $appends 里，任何 toArray() 都会算它们。
 *
 * 生产库里有十几条消息的工单行已经不在了（早年删用户只删工单、没清消息），
 * 对这些消息 toArray() 曾经抛「Attempt to read property "user_id" on null」。
 */
class TicketMessageOrphanTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $prefix = 'u'): User
    {
        return User::create([
            'email' => $prefix . '-' . Str::random(6) . '@example.com',
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

    public function test_messages_still_know_who_wrote_them(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('admin');
        $service = new TicketService();
        $ticket = $service->createTicket($user->id, '节点套餐升级问题', 0, '增购的三网线路是哪几只？');
        $service->replyByAdmin($ticket->id, '是那 10 只 x10 的线路。', $admin->id);

        $rows = TicketMessage::where('ticket_id', $ticket->id)->orderBy('id')->get()->toArray();

        $this->assertCount(2, $rows);
        $this->assertTrue($rows[0]['is_from_user']);
        $this->assertFalse($rows[0]['is_from_admin']);
        $this->assertFalse($rows[1]['is_from_user']);
        $this->assertTrue($rows[1]['is_from_admin']);
    }

    public function test_message_whose_ticket_is_gone_serializes_without_error(): void
    {
        $user = $this->makeUser();
        $ticket = (new TicketService())->createTicket($user->id, '旧工单', 0, '这条消息的工单会被删掉');
        // 模拟早年的删用户：只删工单行，消息留在库里
        DB::table('v2_ticket')->where('id', $ticket->id)->delete();

        $rows = TicketMessage::where('ticket_id', $ticket->id)->get()->toArray();

        $this->assertCount(1, $rows);
        $this->assertFalse($rows[0]['is_from_user']);
        $this->assertFalse($rows[0]['is_from_admin']);
    }
}
