<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 客户 AI 助手的一键授权（OAuth，见 App\Services\Mcp\McpOAuthServer）。
 *
 * v2_mcp_oauth_client：AI 软件自助登记的客户端（动态注册，RFC 7591）。ChatGPT 每建一个连接登记一次、
 * 之后一直复用，所以只增不删；用「客户端元数据文档」（client_id 是一个 https 网址）的软件不进这张表。
 * v2_mcp_oauth_grant：客户点了「允许」之后的授权，记着当前那枚令牌和换新用的 refresh token（只存哈希）。
 *
 * 纯新增，老代码不读不写；mcp_enable 为 0 时没有任何写入。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_mcp_oauth_client')) {
            Schema::create('v2_mcp_oauth_client', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('client_id', 64)->unique();
                $table->char('secret_hash', 64)->nullable()->comment('sha256；公开客户端（none）为空');
                $table->string('name', 80)->nullable()->comment('软件自报的名字，只作参考');
                $table->text('redirect_uris');
                $table->string('auth_method', 32)->default('none');
                $table->string('ip', 64)->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }
        if (!Schema::hasTable('v2_mcp_oauth_grant')) {
            Schema::create('v2_mcp_oauth_grant', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user_id');
                $table->char('client_key', 64)->comment('sha256(client_id)');
                $table->string('client_id', 512);
                $table->string('app_name', 80);
                $table->string('redirect_host', 255);
                $table->unsignedBigInteger('token_id')->nullable()->comment('当前那枚 AI 助手令牌（personal_access_tokens.id）');
                $table->char('refresh_hash', 64)->nullable()->unique();
                $table->integer('created_at');
                $table->integer('updated_at');

                $table->index(['user_id', 'client_key'], 'idx_mcp_oauth_grant_user');
                $table->index('token_id', 'idx_mcp_oauth_grant_token');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_mcp_oauth_grant');
        Schema::dropIfExists('v2_mcp_oauth_client');
    }
};
