<?php

namespace App\Mcp\Tools;

use App\Models\BalanceLog;
use App\Models\User;
use App\Services\Mcp\McpPresenter as P;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_balance_log')]
#[Title('余额明细')]
#[Description('账户余额的进出明细：下单抵扣、取消退回、充值、礼品卡、佣金转入、后台调整。回答「我的余额怎么少了 / 多了」时用它。')]
#[IsReadOnly]
#[IsIdempotent]
class ListBalanceLogTool extends McpTool
{
    private const TYPE_NAMES = [
        BalanceLog::TYPE_ORDER_PAY => '下单抵扣',
        BalanceLog::TYPE_ORDER_CANCEL => '取消订单退回',
        BalanceLog::TYPE_ORDER_REFUND => '换套餐折抵退回',
        BalanceLog::TYPE_GIFT_CARD => '礼品卡',
        BalanceLog::TYPE_COMMISSION_TRANSFER => '佣金转入',
        BalanceLog::TYPE_ADMIN_ADJUST => '客服调整',
        BalanceLog::TYPE_RECHARGE => '充值',
    ];

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->description('最近几条，1–30，默认 10。')->min(1)->max(30),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $limit = max(1, min(30, (int) ($request->get('limit') ?? 10)));
        $rows = BalanceLog::where('user_id', $user->id)->orderByDesc('id')->limit($limit)->get();

        return self::result([
            'balance_yuan' => P::yuan((int) (User::where('id', $user->id)->value('balance') ?? 0)),
            'entries' => $rows->map(fn (BalanceLog $r) => [
                'at' => P::time($r->created_at ? (int) $r->getRawOriginal('created_at') : null),
                'type' => self::TYPE_NAMES[$r->type] ?? (string) $r->type,
                'amount_yuan' => ((int) $r->amount >= 0 ? '+' : '-') . P::yuan(abs((int) $r->amount)),
                'balance_after_yuan' => P::yuan((int) $r->balance_after),
                'remark' => $r->remark !== null ? P::plain((string) $r->remark, 120) : null,
                'trade_no' => $r->ref_type === 'order' ? (string) $r->ref_id : null,
            ])->values()->all(),
        ]);
    }
}
