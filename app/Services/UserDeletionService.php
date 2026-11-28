<?php

namespace App\Services;

use App\Models\TicketAttachment;
use App\Models\User;
use App\Services\Plugin\HookManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 删除用户及其关联数据。后台「用户 → 删除」与付款异常封号满期自动删号（EnforcedUserPurge）共用，删法完全一致。
 */
final class UserDeletionService
{
    /**
     * @param ?Request $request 后台删除时的请求；自动删号为 null（插件钩子 payload 里 source 区分来源）
     * @param ?callable(User): bool $guard 在删除事务里、锁住用户行之后调用，返回 false 放弃删除
     *        （自动删号用它在锁内复核还该不该删，并在删前写追溯记录）
     * @return bool 删了返回 true；用户已不存在或 $guard 放弃返回 false
     */
    public function delete(User $user, ?Request $request = null, ?callable $guard = null, string $source = 'admin'): bool
    {
        $payload = ['user' => $user, 'request' => $request, 'source' => $source];
        HookManager::call('admin.user.destroy.before', $payload);

        $attachments = DB::transaction(function () use ($user, $guard) {
            $locked = User::lockForUpdate()->find($user->id);
            if (!$locked || ($guard !== null && $guard($locked) === false)) {
                return null;
            }
            return $this->deleteRecords($locked);
        });
        if ($attachments === null) {
            return false;
        }

        // 附件文件删除不可回滚，放在事务提交之后
        if ($attachments !== []) {
            $attachmentService = new TicketAttachmentService();
            foreach ($attachments as $attachment) {
                $attachmentService->deleteFile($attachment);
            }
        }

        HookManager::call('admin.user.destroy.after', $payload);
        return true;
    }

    /**
     * 须在事务里调用。返回要在提交后删除文件的工单附件。
     *
     * @return list<TicketAttachment>
     */
    private function deleteRecords(User $user): array
    {
        $userId = (int) $user->id;

        // 主体关联：直接删除（旧行为）
        $user->orders()->delete();
        $user->codes()->delete();
        $user->stat()->delete();

        // 工单消息需手动清理：原 hasMany Tickets()->delete() 不会级联到 v2_ticket_message
        $ticketIds = $user->tickets()->pluck('id')->all();
        // 附件：先记下要删的对象，事务提交后再删存储里的文件
        $attachments = TicketAttachment::where('user_id', $userId)
            ->when(!empty($ticketIds), fn ($q) => $q->orWhereIn('ticket_id', $ticketIds))
            ->get();
        if ($attachments->isNotEmpty()) {
            TicketAttachment::whereIn('id', $attachments->pluck('id')->all())->delete();
        }
        if (!empty($ticketIds)) {
            DB::table('v2_ticket_message')->whereIn('ticket_id', $ticketIds)->delete();
        }
        $user->tickets()->delete();

        // 自身的佣金/礼品卡使用记录：删除
        DB::table('v2_commission_log')->where('user_id', $userId)->delete();
        if (Schema::hasTable('v2_gift_card_usage')) {
            DB::table('v2_gift_card_usage')->where('user_id', $userId)->delete();
        }
        if (Schema::hasTable('v2_traffic_reset_log')) {
            DB::table('v2_traffic_reset_log')->where('user_id', $userId)->delete();
        }

        // 他人对该用户的引用：保留记录但置空，避免悬挂 FK 误读
        User::where('invite_user_id', $userId)->update(['invite_user_id' => null]);
        DB::table('v2_commission_log')->where('invite_user_id', $userId)->update(['invite_user_id' => null]);
        if (Schema::hasTable('v2_gift_card_usage')) {
            DB::table('v2_gift_card_usage')->where('invite_user_id', $userId)->update(['invite_user_id' => null]);
        }
        if (Schema::hasTable('v2_gift_card_code')) {
            DB::table('v2_gift_card_code')->where('user_id', $userId)->update(['user_id' => null]);
        }

        $user->delete();
        return $attachments->all();
    }
}
