<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 邮箱验证邮件里附带 6 位验证码：邮件里的链接点不开（邮件客户端禁用链接、安全扫描先消耗了链接、
 * 浏览器没反应）的用户，登录面板后在提示栏里输入验证码也能完成验证。
 *
 * v2_user 新增两列，全部可空，老代码不读不写：
 *   email_verify_code           验证码的 SHA-256（与链接凭据同一发信周期生成、同时过期）
 *   email_verify_code_attempts  这个验证码已经输错几次；错满 5 次作废，必须重新发送
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_user', 'email_verify_code')) {
                $table->string('email_verify_code', 64)->nullable()->after('email_verify_token_expires_at');
            }
            if (!Schema::hasColumn('v2_user', 'email_verify_code_attempts')) {
                $table->unsignedTinyInteger('email_verify_code_attempts')->default(0)->after('email_verify_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            foreach (['email_verify_code', 'email_verify_code_attempts'] as $column) {
                if (Schema::hasColumn('v2_user', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
