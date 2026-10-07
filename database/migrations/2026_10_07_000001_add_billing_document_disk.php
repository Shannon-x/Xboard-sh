<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 收据 / 账单归档可以放到 S3 兼容对象存储：v2_billing_document 加 disk 列记录文件在哪（local | s3），
 * 切换存储位置后旧文件仍按写入时的位置读。
 *
 * 新装实例由 2026_10_06_000003 建表时直接带上这一列，这里只给已经跑过那次迁移的实例补列；可重复执行。
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('v2_billing_document') && !Schema::hasColumn('v2_billing_document', 'disk')) {
            Schema::table('v2_billing_document', fn (Blueprint $table) => $table->string('disk', 16)->default('local')->after('path'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('v2_billing_document') && Schema::hasColumn('v2_billing_document', 'disk')) {
            Schema::table('v2_billing_document', fn (Blueprint $table) => $table->dropColumn('disk'));
        }
    }
};
