<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('v2_user_payout_profile')) {
            return;
        }

        Schema::create('v2_user_payout_profile', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('user_id')->unique('uniq_user_payout_profile_user');
            $table->string('chain_code', 32);
            $table->string('address', 255);
            $table->integer('qr_attachment_id')->nullable()->comment('上次随申请上传的收款二维码（工单附件）；下次申请可沿用（复制一份绑定到新工单）');
            $table->integer('created_at');
            $table->integer('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_user_payout_profile');
    }
};
