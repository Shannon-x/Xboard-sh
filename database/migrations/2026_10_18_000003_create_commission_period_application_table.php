<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 周期返佣申请：大推广者的有效付费被邀请人达到门槛后由用户申请，站长在后台审核通过才开通；
 * 有效付费人数掉到门槛以下时自动取消资格（status = 3），之后达标需要重新申请。
 * 每次申请一行，保留历史；用户当前状态取最新一行。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('v2_commission_period_application')) {
            return;
        }
        Schema::create('v2_commission_period_application', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('user_id');
            $table->tinyInteger('status')->default(0)->comment('0 待审核 1 已开通 2 已驳回 3 已取消资格（人数低于门槛）');
            $table->integer('active_count')->default(0)->comment('申请时的有效付费被邀请人数');
            $table->integer('min_invitees')->default(0)->comment('申请时的门槛');
            $table->integer('admin_id')->nullable();
            $table->string('reason', 255)->nullable()->comment('驳回原因（给用户看）');
            $table->integer('decided_at')->nullable();
            $table->integer('revoked_at')->nullable();
            $table->integer('revoked_count')->nullable()->comment('取消资格时的有效付费被邀请人数');
            $table->integer('created_at');
            $table->integer('updated_at');
            $table->index(['user_id', 'id'], 'idx_period_application_user');
            $table->index(['status', 'id'], 'idx_period_application_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_commission_period_application');
    }
};
