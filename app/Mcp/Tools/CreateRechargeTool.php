<?php

namespace App\Mcp\Tools;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Mcp\McpAudit;
use App\Services\Mcp\McpConfig;
use App\Services\Mcp\McpPresenter as P;
use App\Services\Mcp\OrderPayService;
use App\Services\Recharge\RechargeConfig;
use App\Services\Recharge\RechargeService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('create_recharge')]
#[Title('余额充值')]
#[Description('替客户生成一张账户余额充值单，返回实付金额、到账金额（含赠送）和付款链接，由客户本人付款。只在客户明确要充值时调用。')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld(false)]
class CreateRechargeTool extends McpTool
{
    protected bool $ordering = true;

    public function schema(JsonSchema $schema): array
    {
        return [
            'amount_yuan' => $schema->string()->description('充值金额（元），例如 "100"。')->required(),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $limit = McpConfig::fromSettings()->dailyOrderLimit;
        if ($this->token && McpAudit::ordersToday((int) $this->token->id) >= $limit) {
            throw new ApiException("这个令牌今天已经建了 {$limit} 单，达到上限。请明天再试，或到面板充值。");
        }
        $amount = $request->get('amount_yuan');
        if (!is_numeric($amount) || (float) $amount <= 0) {
            throw new ApiException('请给出充值金额（元）。');
        }
        $config = RechargeConfig::fromSettings();
        $recharge = app(RechargeService::class)->create(User::findOrFail($user->id), (int) round((float) $amount * 100));
        $this->auditRef = (string) $recharge->trade_no;

        return self::result([
            'trade_no' => (string) $recharge->trade_no,
            'pay_yuan' => P::yuan($recharge->pay_amount),
            'bonus_yuan' => P::yuan($recharge->bonus_amount),
            'credit_yuan' => P::yuan($recharge->total_credit),
            'payment_url' => OrderPayService::url((string) $recharge->trade_no),
            'pay_within_minutes' => 120,
            'limits' => ['min_yuan' => P::yuan($config->minAmount), 'max_yuan' => P::yuan($config->maxAmount)],
            'next_step' => '请客户点开 payment_url 选择支付方式付款；到账后余额可用于续费、下单。',
        ]);
    }
}
