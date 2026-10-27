<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 客户在授权页点了「允许」的一次授权（见 App\Services\Mcp\McpOAuthServer）。
 * 令牌每次换新（refresh）都换成新的一枚，token_id 跟着改；客户在 AI 助手页断开 = 删掉令牌和这一行。
 *
 * @property int $id
 * @property int $user_id
 * @property string $client_key     sha256(client_id)
 * @property string $client_id
 * @property string $app_name
 * @property string $redirect_host
 * @property int|null $token_id
 * @property string|null $refresh_hash
 * @property int $created_at
 * @property int $updated_at
 */
class McpOauthGrant extends Model
{
    protected $table = 'v2_mcp_oauth_grant';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'user_id' => 'integer',
        'token_id' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}
