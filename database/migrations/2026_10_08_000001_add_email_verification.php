<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 邮箱软验证（注册 / 付款后发一次性验证链接，面板常驻提示，宽限期过后限制部分功能）。
 *
 * v2_user 新增几列，全部可空，老代码不读不写：
 *   email_verified_at           验证通过的时间；非空 = 已验证
 *   email_verify_started_at     纳入验证流程的时间（注册 / 付款开通时），宽限期从这里起算；空 = 老用户，还没轮到
 *   email_verify_source         纳入来源 register / order / admin / change
 *   email_verify_token          一次性链接凭据的 SHA-256（库里不存明文，拿到库也拼不出链接）
 *   email_verify_token_expires_at
 *   email_verify_sent_at        最近一次发送验证邮件的时间（重发冷却）
 *   email_verify_reminded_at    到期前的提醒邮件发过的时间（只发一次）
 *   email_verify_pending_email  用户要换成的新邮箱；点开新邮箱里的链接才真正换
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_user', 'email_verified_at')) {
                $table->integer('email_verified_at')->nullable()->after('notify_key');
            }
            if (!Schema::hasColumn('v2_user', 'email_verify_started_at')) {
                $table->integer('email_verify_started_at')->nullable()->after('email_verified_at');
            }
            if (!Schema::hasColumn('v2_user', 'email_verify_source')) {
                $table->string('email_verify_source', 16)->nullable()->after('email_verify_started_at');
            }
            if (!Schema::hasColumn('v2_user', 'email_verify_token')) {
                $table->string('email_verify_token', 64)->nullable()->after('email_verify_source');
                $table->index('email_verify_token', 'idx_user_email_verify_token');
            }
            if (!Schema::hasColumn('v2_user', 'email_verify_token_expires_at')) {
                $table->integer('email_verify_token_expires_at')->nullable()->after('email_verify_token');
            }
            if (!Schema::hasColumn('v2_user', 'email_verify_sent_at')) {
                $table->integer('email_verify_sent_at')->nullable()->after('email_verify_token_expires_at');
            }
            if (!Schema::hasColumn('v2_user', 'email_verify_reminded_at')) {
                $table->integer('email_verify_reminded_at')->nullable()->after('email_verify_sent_at');
            }
            if (!Schema::hasColumn('v2_user', 'email_verify_pending_email')) {
                $table->string('email_verify_pending_email', 64)->nullable()->after('email_verify_reminded_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            if (Schema::hasColumn('v2_user', 'email_verify_token')) {
                $table->dropIndex('idx_user_email_verify_token');
            }
            foreach ([
                'email_verified_at', 'email_verify_started_at', 'email_verify_source', 'email_verify_token',
                'email_verify_token_expires_at', 'email_verify_sent_at', 'email_verify_reminded_at', 'email_verify_pending_email',
            ] as $column) {
                if (Schema::hasColumn('v2_user', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
