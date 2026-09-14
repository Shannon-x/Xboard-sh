<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * v2_ticket.reply_status 的存储语义：0 待回复 / 1 已回复，与列注释一致。
 *
 * 这组断言存在的理由：曾经这里写的是 status 字段的常量（OPENING=0 / CLOSED=1），
 * 语义正好反过来 —— 后台前端也跟着反，两处一抵消看不出问题，但用户前端按注释
 * 语义渲染，新建工单一落库就显示「官方已回复」。谁再想「顺手统一一下常量」，
 * 先让这几条过。
 */
class TicketReplyStatusTest extends TestCase
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

    public function test_new_ticket_waits_for_staff(): void
    {
        $user = $this->makeUser();

        $ticket = (new TicketService())->createTicket($user->id, '节点套餐升级问题', 0, '增购的三网线路是哪几只？');

        $this->assertSame(Ticket::REPLY_STATUS_PENDING, $ticket->fresh()->reply_status);
    }

    public function test_staff_reply_marks_replied_and_user_followup_marks_pending(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeUser('admin');
        $service = new TicketService();
        $ticket = $service->createTicket($user->id, '节点套餐升级问题', 0, '增购的三网线路是哪几只？');

        $service->replyByAdmin($ticket->id, '是那 10 只 x10 的线路。', $admin->id);
        $this->assertSame(Ticket::REPLY_STATUS_REPLIED, $ticket->fresh()->reply_status);

        $service->reply($ticket->fresh(), '好的，那我续费了', $user->id);
        $this->assertSame(Ticket::REPLY_STATUS_PENDING, $ticket->fresh()->reply_status);
    }

    public function test_column_default_no_longer_claims_replied(): void
    {
        // createTicket 之外还有别的地方 Ticket::create（提现工单），漏写字段时
        // 吃到的默认值不能再是「已回复」。
        $user = $this->makeUser();
        $id = DB::table('v2_ticket')->insertGetId([
            'user_id' => $user->id,
            'subject' => '不写 reply_status 的建单',
            'level' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $this->assertSame(Ticket::REPLY_STATUS_PENDING, (int) Ticket::find($id)->reply_status);
    }
}
