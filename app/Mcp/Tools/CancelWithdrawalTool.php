<?php

namespace App\Mcp\Tools;

use App\Exceptions\ApiException;
use App\Models\CommissionWithdrawal;
use App\Models\User;
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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('cancel_withdrawal')]
#[Title('取消提现申请')]
#[Description('取消客户还在处理中的那笔佣金提现（同一时间只会有一笔），冻结的佣金退回可用佣金。已打款或已驳回的不能取消。')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent]
class CancelWithdrawalTool extends McpTool
{
    protected bool $ordering = true;

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $pending = CommissionWithdrawal::where('user_id', $user->id)
            ->where('status', CommissionWithdrawal::STATUS_PENDING)->orderByDesc('id')->first();
        if (!$pending) {
            throw new ApiException('没有处理中的提现申请。');
        }
        $this->auditRef = $pending->reference();
        $withdrawal = (new CommissionWithdrawalService())->cancel(User::findOrFail($user->id), (int) $pending->id);

        return self::result([
            'done' => true,
            'withdrawal' => McpCommission::row($withdrawal),
            'commission_available_yuan' => P::yuan((int) User::where('id', $user->id)->value('commission_balance')),
        ]);
    }
}
