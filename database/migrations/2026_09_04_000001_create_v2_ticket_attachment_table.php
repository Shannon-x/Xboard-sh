<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('v2_ticket_attachment')) {
            return;
        }

        Schema::create('v2_ticket_attachment', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('user_id')->comment('上传者');
            $table->integer('ticket_id')->nullable()->comment('绑定后的工单');
            $table->integer('ticket_message_id')->nullable()->comment('绑定后的消息；NULL = 已上传但尚未随消息发出');
            $table->string('driver', 16)->comment('local | s3，记录上传时的驱动，切换驱动后旧文件仍能被删除');
            $table->string('path', 255)->comment('存储 key（local 为 storage/app 下相对路径，s3 为对象 key）');
            $table->string('original_name', 255);
            $table->string('mime', 128);
            $table->unsignedBigInteger('size');
            $table->boolean('is_image')->default(false);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('access_key', 64)->unique('uniq_ticket_attachment_access_key');
            $table->integer('created_at');
            $table->integer('updated_at');

            $table->index('ticket_message_id', 'idx_ticket_attachment_message');
            $table->index('ticket_id', 'idx_ticket_attachment_ticket');
            $table->index(['user_id', 'created_at'], 'idx_ticket_attachment_user_time');
            $table->index('created_at', 'idx_ticket_attachment_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_ticket_attachment');
    }
};
