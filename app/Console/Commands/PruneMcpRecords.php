<?php

namespace App\Console\Commands;

use App\Models\McpCallLog;
use App\Models\McpOauthClient;
use App\Models\McpOauthGrant;
use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * 客户 AI 助手（MCP）的库表清理，每天跑一次（见 Console\Kernel）。三张表原先只增不删：
 *
 * - v2_mcp_call_log：每次工具调用一行，超过保留期（默认 180 天）的删掉。每日下单上限、最后一次 IP
 *   只看最近一天，保留期再短也不影响功能。
 * - v2_mcp_oauth_grant：令牌已经不在了（客户断开、闲置过期被 sanctum:prune-expired 删掉）的授权。
 *   这种授权的 refresh token 本来就换不到新令牌，留着只占地方。
 * - v2_mcp_oauth_client：动态注册的 AI 软件登记，长期（默认 180 天）没人用、也没有任何授权挂着的删掉。
 *   用过的登记每天会被刷新一次 updated_at（McpOAuthClients::find），正在用的不会被删。
 */
class PruneMcpRecords extends Command
{
    public const LOG_RETENTION_DAYS = 180;
    public const CLIENT_IDLE_DAYS = 180;

    protected $signature = 'mcp:prune {--dry-run : 只统计不删除}';

    protected $description = '清理客户 AI 助手的过期调用记录、失效授权和长期没用过的 AI 软件登记';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $logs = McpCallLog::where('created_at', '<', time() - self::LOG_RETENTION_DAYS * 86400);
        $logCount = $dryRun ? $logs->count() : $this->deleteInChunks($logs);

        $tokenTable = (new PersonalAccessToken())->getTable();
        $orphanGrants = McpOauthGrant::where(function ($q) use ($tokenTable) {
            $q->whereNull('token_id')->orWhereNotExists(function ($sub) use ($tokenTable) {
                $sub->selectRaw('1')->from($tokenTable)->whereColumn($tokenTable . '.id', 'v2_mcp_oauth_grant.token_id');
            });
        });
        $grantCount = $dryRun ? $orphanGrants->count() : $orphanGrants->delete();

        $idleClients = McpOauthClient::where('updated_at', '<', time() - self::CLIENT_IDLE_DAYS * 86400)
            ->whereNotExists(function ($sub) {
                $sub->selectRaw('1')->from('v2_mcp_oauth_grant')->whereColumn('v2_mcp_oauth_grant.client_id', 'v2_mcp_oauth_client.client_id');
            });
        $clientCount = $dryRun ? $idleClients->count() : $idleClients->delete();

        $this->info(sprintf(
            '%s调用记录 %d 条（保留 %d 天），失效授权 %d 条，长期未用的 AI 软件登记 %d 条',
            $dryRun ? '[dry-run] ' : '',
            $logCount,
            self::LOG_RETENTION_DAYS,
            $grantCount,
            $clientCount
        ));
        return self::SUCCESS;
    }

    /** 调用记录可能很多：分批删，不一次锁住整张表 */
    private function deleteInChunks($query): int
    {
        $total = 0;
        do {
            $ids = (clone $query)->orderBy('id')->limit(5000)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $total += McpCallLog::whereIn('id', $ids)->delete();
        } while ($ids->count() === 5000);
        return $total;
    }
}
