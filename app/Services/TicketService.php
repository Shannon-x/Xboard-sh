<?php
namespace App\Services;


use App\Exceptions\ApiException;
use App\Jobs\SendEmailJob;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\TicketCategory\TicketCategories;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Services\Plugin\HookManager;

class TicketService
{
    /**
     * @param int[] $attachmentIds 已上传、待绑定的附件 id（调用方需先经 TicketAttachmentService::validatePendingIds 校验）
     */
    public function reply($ticket, $message, $userId, array $attachmentIds = [])
    {
        try {
            DB::beginTransaction();
            $ticketMessage = TicketMessage::create([
                'user_id' => $userId,
                'ticket_id' => $ticket->id,
                'message' => (string) $message
            ]);
            if ($ticketMessage && $attachmentIds) {
                (new TicketAttachmentService())->attachToMessage($attachmentIds, $ticketMessage, $userId);
            }
            $ticket->reply_status = $userId !== $ticket->user_id
                ? Ticket::REPLY_STATUS_REPLIED
                : Ticket::REPLY_STATUS_PENDING;
            if (!$ticketMessage || !$ticket->save()) {
                throw new \Exception();
            }
            DB::commit();
            return $ticketMessage;
        } catch (\Exception $e) {
            DB::rollback();
            return false;
        }
    }

