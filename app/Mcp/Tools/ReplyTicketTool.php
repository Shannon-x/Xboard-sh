<?php

namespace App\Mcp\Tools;

use App\Exceptions\ApiException;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Mcp\McpTicketRef;
use App\Services\Plugin\HookManager;
use App\Services\TicketService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('reply_ticket')]
#[Title('回复工单')]
#[Description('替客户在一张未关闭的工单里补充或回复客服。ticket_ref 来自 list_tickets。先把要发的内容给客户确认。')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
class ReplyTicketTool extends McpTool
{
    protected bool $ordering = true;

    public function schema(JsonSchema $schema): array
    {
        return [
            'ticket_ref' => $schema->string()->description('工单编号，形如 T1A2B3C4D，来自 list_tickets。')->required(),
            'message' => $schema->string()->description('要发给客服的内容。')->required(),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $message = trim((string) ($request->get('message') ?? ''));
        if ($message === '' || mb_strlen($message) > 9900) {
            throw new ApiException('回复内容不能为空，也不能超过 9900 字。');
        }
        self::throttle("mcp_ticket_reply:user:{$user->id}", 10, 60, '回复太频繁，请稍后再试。');

        $ticket = McpTicketRef::find($user, (string) $request->get('ticket_ref'));
        $this->auditRef = McpTicketRef::of($ticket);
        if ((int) $ticket->status === Ticket::STATUS_CLOSED) {
            throw new ApiException(__('The ticket is closed and cannot be replied') . ' 需要的话可以用 create_ticket 新开一张。');
        }
        $last = TicketMessage::where('ticket_id', $ticket->id)->orderByDesc('id')->first();
        if ((int) admin_setting('ticket_must_wait_reply', 0) && $last && (int) $last->user_id === (int) $user->id) {
            throw new ApiException(__('Please wait for the technical enginneer to reply'));
        }
        if (!(new TicketService())->reply($ticket, $message . CreateTicketTool::AI_NOTE, $user->id)) {
            throw new ApiException(__('Ticket reply failed'));
        }
        HookManager::call('ticket.reply.user.after', $ticket);

        return self::result([
            'ticket_ref' => $this->auditRef,
            'subject' => (string) $ticket->subject,
            'status' => '已发送，等待客服回复',
        ]);
    }
}
