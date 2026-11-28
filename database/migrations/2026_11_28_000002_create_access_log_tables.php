<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 用户访问记录：明细在 S3（节点每批一个对象，按 user_id % 32 分 32 段 gzip），库里只放索引和汇总。
 *
 * - v2_access_log_file：每个批次对象一行，记下 32 段的起点，查某个用户时只按范围读他那一段。
 * - v2_access_log_user_day：用户 × 天 × 节点的连接数、上下行和出现过的小时（位图），既是日汇总，
 *   也告诉明细查询该读哪几个小时的对象。由 access-log:flush 每 5 分钟从 Redis 小计并入。
 * - v2_access_log_query：后台每次明细查询 / 导出一行，同时是查询审计记录。
 *
 * 三张表都按 dt / created_at 每日清理（access-log:prune），保留天数在后台设置。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_access_log_file')) {
            Schema::create('v2_access_log_file', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('node_type', 32);
                $table->unsignedInteger('node_id');
                $table->char('ledger', 32);
                $table->unsignedBigInteger('seq');
                $table->date('dt')->comment('面板时区的日期');
                $table->unsignedTinyInteger('hour')->comment('面板时区的小时');
                $table->unsignedInteger('hour_at')->comment('该小时开始的 Unix 时间');
                $table->string('object_key', 255);
                $table->unsignedInteger('bytes');
                $table->unsignedInteger('records');
                $table->unsignedInteger('dropped')->default(0);
                $table->unsignedInteger('users');
                $table->unsignedInteger('from_at');
                $table->unsignedInteger('to_at');
                $table->text('offsets')->comment('32 段的起始字节，JSON 数组');
                $table->unsignedInteger('created_at');
                $table->unique(['node_type', 'node_id', 'ledger', 'seq'], 'uniq_access_log_file_batch');
                $table->index(['dt', 'node_type', 'node_id', 'hour'], 'idx_access_log_file_dt_node');
            });
        }
        if (!Schema::hasTable('v2_access_log_user_day')) {
            Schema::create('v2_access_log_user_day', function (Blueprint $table) {
                $table->unsignedInteger('user_id');
                $table->date('dt');
                $table->string('node_type', 32);
                $table->unsignedInteger('node_id');
                $table->unsignedBigInteger('records')->default(0);
                $table->unsignedBigInteger('up')->default(0);
                $table->unsignedBigInteger('down')->default(0);
                $table->unsignedInteger('hours')->default(0)->comment('出现过的小时，第 n 位表示 n 点');
                $table->unsignedInteger('updated_at');
                $table->primary(['user_id', 'dt', 'node_type', 'node_id']);
                $table->index('dt', 'idx_access_log_user_day_dt');
            });
        }
        if (!Schema::hasTable('v2_access_log_query')) {
            Schema::create('v2_access_log_query', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('admin_id');
                $table->unsignedInteger('user_id');
                $table->date('date_from');
                $table->date('date_to');
                $table->text('filter')->nullable();
                $table->unsignedTinyInteger('status')->default(0)->comment('0 排队 1 进行中 2 完成 3 失败');
                $table->unsignedInteger('files')->default(0);
                $table->unsignedInteger('missing')->default(0)->comment('读不到的批次（已过期或存储出错）');
                $table->unsignedBigInteger('bytes')->default(0);
                $table->unsignedInteger('rows')->default(0);
                $table->boolean('truncated')->default(false);
                $table->mediumText('summary')->nullable();
                $table->string('export_key', 255)->nullable();
                $table->string('error', 255)->nullable();
                $table->unsignedInteger('created_at');
                $table->unsignedInteger('updated_at');
                $table->index(['user_id', 'created_at'], 'idx_access_log_query_user');
                $table->index('created_at', 'idx_access_log_query_created');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_access_log_query');
        Schema::dropIfExists('v2_access_log_user_day');
        Schema::dropIfExists('v2_access_log_file');
    }
};
