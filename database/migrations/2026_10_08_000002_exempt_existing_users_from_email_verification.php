<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 邮箱软验证上线前就注册的老用户一律免验证（shannon 2026-10-08：「保证已经注册的用户不用再验证」）。
 *
 * 迁移这一刻库里还没纳入流程的账号全部记成已验证、来源 legacy：横幅、限制、到期提醒、付款后补验都不会再找上他们，
 * 面板里也不再出现「立即验证」。后台用户列表把他们标成「老用户免验证」，个别可疑账号仍可手动「重新要求验证邮箱」。
 * 已纳入流程（started_at 非空）的行不碰，重复执行无害。
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('v2_user')
            ->whereNull('email_verified_at')
            ->whereNull('email_verify_started_at')
            ->update(['email_verified_at' => time(), 'email_verify_source' => 'legacy']);
    }

    public function down(): void
    {
        DB::table('v2_user')
            ->where('email_verify_source', 'legacy')
            ->update(['email_verified_at' => null, 'email_verify_source' => null]);
    }
};
