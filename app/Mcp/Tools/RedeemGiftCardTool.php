<?php

namespace App\Mcp\Tools;

use App\Exceptions\ApiException;
use App\Models\Plan;
use App\Models\User;
use App\Services\GiftCardService;
use App\Services\Mcp\McpPresenter as P;
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

#[Name('redeem_gift_card')]
#[Title('兑换礼品卡')]
#[Description('把客户给的礼品卡 / 兑换码兑换到他的账户（余额、流量、天数或套餐）。兑换后不能撤销；兑换码只用一次，不要在对话里重复展示。')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
class RedeemGiftCardTool extends McpTool
{
    protected bool $ordering = true;

    public function schema(JsonSchema $schema): array
    {
        return [
            'code' => $schema->string()->description('兑换码，8–32 位。')->required(),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $code = trim((string) ($request->get('code') ?? ''));
        if (strlen($code) < 8 || strlen($code) > 32) {
            throw new ApiException('兑换码是 8–32 位，请让客户核对一下。');
        }
        // 与面板兑换共用限流桶：借 AI 也不能多试几个码
        self::throttle("giftcard_redeem:user:{$user->id}", 5, 60, '兑换太频繁，请一分钟后再试。', 'gift-card-redeem');

        $service = new GiftCardService($code);
        $service->setUser(User::findOrFail($user->id));
        $service->validate();
        $result = $service->redeem(['user_agent' => 'mcp']);
        $r = (array) ($result['rewards'] ?? []);
        $plan = isset($r['plan_id']) ? Plan::find($r['plan_id']) : null;

        return self::result([
            'redeemed' => true,
            'card' => (string) ($result['template_name'] ?? ''),
            'rewards' => array_filter([
                'balance_yuan' => !empty($r['balance']) ? P::yuan((int) $r['balance']) : null,
                'traffic_gb' => !empty($r['transfer_enable']) ? P::gb((int) $r['transfer_enable']) : null,
                'extra_devices' => !empty($r['device_limit']) ? (int) $r['device_limit'] : null,
                'traffic_reset' => !empty($r['reset_package']) ? true : null,
                'plan' => $plan ? (string) $plan->name : null,
                'plan_days' => $plan && !empty($r['plan_validity_days']) ? (int) $r['plan_validity_days'] : null,
                'extended_days' => !empty($r['expire_days']) ? (int) $r['expire_days'] : null,
            ], fn ($v) => $v !== null),
        ]);
    }
}
