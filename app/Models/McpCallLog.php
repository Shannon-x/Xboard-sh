<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 客户 AI 助手（MCP）的一次工具调用（见 App\Services\Mcp\McpAudit）。
 *
 * @property int $id
 * @property int $user_id
 * @property int $token_id
 * @property string $tool
 * @property int $ok
 * @property string|null $ref       下单类工具：订单号
 * @property string|null $message   失败原因
 * @property string|null $ip
 * @property int $created_at
 */
class McpCallLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'v2_mcp_call_log';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'user_id' => 'integer',
        'token_id' => 'integer',
        'ok' => 'integer',
    ];
}
