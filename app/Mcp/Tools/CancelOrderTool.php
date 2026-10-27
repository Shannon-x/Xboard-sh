<?php

namespace App\Mcp\Tools;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\RechargeOrder;
use App\Models\User;
use App\Services\Mcp\McpPresenter as P;
use App\Services\OrderService;
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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('cancel_order')]
#[Title('取消订单')]
#[Description('取消一张待付款的订单或充值单。下单时自动抵扣的账户余额会退回。只在客户明确说不要这一单时调用。')]
#[IsReadOnly(false)]
#[IsDestructive]
#[IsIdempotent]
class CancelOrderTool extends McpTool
{
    protected bool $ordering = true;

    public function schema(JsonSchema $schema): array
    {
        return [
            'trade_no' => $schema->string()->description('要取消的订单号。')->required(),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $tradeNo = trim((string) $request->get('trade_no'));
        $this->auditRef = $tradeNo !== '' ? $tradeNo : null;
        $order = Order::where('user_id', $user->id)->where('trade_no', $tradeNo)->first();
        if ($order) {
            if ((int) $order->status !== Order::STATUS_PENDING) {
                throw new ApiException('只能取消待付款的订单，这一单现在是「' . P::orderStatus((int) $order->status) . '」。');
            }
            $refund = (int) ($order->balance_amount ?? 0);
            if (!(new OrderService($order))->cancel()) {
                throw new ApiException('取消失败，请稍后再试。');
            }
            return self::result(['trade_no' => $tradeNo, 'cancelled' => true, 'balance_refunded_yuan' => P::yuan($refund)]);
        }
        $recharge = RechargeOrder::where('user_id', $user->id)->where('trade_no', $tradeNo)->first();
        if ($recharge && (int) $recharge->status === RechargeOrder::STATUS_PENDING && app(RechargeService::class)->cancel($recharge)) {
            return self::result(['trade_no' => $tradeNo, 'cancelled' => true, 'balance_refunded_yuan' => '0.00']);
        }
        throw new ApiException('找不到这张待付款订单（可能已付款或已取消）。');
    }
}
