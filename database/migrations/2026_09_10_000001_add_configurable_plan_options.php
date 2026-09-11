<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // MySQL DDL is not transactional. Retry safely after a partially completed upgrade.
        foreach (['v2_plan' => 'customization', 'v2_order' => 'plan_snapshot', 'v2_user' => 'plan_options'] as $name => $column) {
            if (!Schema::hasColumn($name, $column)) {
                Schema::table($name, fn (Blueprint $table) => $table->json($column)->nullable());
            }
        }
    }

    public function down(): void
    {
        Schema::table('v2_user', fn (Blueprint $table) => $table->dropColumn('plan_options'));
        Schema::table('v2_order', fn (Blueprint $table) => $table->dropColumn('plan_snapshot'));
        Schema::table('v2_plan', fn (Blueprint $table) => $table->dropColumn('customization'));
    }
};
