<?php

namespace App\Mcp\Tools;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Mcp\McpAccountSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('update_account_settings')]
#[Title('修改账号设置')]
#[Description('按客户的话开关设置：自动续费（auto_renew）、按量自动补充（payg_auto_topup）、邮件通知类别（email_notifications）。只传要改的项。开自动续费前先用 get_account_settings 告诉客户会续什么、多少钱、余额够不够；余额不够可以建议 create_recharge 充值。')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent]
class UpdateAccountSettingsTool extends McpTool
{
    protected bool $ordering = true;

    public function schema(JsonSchema $schema): array
    {
        return [
            'auto_renew' => $schema->boolean()->description('true 开启、false 关闭自动续费。'),
            'payg_auto_topup' => $schema->boolean()->description('true 开启、false 关闭按量自动补充（只有按量套餐有）。'),
            'email_notifications' => $schema->object()->description(
                '要改的邮件通知类别 → true/false，例如 {"marketing": false}。类别：billing 账单与到期提醒、usage 流量与用量、support 工单回复、commission 推广与佣金、announcement 服务公告、marketing 活动与优惠。'
            ),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $notifications = $request->get('email_notifications');
        if ($notifications !== null && !is_array($notifications)) {
            throw new ApiException('email_notifications 要是「类别 → true/false」的对象。');
        }

        return self::result(McpAccountSettings::apply($user, [
            'auto_renew' => self::bool($request->get('auto_renew')),
            'payg_auto_topup' => self::bool($request->get('payg_auto_topup')),
            'email_notifications' => $notifications ?? [],
        ], request()->ip()));
    }

    private static function bool(mixed $value): ?bool
    {
        return $value === null ? null : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}
