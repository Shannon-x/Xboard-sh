<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 用户通知偏好（按类别退订）。
 *
 * v2_user_notification_pref  每位用户每个类别最多一行：enabled 是当前取值，source 记这次改动从哪来
 *                            （面板 / 邮件里的链接 / 邮件客户端的一键退订 / 后台 / 老接口），后台据此看退订来源。
 *                            没有行 = 默认开启。账单与流量两类另外镜像到老列 remind_expire / remind_traffic，
 *                            扫描任务与老前端不用改。
 * v2_user                    +notify_key：邮件页脚「管理通知偏好」链接与 List-Unsubscribe 头里的凭据，
 *                            128 位随机、首次发信时生成；不带用户 id，看不出站点的用户量。
 *
 * 新表 + 可空列，老代码不读不写。
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('v2_user_notification_pref')) {
            Schema::create('v2_user_notification_pref', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('user_id');
                $table->string('category', 32);
                $table->boolean('enabled')->default(true);
                $table->string('source', 24)->default('panel');
                $table->string('ip', 45)->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
                $table->unique(['user_id', 'category'], 'uk_notify_pref_user_category');
                $table->index(['category', 'enabled'], 'idx_notify_pref_category_enabled');
            });
        }

        if (!Schema::hasColumn('v2_user', 'notify_key')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->string('notify_key', 32)->nullable()->unique('uk_user_notify_key')->after('mail_suppressed_reason');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_user_notification_pref');
        if (Schema::hasColumn('v2_user', 'notify_key')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->dropUnique('uk_user_notify_key');
                $table->dropColumn('notify_key');
            });
        }
    }
};
