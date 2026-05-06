<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 补齐若干高频查询路径上缺失的索引。
 *
 * 这些索引在外部审计中已被明确指出（v2board 等成熟分支也大多已加）：
 *   - v2_user.plan_id           ：用户列表筛选 + 套餐删除前置校验
 *   - v2_user.invite_user_id    ：邀请人查询、佣金统计
 *   - v2_order.user_id          ：用户订单列表（管理员侧高频）
 *   - v2_order.plan_id          ：套餐销售统计、删除前置校验
 *   - v2_ticket_message.ticket_id：工单详情按 ticket 拉消息
 *   - v2_mail_log.email          ：用户邮件历史查询
 *
 * 用 hasColumn / 索引存在性判断是为了对老库 / 二次 fork 库幂等。
 */
return new class extends Migration {
    public function up(): void
    {
        $this->ensureIndex('v2_user', 'plan_id', 'idx_v2_user_plan_id');
        $this->ensureIndex('v2_user', 'invite_user_id', 'idx_v2_user_invite_user_id');
        $this->ensureIndex('v2_order', 'user_id', 'idx_v2_order_user_id');
        $this->ensureIndex('v2_order', 'plan_id', 'idx_v2_order_plan_id');
        $this->ensureIndex('v2_ticket_message', 'ticket_id', 'idx_v2_ticket_message_ticket_id');
        $this->ensureIndex('v2_mail_log', 'email', 'idx_v2_mail_log_email');
    }

    public function down(): void
    {
        $this->dropIndexIfExists('v2_user', 'idx_v2_user_plan_id');
        $this->dropIndexIfExists('v2_user', 'idx_v2_user_invite_user_id');
        $this->dropIndexIfExists('v2_order', 'idx_v2_order_user_id');
        $this->dropIndexIfExists('v2_order', 'idx_v2_order_plan_id');
        $this->dropIndexIfExists('v2_ticket_message', 'idx_v2_ticket_message_ticket_id');
        $this->dropIndexIfExists('v2_mail_log', 'idx_v2_mail_log_email');
    }

    private function ensureIndex(string $table, string $column, string $indexName): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return;
        }
        if ($this->indexExists($table, $indexName)) {
            return;
        }
        Schema::table($table, function (Blueprint $t) use ($column, $indexName) {
            $t->index($column, $indexName);
        });
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }
        if (!$this->indexExists($table, $indexName)) {
            return;
        }
        Schema::table($table, function (Blueprint $t) use ($indexName) {
            $t->dropIndex($indexName);
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $database = DB::connection()->getDatabaseName();
        $count = DB::selectOne(
            'SELECT COUNT(1) AS c FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$database, $table, $indexName]
        );
        return ((int) ($count->c ?? 0)) > 0;
    }
};
