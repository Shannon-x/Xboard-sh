<?php
namespace App\Services;


use App\Exceptions\ApiException;
use App\Jobs\SendEmailJob;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
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
            if ($userId !== $ticket->user_id) {
                $ticket->reply_status = Ticket::STATUS_OPENING;
            } else {
                $ticket->reply_status = Ticket::STATUS_CLOSED;
            }
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
            if ($userId !== $ticket->user_id) {
                $ticket->reply_status = Ticket::STATUS_OPENING;
            } else {
                $ticket->reply_status = Ticket::STATUS_CLOSED;
            }
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
     */
    public function createTicket($userId, $subject, $level, $message, array $attachmentIds = [])
    {
        try {
            DB::beginTransaction();
            if (Ticket::where('status', 0)->where('user_id', $userId)->lockForUpdate()->first()) {
                DB::rollBack();
                throw new ApiException('存在未关闭的工单');
            }
            $ticket = Ticket::create([
                'user_id' => $userId,
                'subject' => $subject,
                'level' => $level
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
                DB::rollBack();
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
