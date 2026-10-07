<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 流量提醒按周期去重：level 记这个周期已经发到哪一档（0 = 没发，1 = 用量预警，2 = 流量用完），
 * 用量回落到阈值以下（流量重置 / 升级套餐）时清零。原来靠 24 小时的 Redis 标记，和每日扫描同周期，
 * 用量停在 80%–99% 的用户每天都会收到一封。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_user', 'traffic_notified_level')) {
                $table->unsignedTinyInteger('traffic_notified_level')->default(0)->after('remind_traffic')->comment('本周期流量提醒已发到的档位：0 无 1 预警 2 用完');
            }
            if (!Schema::hasColumn('v2_user', 'traffic_notified_at')) {
                $table->integer('traffic_notified_at')->nullable()->after('traffic_notified_level')->comment('最近一封流量提醒的时间');
            }
        });
    }

    public function down(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            foreach (['traffic_notified_level', 'traffic_notified_at'] as $column) {
                if (Schema::hasColumn('v2_user', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