    /**
     * @param int[] $attachmentIds 管理员先经 admin ticket/attachment/upload 上传的待绑定附件 id
     */
    public function replyByAdmin($ticketId, $message, $userId, array $attachmentIds = []): void
    {
        $ticket = Ticket::where('id', $ticketId)
            ->first();
        if (!$ticket) {
            throw new ApiException('工单不存在');
        }
        $ticket->status = Ticket::STATUS_OPENING;
        try {
            DB::beginTransaction();
            $ticketMessage = TicketMessage::create([
                'user_id' => $userId,
                'ticket_id' => $ticket->id,
                'message' => (string) $message
            ]);
            if ($ticketMessage && $attachmentIds) {
                (new TicketAttachmentService())->attachToMessage($attachmentIds, $ticketMessage, $userId);
            }
            $ticket->reply_status = $userId !== $ticket->user_id
                ? Ticket::REPLY_STATUS_REPLIED
                : Ticket::REPLY_STATUS_PENDING;
            if (!$ticketMessage || !$ticket->save()) {
                throw new ApiException('工单回复失败');
            }
            DB::commit();
            HookManager::call('ticket.reply.admin.after', [$ticket, $ticketMessage]);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
        $this->sendEmailNotify($ticket, $ticketMessage);
    }

    /**
     * @param int[] $attachmentIds 已上传、待绑定的附件 id，随首条消息一起绑定
     * @param string|null $category 分类 code；null（老前端）落到 other。必须在 selectableCodes() 里
     */
    public function createTicket($userId, $subject, $level, $message, array $attachmentIds = [], ?string $category = null)
    {
        $category = $category === null || $category === '' ? TicketCategories::DEFAULT_CATEGORY : $category;
        if (!in_array($category, TicketCategories::selectableCodes(), true)) {
            throw new ApiException(__('Invalid ticket category'));
        }
        $type = TicketCategories::typeOf($category);
        $isFeedback = $type === TicketCategories::TYPE_FEEDBACK;

        try {
            // 回滚统一交给下面的 catch：这里以前在 throw 前先 rollBack 一次、catch 里再 rollBack 一次，
            // 外层若已有事务（测试的 RefreshDatabase、或调用方自己开的事务）会被多退一层整个回滚掉
            DB::beginTransaction();
            // 求助类仍是「同时只能开一张」；建议 / 反馈单独计数 —— 否则用户手上有一张没关的
            // 求助单时就没法顺手提个建议，而建议本来就不需要客服「解决」
            $openQuery = Ticket::where('status', Ticket::STATUS_OPENING)
                ->where('user_id', $userId)
                ->where('type', $type)
                ->lockForUpdate();
            if ($isFeedback) {
                if ($openQuery->count() >= TicketCategories::MAX_OPEN_FEEDBACK) {
                    throw new ApiException(__('Too many open feedback tickets'));
                }
            } elseif ($openQuery->first()) {
                throw new ApiException('存在未关闭的工单');
            }
            $ticket = Ticket::create([
                'user_id' => $userId,
                'subject' => $subject,
                // 建议 / 反馈不分紧急程度，统一记最低，免得用户把建议标成「高」挤占客服队列
                'level' => $isFeedback ? 0 : $level,
                'category' => $category,
                'type' => $type,
                'feedback_state' => $isFeedback ? TicketCategories::FEEDBACK_RECEIVED : null,
                // 不写就吃列默认值，新工单会被当成「客服已回复」
                'reply_status' => Ticket::REPLY_STATUS_PENDING
            ]);
            if (!$ticket) {
                throw new ApiException('工单创建失败');
            }
            $ticketMessage = TicketMessage::create([
                'user_id' => $userId,
                'ticket_id' => $ticket->id,
                'message' => $message
            ]);
            if (!$ticketMessage) {
                throw new ApiException('工单消息创建失败');
            }
            if ($attachmentIds) {
                (new TicketAttachmentService())->attachToMessage($attachmentIds, $ticketMessage, $userId);
            }
            DB::commit();
            return $ticket;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 后台改分类 / 优先级 / 反馈跟进状态。只改传了的字段。
     *
     * 改分类会连带改 type：把一张误分进「其他问题」的建议挪到「功能建议」，它就该开始有跟进状态；
     * 反过来挪回求助类，跟进状态清空。
     *
     * @param array{category?:string|null, level?:int|null, feedback_state?:string|null} $changes
     */
    public function updateByAdmin(Ticket $ticket, array $changes): Ticket
    {
        if (array_key_exists('category', $changes) && $changes['category'] !== null) {
            $category = (string) $changes['category'];
            if (!TicketCategories::exists($category)) {
                throw new ApiException('分类不存在');
            }
            $ticket->category = $category;
            $ticket->type = TicketCategories::typeOf($category);
        }
        if (array_key_exists('level', $changes) && $changes['level'] !== null) {
            $ticket->level = (int) $changes['level'];
        }

        $isFeedback = (int) $ticket->type === TicketCategories::TYPE_FEEDBACK;
        if (array_key_exists('feedback_state', $changes) && $changes['feedback_state'] !== null) {
            if (!$isFeedback) {
                throw new ApiException('只有建议与反馈类工单才有跟进状态');
            }
            if (!TicketCategories::isFeedbackState($changes['feedback_state'])) {
                throw new ApiException('跟进状态不存在');
            }
            $ticket->feedback_state = $changes['feedback_state'];
        }
        if ($isFeedback && !TicketCategories::isFeedbackState($ticket->feedback_state)) {
            $ticket->feedback_state = TicketCategories::FEEDBACK_RECEIVED;
        }
        if (!$isFeedback) {
            $ticket->feedback_state = null;
        }

        $ticket->save();
        HookManager::call('ticket.update.admin.after', $ticket);
        return $ticket;
    }

    // 半小时内不再重复通知
    private function sendEmailNotify(Ticket $ticket, TicketMessage $ticketMessage)
    {
        $user = User::find($ticket->user_id);
        $cacheKey = 'ticket_sendEmailNotify_' . $ticket->user_id;
        if (!Cache::get($cacheKey)) {
            Cache::put($cacheKey, 1, 1800);
            $content = (string) $ticketMessage->message;
            // 只发附件不写正文时，邮件里至少说明有附件，而不是一句空的「回复内容：」
            $attachmentCount = $ticketMessage->attachments()->count();
            if ($attachmentCount > 0) {
                $content = trim($content . "\r\n[附件 x {$attachmentCount}]");
            }
            SendEmailJob::dispatch([
                'email' => $user->email,
                'user_id' => $user->id,
                'category' => \App\Services\Notification\NotificationPreference::SUPPORT,
                'subject' => '您在' . admin_setting('app_name', 'XBoard') . '的工单得到了回复',
                'template_name' => 'notify',
                'template_value' => [
                    'name' => admin_setting('app_name', 'XBoard'),
                    'url' => admin_setting('app_url'),
                    'content' => "主题：{$ticket->subject}\r\n回复内容：{$content}"
                ]
            ]);
        }
    }
}
