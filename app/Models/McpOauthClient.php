<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AI 软件动态注册的 OAuth 客户端（见 App\Services\Mcp\McpOAuthClients）。
 *
 * @property int $id
 * @property string $client_id
 * @property string|null $secret_hash
 * @property string|null $name
 * @property array $redirect_uris
 * @property string $auth_method   none | client_secret_post | client_secret_basic
 * @property string|null $ip
 * @property int $created_at
 * @property int $updated_at
 */
class McpOauthClient extends Model
{
    protected $table = 'v2_mcp_oauth_client';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'redirect_uris' => 'array',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}
