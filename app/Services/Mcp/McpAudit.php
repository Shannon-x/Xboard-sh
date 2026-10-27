<?php

namespace App\Services\Mcp;

use App\Models\McpCallLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 客户 AI 助手的调用审计：每次工具调用一行（v2_mcp_call_log）。写失败只记日志，不影响工具本身。
 */
class McpAudit
{
    /** 会建单的工具：每日上限按它们数 */
    public const ORDER_TOOLS = ['create_order', 'create_recharge'];

    public static function record(int $userId, int $tokenId, string $tool, bool $ok, ?string $ref = null, ?string $message = null, ?string $ip = null): void
    {
        try {
            McpCallLog::create([
                'user_id' => $userId,
                'token_id' => $tokenId,
                'tool' => Str::limit($tool, 64, ''),
                'ok' => $ok ? 1 : 0,
                'ref' => $ref !== null ? Str::limit($ref, 64, '') : null,
                'message' => $message !== null ? Str::limit($message, 250) : null,
                'ip' => $ip !== null ? Str::limit($ip, 64, '') : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('mcp audit write failed', ['tool' => $tool, 'error' => $e->getMessage()]);
        }
    }

    /** 这个令牌今天（站点时区）成功建了几单 */
    public static function ordersToday(int $tokenId): int
    {
        return McpCallLog::where('token_id', $tokenId)
            ->whereIn('tool', self::ORDER_TOOLS)
            ->where('ok', 1)
            ->whereNotNull('ref')
            ->where('created_at', '>=', now()->startOfDay()->getTimestamp())
            ->count();
    }
}
