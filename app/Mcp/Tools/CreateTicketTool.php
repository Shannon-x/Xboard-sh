<?php

namespace App\Mcp\Tools;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\EmailVerification;
use App\Services\Mcp\McpTicketRef;
use App\Services\Plugin\HookManager;
use App\Services\TicketCategory\TicketCategories;
use App\Services\TicketService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('create_ticket')]
#[Title('提交工单')]
#[Description('替客户给客服提交一张工单。先把主题和内容念给客户确认再提交，内容写清时间、节点名、现象。同一时间只能有一张未关闭的求助工单（建议 / 反馈另算，最多 3 张）；已经有未关闭的工单时改用 reply_ticket 补充。退款、投诉、连不上都走这里。')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
class CreateTicketTool extends McpTool
{
    protected bool $ordering = true;

    /** 附在正文后，客服一眼看出这条是 AI 代写的 */
    public const AI_NOTE = "\n\n（由客户的 AI 助手代为提交）";

    public function schema(JsonSchema $schema): array
    {
        $names = [];
        foreach (TicketCategories::selectableCodes() as $code) {
            $names[] = $code . ' ' . TicketCategories::nameOf($code);
        }
        return [
            'subject' => $schema->string()->description('主题，一句话，200 字以内。')->required(),
            'message' => $schema->string()->description('正文：发生了什么、什么时间、哪个节点、试过什么。')->required(),
            'category' => $schema->string()->enum(TicketCategories::selectableCodes())
                ->description('分类：' . implode('、', $names) . '。不确定就用 other。'),
            'level' => $schema->integer()->description('紧急程度：0 低、1 中、2 高，默认 1。')->min(0)->max(2),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = Validator::make($request->all(), [
            'subject' => 'required|string|max:200',
            'message' => 'required|string|max:9900',
            'category' => 'nullable|string|max:32',
            'level' => 'nullable|in:0,1,2',
        ], [
            'subject.*' => '主题必填，200 字以内。',
            'message.*' => '正文必填，不超过 9900 字。',
            'level.in' => 'level 只能是 0、1、2。',
        ])->validate();
        $category = ($data['category'] ?? '') !== '' ? $data['category'] : TicketCategories::DEFAULT_CATEGORY;

        // 与面板新建工单同一套门槛：邮箱软验证受限时只放行支付类；后台要求「有订阅才能开单」时同样生效
        $fresh = User::findOrFail($user->id);
        EmailVerification::assertTicketAllowed($fresh, $category);
        $service = new TicketService();
        if ((int) admin_setting('ticket_active_subscription_required', 0) && !$service->canOpenTicket($fresh)) {
            throw new ApiException(__('Please purchase a subscription, earn affiliate commission, or place an order before opening a ticket'));
        }

        $ticket = $service->createTicket($user->id, $data['subject'], (int) ($data['level'] ?? 1), $data['message'] . self::AI_NOTE, [], $category);
        HookManager::call('ticket.create.after', $ticket);
        $this->auditRef = McpTicketRef::of($ticket);

        return self::result([
            'ticket_ref' => $this->auditRef,
            'subject' => (string) $ticket->subject,
            'category' => TicketCategories::nameOf($ticket->category),
            'status' => '已提交，等待客服回复',
            'note' => '客服回复后客户会收到邮件；也可以稍后用 list_tickets 查看。',
        ]);
    }
}
