<?php

use App\Services\Billing\BillingDocumentService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 提现工单的主题、首条消息和结算回复原来带自增 id（「[提现申请] #37 ¥100.00 → …」「提现申请 #37」「✅ 提现 #37 已完成」）。
 * 用户在工单列表和「工单有新回复」邮件里都看得到，拿到 #37 就知道站点一共打过 37 笔佣金。
 * 新申请已经改成不带 id 的主题和 WD- 申请编号，这里把历史工单也改成同样的文案。
 * 只动系统写入的那几行固定文案，用户自己写的消息不碰；可以重复执行。
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('v2_commission_withdrawal') || !Schema::hasTable('v2_ticket') || !Schema::hasTable('v2_ticket_message')) {
            return;
        }
        DB::table('v2_commission_withdrawal')
            ->whereNotNull('ticket_id')
            ->select(['id', 'ticket_id', 'created_at'])
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $id = (int) $row->id;
                    $reference = BillingDocumentService::documentNumber('WD', (int) $row->created_at, 'withdrawal:' . $id);
                    $pattern = '/^\[提现申请\] #' . $id . '\s+/u';

                    $ticket = DB::table('v2_ticket')->where('id', $row->ticket_id)->first(['id', 'subject']);
                    if ($ticket && preg_match($pattern, (string) $ticket->subject)) {
                        DB::table('v2_ticket')->where('id', $ticket->id)->update([
                            'subject' => preg_replace($pattern, '[提现申请] ', (string) $ticket->subject),
                        ]);
                    }

                    $messages = DB::table('v2_ticket_message')
                        ->where('ticket_id', $row->ticket_id)
                        ->where('message', 'like', "%#{$id}%")
                        ->get(['id', 'message']);
                    foreach ($messages as $message) {
                        $old = (string) $message->message;
                        // 换行作为边界：#37 不会误伤 #371
                        $new = str_replace(
                            ["提现申请 #{$id}\n", "✅ 提现 #{$id} 已完成"],
                            ["提现申请 {$reference}\n", '✅ 提现已完成'],
                            $old
                        );
                        if ($new !== $old) {
                            DB::table('v2_ticket_message')->where('id', $message->id)->update(['message' => $new]);
                        }
                    }
                }
            });
    }

    public function down(): void
    {
        // 去掉的是不该给用户看的流水号，没有要还原的
    }
};
