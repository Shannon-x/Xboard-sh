<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 上网按量与到货提醒（见 App\Services\Payg\PaygService、App\Services\RestockNotifier）：
 *
 *   v2_user.payg_auto_topup   用户自己打开的「自动补充」：按量余额低于门槛时从账户余额买一份。
 *                             与 auto_renew 分开：换成包月不会因此被自动续费。
 *   v2_payg_log               按量钱包的事件：充值 / 自动补充（含赠送）、月度保底补扣。
 *                             钱包余额本身仍是 v2_user 的 transfer_enable − u − d，这张表只用来
 *                             展示扣费明细、判断首充、让月度保底每人每月只结算一次（唯一键）。
 *   v2_plan_restock_notify    售罄套餐的到货提醒登记：每次登记最多发一封，发过记 notified_at。
 *
 * 三处都是新增，老代码不读不写；payg_plan_ids 为空时整套逻辑不触发。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('v2_user', 'payg_auto_topup')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->tinyInteger('payg_auto_topup')->default(0)->after('auto_renew');
            });
        }

        if (!Schema::hasTable('v2_payg_log')) {
            Schema::create('v2_payg_log', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedInteger('plan_id')->nullable();
                $table->unsignedBigInteger('order_id')->nullable();
                $table->string('type', 16)->comment('recharge / auto_topup / floor');
                $table->tinyInteger('opening')->default(0)->comment('这一单开了新钱包（新购 / 从别的套餐换过来）');
                $table->string('month', 7)->nullable()->comment('floor：结算的月份 YYYY-MM');
                $table->integer('amount')->default(0)->comment('分：充值为本单实付，floor 为补扣金额');
                $table->bigInteger('bytes')->default(0)->comment('充值买到的流量 / floor 从余额扣掉的流量');
                $table->bigInteger('bonus_bytes')->default(0)->comment('充值档位赠送');
                $table->bigInteger('first_bonus_bytes')->default(0)->comment('首次充值赠送');
                $table->bigInteger('usage_bytes')->default(0)->comment('floor：这个月的计费用量');
                $table->integer('created_at');
                $table->integer('updated_at');

                $table->index(['user_id', 'id'], 'idx_payg_log_user');
                $table->unique('order_id', 'uniq_payg_log_order');
                $table->unique(['user_id', 'type', 'month'], 'uniq_payg_log_month');
            });
        }

        if (!Schema::hasTable('v2_plan_restock_notify')) {
            Schema::create('v2_plan_restock_notify', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('plan_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('email', 64);
                $table->integer('notified_at')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');

                $table->unique(['plan_id', 'email'], 'uniq_restock_plan_email');
                $table->index(['notified_at', 'plan_id'], 'idx_restock_pending');
                $table->index('user_id', 'idx_restock_user');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_plan_restock_notify');
        Schema::dropIfExists('v2_payg_log');
        if (Schema::hasColumn('v2_user', 'payg_auto_topup')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->dropColumn('payg_auto_topup');
            });
        }
    }
};
