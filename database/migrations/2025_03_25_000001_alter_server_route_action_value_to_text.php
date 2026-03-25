<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server_route', function (Blueprint $table) {
            $table->text('action_value')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('v2_server_route', function (Blueprint $table) {
            $table->string('action_value')->nullable()->change();
        });
    }
};
