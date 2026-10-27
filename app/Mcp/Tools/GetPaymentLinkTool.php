<?php

namespace App\Mcp\Tools;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\RechargeOrder;
use App\Models\User;
use App\Services\Mcp\McpPresenter as P;
use App\Services\Mcp\OrderPayService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_payment_link')]
#[Title('付款链接')]
#[Description('取一张待付款订单（或充值单）的付款链接和应付金额。不传 trade_no 时取最近一张待付单。客户在链接里自己选支付方式付款，不用登录面板。')]
#[IsReadOnly]
#[IsIdempotent]
class GetPaymentLinkTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'trade_no' => $schema->string()->description('订单号，可选。'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $tradeNo = trim((string) ($request->get('trade_no') ?? ''));
        $order = Order::where('user_id', $user->id)->where('status', Order::STATUS_PENDING)
            ->when($tradeNo !== '', fn ($q) => $q->where('trade_no', $tradeNo))
            ->orderByDesc('id')->first();
        if ($order) {
            return self::result([
                'trade_no' => (string) $order->trade_no,
                'kind' => P::orderType((int) $order->type),
                'cash_due_yuan' => P::yuan($order->total_amount),
                'balance_used_yuan' => P::yuan($order->balance_amount),
                'payment_url' => OrderPayService::url((string) $order->trade_no),
                'expires_at' => P::time((int) $order->created_at + \App\Jobs\OrderHandleJob::PENDING_CANCEL_AFTER),
            ]);
        }
        $recharge = RechargeOrder::where('user_id', $user->id)->where('status', RechargeOrder::STATUS_PENDING)
            ->when($tradeNo !== '', fn ($q) => $q->where('trade_no', $tradeNo))
            ->orderByDesc('id')->first();
        if ($recharge) {
            return self::result([
                'trade_no' => (string) $recharge->trade_no,
                'kind' => '余额充值',
                'cash_due_yuan' => P::yuan($recharge->pay_amount),
                'payment_url' => OrderPayService::url((string) $recharge->trade_no),
                'expires_at' => P::time((int) $recharge->created_at + \App\Services\Recharge\RechargeService::PENDING_CANCEL_AFTER),
            ]);
        }
        throw new ApiException($tradeNo !== '' ? '找不到这张待付款订单（可能已付款或已取消）。' : '现在没有待付款的订单。');
    }
}
