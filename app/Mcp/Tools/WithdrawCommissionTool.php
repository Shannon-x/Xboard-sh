<?php

namespace App\Mcp\Tools;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Commission\WithdrawalConfig;
use App\Services\CommissionWithdrawalService;
use App\Services\Mcp\McpCommission;
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
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('withdraw_commission')]
#[Title('佣金提现')]
#[Description('替客户申请佣金提现，打到客户在面板填过的收款地址（你不能填新地址；第一次提现或换地址要客户到面板操作）。先不带 confirm 调一次，把金额、收款网络、地址头尾和预计到账 USDT 讲给客户；客户同意后带 confirm=true 再调。申请后佣金先冻结，管理员打款后客户会收到邮件；处理前可以用 cancel_withdrawal 取消。')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld(false)]
class WithdrawCommissionTool extends McpTool
{
    protected bool $ordering = true;

    public function schema(JsonSchema $schema): array
    {
        return [
            'amount_yuan' => $schema->string()->description('提现金额（元），例如 "150"。全部提出就填 get_invite_info 里的 commission_available_yuan。')->required(),
            'confirm' => $schema->boolean()->description('客户确认金额和收款地址后传 true；不传只预览。'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $amount = $request->get('amount_yuan');
        if (!is_numeric($amount) || (float) $amount <= 0) {
            throw new ApiException('请给出提现金额（元）。');
        }
        $cents = (int) round((float) $amount * 100);
        $config = WithdrawalConfig::fromSettings();
        $user = User::findOrFail($user->id);
        $plan = McpCommission::prepare($user, $cents, $config);
        $quote = $plan['quote'];
        $preview = [
            'amount_yuan' => P::yuan($cents),
            'to' => [
                'network' => WithdrawalConfig::chainLabel($plan['chain']),
                'address' => McpCommission::mask($plan['address']),
            ],
            'estimated_usdt' => $quote['net'],
            'fee_usdt' => $quote['fee'],
            'usdt_rate' => $quote['rate'],
            'commission_left_yuan' => P::yuan((int) $user->commission_balance - $cents),
        ];

        if (!self::confirmed($request)) {
            return self::result(['done' => false] + $preview + [
                'next_step' => '把金额、收款网络和地址头尾讲给客户核对；客户同意后带 confirm=true 再调用。最终到账以打款时的汇率和通道费为准。',
            ]);
        }

        // 与面板提现共用限流桶
        self::throttle("withdraw_apply:user:{$user->id}", 5, 60, '提交太频繁，请一分钟后再试。', 'withdraw-apply');
        $withdrawal = (new CommissionWithdrawalService($config))
            ->apply($user, $cents, $plan['chain'], $plan['address'], [], true, McpCommission::AI_NOTE);
        $this->auditRef = $withdrawal->reference();

        return self::result([
            'done' => true,
            'withdrawal' => McpCommission::row($withdrawal),
            'next_step' => '佣金已冻结。管理员打款后会回复工单并发邮件通知客户；处理前客户想撤回，可以用 cancel_withdrawal。',
        ]);
    }
}
