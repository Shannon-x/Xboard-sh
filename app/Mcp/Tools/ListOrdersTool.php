<?php

namespace App\Mcp\Tools;

use App\Models\Order;
use App\Models\Plan;
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

#[Name('list_orders')]
#[Title('订单记录')]
#[Description('查看最近的订单（新购 / 续费 / 换套餐 / 流量加购 / 余额充值）：订单号、套餐、周期、金额、状态、时间。待付款的订单附带付款链接。')]
#[IsReadOnly]
#[IsIdempotent]
class ListOrdersTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->description('最多返回几条，1–20，默认 10。')->min(1)->max(20),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $limit = max(1, min(20, (int) ($request->get('limit') ?? 10)));
        $orders = Order::where('user_id', $user->id)->orderByDesc('created_at')->limit($limit)->get();
        $plans = Plan::whereIn('id', $orders->pluck('plan_id')->filter()->unique())->pluck('name', 'id');
        $items = $orders->map(fn (Order $o) => [
            'trade_no' => (string) $o->trade_no,
            'kind' => P::orderType((int) $o->type),
            'plan_name' => (string) ($plans[$o->plan_id] ?? ''),
            'period' => P::periodName((string) $o->period),
            'cash_amount_yuan' => P::yuan($o->total_amount),
            'balance_used_yuan' => P::yuan($o->balance_amount),
            'status' => P::orderStatus((int) $o->status),
            'created_at' => P::time((int) $o->created_at),
            'payment_url' => (int) $o->status === Order::STATUS_PENDING ? OrderPayService::url((string) $o->trade_no) : null,
            'created_at_ts' => (int) $o->created_at,
        ]);
        $recharges = RechargeOrder::where('user_id', $user->id)->orderByDesc('created_at')->limit($limit)->get()
            ->map(fn (RechargeOrder $r) => [
                'trade_no' => (string) $r->trade_no,
                'kind' => '余额充值',
                'plan_name' => '',
                'period' => '',
                'cash_amount_yuan' => P::yuan($r->pay_amount),
                'balance_used_yuan' => '0.00',
                'credit_yuan' => P::yuan($r->total_credit),
                'status' => match ((int) $r->status) {
                    RechargeOrder::STATUS_PENDING => '待付款',
                    RechargeOrder::STATUS_COMPLETED => '已完成',
                    default => '已取消',
                },
                'created_at' => P::time((int) $r->created_at),
                'payment_url' => (int) $r->status === RechargeOrder::STATUS_PENDING ? OrderPayService::url((string) $r->trade_no) : null,
                'created_at_ts' => (int) $r->created_at,
            ]);
        $all = $items->concat($recharges)->sortByDesc('created_at_ts')->take($limit)
            ->map(function (array $row) {
                unset($row['created_at_ts']);
                return $row;
            })->values()->all();

        return self::result(['orders' => $all]);
    }
}
