<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 客户 AI 助手一键授权：记下上一枚 refresh token 的哈希（见 App\Services\Mcp\McpOAuthServer::refresh）。
 *
 * refresh token 每次换新都会轮换。已经换掉的旧 refresh token 又被拿来用，说明它落到了别人手里
 * （或 AI 软件出了问题）：过了短暂的重试宽限期后，按 OAuth 2.1 的建议连同整份授权一起作废。
 *
 * 纯新增一列，可为空；老数据不用回填。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_mcp_oauth_grant') || Schema::hasColumn('v2_mcp_oauth_grant', 'prev_refresh_hash')) {
            return;
        }
        Schema::table('v2_mcp_oauth_grant', function (Blueprint $table) {
            $table->char('prev_refresh_hash', 64)->nullable()->after('refresh_hash')->comment('上一枚 refresh token 的 sha256');
            $table->integer('refreshed_at')->nullable()->after('prev_refresh_hash')->comment('最近一次换新的时间');
            $table->index('prev_refresh_hash', 'idx_mcp_oauth_grant_prev_refresh');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_mcp_oauth_grant') || !Schema::hasColumn('v2_mcp_oauth_grant', 'prev_refresh_hash')) {
            return;
        }
        Schema::table('v2_mcp_oauth_grant', function (Blueprint $table) {
            $table->dropIndex('idx_mcp_oauth_grant_prev_refresh');
            $table->dropColumn(['prev_refresh_hash', 'refreshed_at']);
        });
    }
};
