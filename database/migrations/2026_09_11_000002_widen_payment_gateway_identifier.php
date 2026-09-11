<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // StripeSubscription / PayPalSubscription are 18 characters. SQLite did
        // not enforce the old varchar(16), masking the production MySQL failure.
        Schema::table('v2_payment', fn (Blueprint $table) => $table->string('payment', 64)->change());
    }

    public function down(): void
    {
        $hasLongIdentifier = DB::table('v2_payment')->pluck('payment')
            ->contains(fn ($name) => mb_strlen($name) > 16);
        if ($hasLongIdentifier) {
            throw new RuntimeException('Cannot shrink payment identifiers without truncating existing gateway names.');
        }
        Schema::table('v2_payment', fn (Blueprint $table) => $table->string('payment', 16)->change());
    }
};
