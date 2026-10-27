<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 客户 AI 助手（MCP）的调用审计（见 App\Services\Mcp\McpAudit）：每次工具调用一行，
 * 记下谁（用户 / 令牌）、调了什么、成没成、从哪个 IP。下单类工具在 ref 里记订单号，
 * 后台据此统计「AI 下单」的笔数和成交，也用来数每个令牌当天建了几单（每日上限）。
 *
 * 纯新增，老代码不读不写；mcp_enable 为 0 时没有任何写入。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('v2_mcp_call_log')) {
            return;
        }
        Schema::create('v2_mcp_call_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('token_id');
            $table->string('tool', 64);
            $table->tinyInteger('ok')->default(1);
            $table->string('ref', 64)->nullable()->comment('下单类工具：订单号');
            $table->string('message', 255)->nullable()->comment('失败原因（给后台看，不含令牌）');
            $table->string('ip', 64)->nullable();
            $table->integer('created_at');

            $table->index(['user_id', 'id'], 'idx_mcp_call_user');
            $table->index(['token_id', 'created_at'], 'idx_mcp_call_token');
            $table->index(['tool', 'created_at'], 'idx_mcp_call_tool');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_mcp_call_log');
    }
};
