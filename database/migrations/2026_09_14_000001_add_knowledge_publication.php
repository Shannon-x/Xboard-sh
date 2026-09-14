<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['visibility', 'slug', 'summary', 'published_at'] as $column) {
            if (Schema::hasColumn('v2_knowledge', $column)) {
                continue;
            }
            Schema::table('v2_knowledge', function (Blueprint $table) use ($column) {
                match ($column) {
                    'visibility' => $table->string('visibility', 16)->default('members'),
                    'slug' => $table->string('slug', 120)->nullable(),
                    'summary' => $table->text('summary')->nullable(),
                    'published_at' => $table->unsignedBigInteger('published_at')->nullable(),
                };
            });
        }
        if (!Schema::hasIndex('v2_knowledge', 'knowledge_language_slug_unique')) {
            Schema::table('v2_knowledge', fn (Blueprint $table) =>
                $table->unique(['language', 'slug'], 'knowledge_language_slug_unique'));
        }
        if (!Schema::hasIndex('v2_knowledge', 'knowledge_public_lookup')) {
            Schema::table('v2_knowledge', fn (Blueprint $table) =>
                $table->index(['visibility', 'show', 'language'], 'knowledge_public_lookup'));
        }
    }

    public function down(): void
    {
        // An old reader ignores visibility and exposes every show=1 row. Never
        // erase permissions or publication metadata to make that reader start.
        if (DB::table('v2_knowledge')->where(function ($query) {
            $query->where('visibility', '!=', 'members')->orWhereNull('visibility')
                ->orWhereNotNull('slug')->orWhereNotNull('summary')->orWhereNotNull('published_at');
        })->exists()) {
            throw new RuntimeException('Knowledge publication data cannot safely roll back to a visibility-unaware backend. Keep the new reader or perform an audited offline downgrade.');
        }
        Schema::table('v2_knowledge', function (Blueprint $table) {
            $table->dropUnique('knowledge_language_slug_unique');
            $table->dropIndex('knowledge_public_lookup');
            $table->dropColumn(['visibility', 'slug', 'summary', 'published_at']);
        });
    }
};
