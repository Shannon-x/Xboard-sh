<?php

namespace App\Mcp\Tools;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Mcp\McpPresenter as P;
use App\Services\Mcp\McpTicketRef;
use App\Services\TicketCategory\TicketCategories;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_tickets')]
#[Title('我的工单')]
#[Description('查看客户最近的工单：编号（ticket_ref）、主题、分类、是否已关闭、客服是否已回复，以及每张工单最近几条消息。回答「客服回了没」时用它；回复或关闭工单要用这里的 ticket_ref。')]
#[IsReadOnly]
#[IsIdempotent]
class ListTicketsTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->description('最多几张工单，1–10，默认 5。')->min(1)->max(10),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $limit = max(1, min(10, (int) ($request->get('limit') ?? 5)));
        $tickets = Ticket::where('user_id', $user->id)->orderByDesc('updated_at')->limit($limit)->get();
        $messages = TicketMessage::whereIn('ticket_id', $tickets->pluck('id'))->orderByDesc('id')->get()->groupBy('ticket_id');

        // 编号是 HMAC 出来的 ticket_ref，不是自增 ID（自增 ID 会让人推算出站点总工单数）
        $items = $tickets->map(fn (Ticket $t) => [
            'ticket_ref' => McpTicketRef::of($t),
            'subject' => (string) $t->subject,
            'category' => TicketCategories::nameOf($t->category),
            'status' => (int) $t->status === Ticket::STATUS_CLOSED ? '已关闭' : '处理中',
            'reply_status' => (int) $t->reply_status === Ticket::REPLY_STATUS_REPLIED ? '客服已回复' : '等待客服回复',
            'updated_at' => P::time((int) $t->updated_at),
            'recent_messages' => collect($messages[$t->id] ?? [])->take(3)->reverse()->map(fn (TicketMessage $m) => [
                'from' => (int) $m->user_id === (int) $user->id ? '客户' : '客服',
                'at' => P::time($m->created_at ? (int) $m->getRawOriginal('created_at') : null),
                'text' => P::plain($m->message, 400),
            ])->values()->all(),
        ])->values()->all();

        return self::result(['tickets' => $items]);
    }
}
