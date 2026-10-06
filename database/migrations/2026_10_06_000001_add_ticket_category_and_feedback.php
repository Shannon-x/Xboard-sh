<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 工单分类 + 建议 / 反馈。
 *
 * - category：分类 code，取值见 App\Services\TicketCategory\TicketCategories::CATEGORIES。
 *   存量工单一律落到 other（老前端不传分类时也是 other），提现自动开的工单回填为 withdraw。
 * - type：0 求助 / 1 建议与反馈。由分类推导，但单独落列 —— 后台按类型筛选、统计是高频查询，
 *   而且以后分类改归属也不该把历史工单的类型一起改掉。
 * - feedback_state：建议 / 反馈的跟进状态（received/accepted/planned/shipped/declined），求助类为 NULL。
 *
 * 幂等：每一列先 hasColumn 再加，重复跑不会报错。
 */
return new class extends Migration {
    private const TABLE = 'v2_ticket';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            if (!Schema::hasColumn(self::TABLE, 'category')) {
                $table->string('category', 32)->default('other')->after('level')->comment('分类 code');
            }
            if (!Schema::hasColumn(self::TABLE, 'type')) {
                $table->tinyInteger('type')->default(0)->after('category')->comment('0:求助 1:建议与反馈');
            }
            if (!Schema::hasColumn(self::TABLE, 'feedback_state')) {
                $table->string('feedback_state', 16)->nullable()->after('type')->comment('建议/反馈跟进状态，求助类为 NULL');
            }
        });

        Schema::table(self::TABLE, function (Blueprint $table) {
            if (!$this->hasIndex('idx_ticket_type_status')) {
                $table->index(['type', 'status'], 'idx_ticket_type_status');
            }
            if (!$this->hasIndex('idx_ticket_category')) {
                $table->index('category', 'idx_ticket_category');
            }
        });

        if (Schema::hasTable('v2_commission_withdrawal')) {
            DB::table(self::TABLE)
                ->whereIn('id', DB::table('v2_commission_withdrawal')->whereNotNull('ticket_id')->select('ticket_id'))
                ->where('category', 'other')
                ->update(['category' => 'withdraw']);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }
        Schema::table(self::TABLE, function (Blueprint $table) {
            if ($this->hasIndex('idx_ticket_type_status')) {
                $table->dropIndex('idx_ticket_type_status');
            }
            if ($this->hasIndex('idx_ticket_category')) {
                $table->dropIndex('idx_ticket_category');
            }
        });
        Schema::table(self::TABLE, function (Blueprint $table) {
            foreach (['feedback_state', 'type', 'category'] as $column) {
                if (Schema::hasColumn(self::TABLE, $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function hasIndex(string $name): bool
    {
        foreach (Schema::getIndexes(self::TABLE) as $index) {
            if (($index['name'] ?? '') === $name) {
                return true;
            }
        }
        return false;
    }
};
