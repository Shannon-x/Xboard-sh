<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 管理员两步验证（TOTP）。独立一张表，只有管理员才有行，v2_user 不动。
 *
 *   secret           已启用的 TOTP 密钥（APP_KEY 加密存储，库被拖走也拿不到明文）
 *   pending_secret   等待确认的新密钥：首次绑定 / 重新绑定先写这里，验证通过才转正
 *   confirmed_at     绑定完成时间；null = 尚未启用
 *   recovery_codes   8 个恢复码的 bcrypt 哈希（JSON 数组），用掉一个删一个
 *   last_used_step   最近一次通过验证的 30 秒时间步，同一个验证码不能用第二次
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('v2_admin_two_factor')) {
            return;
        }
        Schema::create('v2_admin_two_factor', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->text('secret')->nullable();
            $table->text('pending_secret')->nullable();
            $table->unsignedInteger('confirmed_at')->nullable();
            $table->text('recovery_codes')->nullable();
            $table->unsignedBigInteger('last_used_step')->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_admin_two_factor');
    }
};
