<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 余额充值从 BalanceRecharge 插件并入核心。
 *
 * - 单据表 plugin_recharge_orders 原样改名为 v2_recharge_order（id 不变：v2_payment_verification 与
 *   plugin_payment_attempts 都按 id 引用充值单），补上自动取消 / 回查要用的索引；
 * - v2_payment_callback 加 subject_type：同一笔网关流水只能入账一张单据，订单与充值单共用一张去重表；
 * - 插件配置（开关、最低 / 最高金额、赠送档位）搬到系统设置，然后删掉插件行。插件目录若还留在
 *   plugins/ 下也不会再被加载（只加载 v2_plugins 里启用的行），PluginManager 也拒绝重新安装它。
 *
 * 改表名期间旧代码（插件）读不到表，所以这条迁移要在停掉 web / horizon 之后、起新容器之前跑。
 */
return new class extends Migration
{
    private const LEGACY_TABLE = 'plugin_recharge_orders';
    private const TABLE = 'v2_recharge_order';
    private const PLUGIN_CODE = 'balance_recharge';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            if (Schema::hasTable(self::LEGACY_TABLE)) {
                Schema::rename(self::LEGACY_TABLE, self::TABLE);
            } else {
                Schema::create(self::TABLE, function (Blueprint $table) {
                    $table->id();
                    $table->integer('user_id')->index();
                    $table->string('trade_no', 64)->unique();
                    $table->integer('deposit_amount')->comment('充值金额（分）');
                    $table->integer('bonus_amount')->default(0)->comment('赠送金额（分），下单时按当时的档位定死');
                    $table->integer('total_credit')->comment('入账总额（分）= deposit + bonus');
                    $table->integer('pay_amount')->comment('应付金额（分），含手续费');
                    $table->integer('handling_amount')->nullable()->comment('手续费（分）');
                    $table->integer('payment_id')->nullable();
                    $table->string('payment_method', 64)->nullable();
                    $table->tinyInteger('status')->default(0)->comment('0待支付 2已取消 3已完成');
                    $table->string('callback_no', 255)->nullable();
                    $table->integer('invite_user_id')->nullable();
                    $table->integer('commission_balance')->default(0)->comment('佣金（分），充值不返佣，恒为 0');
                    $table->integer('paid_at')->nullable();
                    $table->integer('created_at');
                    $table->integer('updated_at');
                });
            }
        }

        $indexes = [
            'idx_recharge_status_created' => ['status', 'created_at'],
            'idx_recharge_callback_no' => ['callback_no'],
            'idx_recharge_paid_at' => ['paid_at'],
        ];
        foreach ($indexes as $name => $columns) {
            if (!Schema::hasIndex(self::TABLE, $name)) {
                Schema::table(self::TABLE, function (Blueprint $table) use ($name, $columns) {
                    $table->index($columns, $name);
                });
            }
        }

        if (Schema::hasTable('v2_payment_callback') && !Schema::hasColumn('v2_payment_callback', 'subject_type')) {
            Schema::table('v2_payment_callback', function (Blueprint $table) {
                $table->string('subject_type', 16)->default('order')->comment('order / recharge，order_id 即该单据的 id');
            });
        }

        $this->migratePluginSettings();
    }

    public function down(): void
    {
        if (Schema::hasTable('v2_payment_callback') && Schema::hasColumn('v2_payment_callback', 'subject_type')) {
            Schema::table('v2_payment_callback', function (Blueprint $table) {
                $table->dropColumn('subject_type');
            });
        }
        if (Schema::hasTable(self::TABLE) && !Schema::hasTable(self::LEGACY_TABLE)) {
            foreach (['idx_recharge_status_created', 'idx_recharge_callback_no', 'idx_recharge_paid_at'] as $name) {
                if (Schema::hasIndex(self::TABLE, $name)) {
                    Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropIndex($name));
                }
            }
            Schema::rename(self::TABLE, self::LEGACY_TABLE);
        }
        $this->restorePluginRow();
    }

    /**
     * 回滚时按当前系统设置把插件行写回去（插件目录要由回滚流程一起放回 plugins/）。
     * 只在确实启用过充值时才写，免得给从没装过插件的站点凭空装一个。
     */
    private function restorePluginRow(): void
    {
        if (!Schema::hasTable('v2_plugins') || DB::table('v2_plugins')->where('code', self::PLUGIN_CODE)->exists()) {
            return;
        }
        $values = DB::table('v2_settings')
            ->whereIn('name', ['recharge_enable', 'recharge_min_amount', 'recharge_max_amount', 'recharge_bonus_tiers'])
            ->pluck('value', 'name');
        if (!isset($values['recharge_enable'])) {
            return;
        }
        $tiers = json_decode((string) ($values['recharge_bonus_tiers'] ?? '[]'), true) ?: [];
        $now = now();
        $row = [
            'name' => '余额充值',
            'code' => self::PLUGIN_CODE,
            'version' => '1.2.1',
            'is_enabled' => (bool) (int) $values['recharge_enable'],
            'config' => json_encode([
                'enabled' => true,
                'min_amount' => (float) ($values['recharge_min_amount'] ?? 1),
                'max_amount' => (float) ($values['recharge_max_amount'] ?? 99999),
                'deposit_bonus' => array_map(fn ($t) => $t['amount'] . ':' . $t['bonus'], $tiers),
            ], JSON_UNESCAPED_UNICODE),
            'installed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if (Schema::hasColumn('v2_plugins', 'type')) {
            $row['type'] = 'feature';
        }
        DB::table('v2_plugins')->insert($row);
    }

    /**
     * 插件配置 → 系统设置。只在插件确实装过时搬；系统设置里已有值（重复跑迁移、或管理员已在新后台改过）不覆盖。
     */
    private function migratePluginSettings(): void
    {
        if (!Schema::hasTable('v2_plugins')) {
            return;
        }
        $row = DB::table('v2_plugins')->where('code', self::PLUGIN_CODE)->first();
        if (!$row) {
            return;
        }

        $config = is_string($row->config) ? (json_decode($row->config, true) ?: []) : [];
        $enabled = (bool) $row->is_enabled && (bool) ($config['enabled'] ?? true);

        $tiers = [];
        foreach ((array) ($config['deposit_bonus'] ?? []) as $tier) {
            if (!is_string($tier) || !str_contains($tier, ':')) {
                continue;
            }
            [$amount, $bonus] = array_map('trim', explode(':', $tier, 2));
            if (is_numeric($amount) && is_numeric($bonus) && (float) $amount > 0 && (float) $bonus > 0) {
                $tiers[] = ['amount' => (float) $amount, 'bonus' => (float) $bonus];
            }
        }

        $settings = [
            'recharge_enable' => $enabled ? 1 : 0,
            'recharge_min_amount' => (float) ($config['min_amount'] ?? 1),
            'recharge_max_amount' => (float) ($config['max_amount'] ?? 99999),
            'recharge_bonus_tiers' => $tiers,
        ];
        $existing = DB::table('v2_settings')->whereIn('name', array_keys($settings))->pluck('name')->all();
        $missing = array_diff_key($settings, array_flip($existing));
        if ($missing) {
            // 走 admin_setting：除了写库还会清掉 Redis 里的设置缓存，否则运行中的进程读到的仍是「没有这项」
            admin_setting($missing);
        }

        DB::table('v2_plugins')->where('code', self::PLUGIN_CODE)->delete();
    }
};
