<?php

namespace App\Mcp\Tools;

use App\Exceptions\ApiException;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Mcp\McpTicketRef;
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

#[Name('close_ticket')]
#[Title('关闭工单')]
#[Description('客户说问题已经解决、不用再跟进时，关闭这张工单。关闭后不能再回复，有新问题再开一张。')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent]
class CloseTicketTool extends McpTool
{
    protected bool $ordering = true;

    public function schema(JsonSchema $schema): array
    {
        return [
            'ticket_ref' => $schema->string()->description('工单编号，来自 list_tickets。')->required(),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $ticket = McpTicketRef::find($user, (string) $request->get('ticket_ref'));
        $this->auditRef = McpTicketRef::of($ticket);
        if ((int) $ticket->status !== Ticket::STATUS_CLOSED) {
            $ticket->status = Ticket::STATUS_CLOSED;
            if (!$ticket->save()) {
                throw new ApiException(__('Close failed'));
            }
        }
        return self::result(['ticket_ref' => $this->auditRef, 'subject' => (string) $ticket->subject, 'status' => '已关闭']);
    }
}
