<?php

namespace App\Mcp\Tools;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Commission\CommissionTransfer;
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

#[Name('transfer_commission')]
#[Title('佣金转余额')]
#[Description('把客户的可用佣金转进账户余额（和面板邀请页「划转」一样），之后下单、续费自动抵扣。转进余额的钱不能再提现。先不带 confirm 预览，客户同意后带 confirm=true。')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld(false)]
class TransferCommissionTool extends McpTool
{
    protected bool $ordering = true;

    public function schema(JsonSchema $schema): array
    {
        return [
            'amount_yuan' => $schema->string()->description('转入余额的金额（元），例如 "50"。全部转就填 get_invite_info 里的 commission_available_yuan。')->required(),
            'confirm' => $schema->boolean()->description('客户确认后传 true；不传只预览。'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $amount = $request->get('amount_yuan');
        if (!is_numeric($amount) || (float) $amount <= 0) {
            throw new ApiException('请给出要转入余额的金额（元）。');
        }
        $cents = (int) round((float) $amount * 100);
        $user = User::findOrFail($user->id);
        CommissionTransfer::assertAllowed($user);
        if ($cents > (int) $user->commission_balance) {
            throw new ApiException('可用佣金只有 ¥' . P::yuan((int) $user->commission_balance) . '。');
        }

        if (!self::confirmed($request)) {
            return self::result([
                'done' => false,
                'amount_yuan' => P::yuan($cents),
                'commission_after_yuan' => P::yuan((int) $user->commission_balance - $cents),
                'balance_after_yuan' => P::yuan((int) $user->balance + $cents),
                'effect' => '转进余额后可用于下单、续费、加购，但不能再提现。',
                'next_step' => '客户同意后带 confirm=true 再调用。',
            ]);
        }

        $after = CommissionTransfer::move((int) $user->id, $cents);

        return self::result([
            'done' => true,
            'amount_yuan' => P::yuan($cents),
            'commission_available_yuan' => P::yuan((int) $after->commission_balance),
            'balance_yuan' => P::yuan((int) $after->balance),
        ]);
    }
}
