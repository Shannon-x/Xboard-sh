<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 财务面板补全：收据 / 账单归档、余额流水、邮件投递闭环、到期后的生命周期邮件。
 *
 * v2_billing_document  开出的收据 / 续费账单：payload 存开具那一刻的内容快照（渲染用数据，约 2 KB），
 *                      下载 / 重发时按快照现生成 PDF，不落文件。access_key 是下载链接里的随机凭据（同工单附件）。
 * v2_balance_log       余额流水：user.balance 每变动一次记一行（余额支付、取消退回、折抵退回、礼品卡、佣金划转、后台调整）。
 * v2_mail_log          +user_id / status / category：投递结果分类（suppressed / bounce / temporary / config），
 *                      后台能按用户、按结果查，日报按分类汇总。
 * v2_user              +mail_failed_count / mail_failed_at / mail_suppressed_at / mail_suppressed_reason：
 *                      退信后标记为「暂停投递」，之后系统主动发的邮件改走 Telegram 或只留在面板，成功一次即自动恢复。
 *                      +lifecycle_stage / lifecycle_expiry：到期后邮件序列（已到期 → 挽回 1 → 挽回 2）走到了第几步、属于哪个到期日。
 *
 * 全部是新表、可空列或带默认值的列；老代码不读不写。
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('v2_billing_document')) {
            Schema::create('v2_billing_document', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('user_id');
                $table->integer('order_id')->nullable()->unique();
                $table->string('kind', 16);
                $table->string('doc_no', 40);
                $table->string('stage', 16)->nullable();
                $table->integer('expired_at')->nullable();
                $table->integer('amount')->default(0);
                $table->string('access_key', 32);
                $table->mediumText('payload')->nullable();
                $table->integer('size')->default(0);
                $table->string('locale', 8)->default('zh-CN');
                $table->integer('sent_at')->nullable();
                $table->integer('send_count')->default(0);
                $table->string('channel', 16)->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
                $table->index(['user_id', 'kind', 'expired_at'], 'idx_billing_doc_user_kind_expiry');
            });
        }

        if (!Schema::hasTable('v2_balance_log')) {
            Schema::create('v2_balance_log', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('user_id');
                $table->string('type', 32);
                $table->integer('amount');
                $table->integer('balance_before');
                $table->integer('balance_after');
                $table->integer('order_id')->nullable();
                $table->string('ref_type', 32)->nullable();
                $table->string('ref_id', 64)->nullable();
                $table->string('remark', 255)->nullable();
                $table->integer('operator_id')->nullable();
                $table->integer('created_at');
                $table->index(['user_id', 'created_at'], 'idx_balance_log_user_created');
                $table->index('order_id', 'idx_balance_log_order');
            });
        }

        Schema::table('v2_mail_log', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_mail_log', 'user_id')) {
                $table->integer('user_id')->nullable()->after('email');
            }
            if (!Schema::hasColumn('v2_mail_log', 'status')) {
                $table->tinyInteger('status')->default(1)->after('error');
            }
            if (!Schema::hasColumn('v2_mail_log', 'category')) {
                $table->string('category', 16)->nullable()->after('status');
            }
        });
        if (!$this->hasIndex('v2_mail_log', 'idx_v2_mail_log_user')) {
            Schema::table('v2_mail_log', fn (Blueprint $table) => $table->index('user_id', 'idx_v2_mail_log_user'));
        }
        if (!$this->hasIndex('v2_mail_log', 'idx_v2_mail_log_status_created')) {
            Schema::table('v2_mail_log', fn (Blueprint $table) => $table->index(['status', 'created_at'], 'idx_v2_mail_log_status_created'));
        }

        Schema::table('v2_user', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_user', 'mail_failed_count')) {
                $table->integer('mail_failed_count')->default(0)->after('remind_traffic');
            }
            if (!Schema::hasColumn('v2_user', 'mail_failed_at')) {
                $table->integer('mail_failed_at')->nullable()->after('mail_failed_count');
            }
            if (!Schema::hasColumn('v2_user', 'mail_suppressed_at')) {
                $table->integer('mail_suppressed_at')->nullable()->after('mail_failed_at');
            }
            if (!Schema::hasColumn('v2_user', 'mail_suppressed_reason')) {
                $table->string('mail_suppressed_reason', 32)->nullable()->after('mail_suppressed_at');
            }
            if (!Schema::hasColumn('v2_user', 'lifecycle_stage')) {
                $table->tinyInteger('lifecycle_stage')->default(0)->after('invoice_final_notified_at');
            }
            if (!Schema::hasColumn('v2_user', 'lifecycle_expiry')) {
                $table->integer('lifecycle_expiry')->nullable()->after('lifecycle_stage');
            }
        });
    }

    public function down(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            foreach (['lifecycle_expiry', 'lifecycle_stage', 'mail_suppressed_reason', 'mail_suppressed_at', 'mail_failed_at', 'mail_failed_count'] as $column) {
                if (Schema::hasColumn('v2_user', $column)) $table->dropColumn($column);
            }
        });
        if ($this->hasIndex('v2_mail_log', 'idx_v2_mail_log_status_created')) {
            Schema::table('v2_mail_log', fn (Blueprint $table) => $table->dropIndex('idx_v2_mail_log_status_created'));
        }
        if ($this->hasIndex('v2_mail_log', 'idx_v2_mail_log_user')) {
            Schema::table('v2_mail_log', fn (Blueprint $table) => $table->dropIndex('idx_v2_mail_log_user'));
        }
        Schema::table('v2_mail_log', function (Blueprint $table) {
            foreach (['category', 'status', 'user_id'] as $column) {
                if (Schema::hasColumn('v2_mail_log', $column)) $table->dropColumn($column);
            }
        });
        Schema::dropIfExists('v2_balance_log');
        Schema::dropIfExists('v2_billing_document');
    }

    private function hasIndex(string $table, string $index): bool
    {
        try {
            foreach (Schema::getIndexes($table) as $existing) {
                if (($existing['name'] ?? null) === $index) {
                    return true;
                }
            }
        } catch (\Throwable) {
            // 拿不到索引信息就当不存在：建重复索引会报错，但比静默漏建好
        }
        return false;
    }
};
