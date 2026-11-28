<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 付款异常自动封号的用户，封号满 180 天后系统自动删号（见 App\Services\EnforcedUserPurge），不再等管理员确认。
 * user_deleted_at 记下删号时间，每日邮件按它汇总「本期自动删了谁」；删前的邮箱、单号等写在 enforce_snapshot.deleted。
 *
 * 纯新增可空列与索引，老代码不受影响。
 */
return new class extends Migration
{
    private const TABLE = 'v2_payment_verification';
    private const INDEX = 'idx_payment_verification_user_deleted_at';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }
        if (!Schema::hasColumn(self::TABLE, 'user_deleted_at')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->integer('user_deleted_at')->nullable()->comment('封号满期自动删号的时间，删前的用户信息见 enforce_snapshot.deleted');
            });
        }
        if (!Schema::hasIndex(self::TABLE, self::INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->index('user_deleted_at', self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }
        if (Schema::hasIndex(self::TABLE, self::INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropIndex(self::INDEX);
            });
        }
        if (Schema::hasColumn(self::TABLE, 'user_deleted_at')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropColumn('user_deleted_at');
            });
        }
    }
};
