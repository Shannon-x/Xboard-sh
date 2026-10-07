<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 早一版的投递闭环把每次队列重试都算一次临时失败：同一封信重试 3 次（几秒到一分钟内）就会把用户标成暂停投递。
 * 计数改成一小时内只算一次之后，清掉旧规则留下的临时失败标记和计数，免得这些用户收不到之后的提醒和账单。
 * 退信和抑制名单（suppressed / bounce）的标记是对的，不动。可以重复执行。
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('v2_user', 'mail_suppressed_reason')) {
            return;
        }
        DB::table('v2_user')->where('mail_suppressed_reason', 'temporary')->update([
            'mail_failed_count' => 0,
            'mail_failed_at' => null,
            'mail_suppressed_at' => null,
            'mail_suppressed_reason' => null,
        ]);
        // 没被标记却带着计数的只可能来自临时失败（退信一次就标记，解除时计数清零）
        DB::table('v2_user')->whereNull('mail_suppressed_at')->where('mail_failed_count', '>', 0)->update([
            'mail_failed_count' => 0,
            'mail_failed_at' => null,
        ]);
    }

    public function down(): void
    {
        // 清掉的是按旧规则误记的计数，没有要还原的
    }
};
