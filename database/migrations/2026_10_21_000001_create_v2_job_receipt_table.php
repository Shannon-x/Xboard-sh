<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 队列任务落库回执：流量上报的三类任务（TrafficFetchJob / StatUserJob / StatServerJob）把数据写库
 * 和一行回执放进同一个事务。任务重试时先看回执：上次已经提交过的就跳过，回滚过的照常再做。
 * 这样重试永远不会把同一批流量记两遍，也就可以放心给 TrafficFetchJob 开重试（原先 tries=1，
 * 任何一次死锁 / 锁等待超时都直接丢掉这一块用户的流量）。见 App\Support\JobReceipt。
 * 行只需保留几天（reset:log 每天清理），表很小。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('v2_job_receipt')) {
            return;
        }
        Schema::create('v2_job_receipt', function (Blueprint $table) {
            $table->char('job_uuid', 36)->primary();
            $table->string('job', 64)->comment('任务类短名，便于排查');
            $table->integer('created_at');
            $table->index('created_at', 'idx_job_receipt_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_job_receipt');
    }
};
