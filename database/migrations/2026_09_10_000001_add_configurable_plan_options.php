<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_plan', fn (Blueprint $table) => $table->json('customization')->nullable());
        Schema::table('v2_order', fn (Blueprint $table) => $table->json('plan_snapshot')->nullable());
        Schema::table('v2_user', fn (Blueprint $table) => $table->json('plan_options')->nullable());
    }

    public function down(): void
    {
        Schema::table('v2_user', fn (Blueprint $table) => $table->dropColumn('plan_options'));
        Schema::table('v2_order', fn (Blueprint $table) => $table->dropColumn('plan_snapshot'));
        Schema::table('v2_plan', fn (Blueprint $table) => $table->dropColumn('customization'));
    }
};
