<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\Mcp\McpAccountSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_account_settings')]
#[Title('账号设置')]
#[Description('查看客户能自己开关的设置：自动续费（会续什么、多少钱、余额够不够）、按量自动补充、各类邮件通知是否开启。改设置用 update_account_settings。')]
#[IsReadOnly]
#[IsIdempotent]
class GetAccountSettingsTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        return self::result(McpAccountSettings::view($user));
    }
}
