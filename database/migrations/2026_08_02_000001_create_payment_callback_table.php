<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('v2_payment_callback')) {
            return;
        }

        Schema::create('v2_payment_callback', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('fingerprint', 64)->unique('uniq_payment_callback_fingerprint');
            $table->unsignedInteger('payment_id');
            $table->unsignedInteger('order_id');
            $table->string('trade_no', 36);
            $table->integer('created_at');

            $table->index('order_id', 'idx_payment_callback_order');
            $table->index(['payment_id', 'created_at'], 'idx_payment_callback_payment_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_payment_callback');
    }
};
