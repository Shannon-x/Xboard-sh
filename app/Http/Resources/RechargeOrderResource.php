<?php

namespace App\Http\Resources;

use App\Models\RechargeOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 充值单在用户端「订单」接口（/user/order/fetch|detail）里的样子。
 *
 * 前端把充值单和套餐订单放在同一个订单列表 / 收银台里，按 wyx2685/v2board 的约定识别它：
 * plan_id = 0、period = deposit，赠送额 bounus（原文拼写）、到账额 get_amount。
 * 订单才有的字段（折扣、折抵、余额抵扣……）补 0，前端不必判空。
 *
 * @mixin RechargeOrder
 */
class RechargeOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'trade_no' => (string) $this->trade_no,
            'is_recharge' => true,
            'plan_id' => RechargeOrder::LEGACY_PLAN_ID,
            'plan' => ['id' => RechargeOrder::LEGACY_PLAN_ID, 'name' => __('Balance recharge')],
            'period' => RechargeOrder::LEGACY_PERIOD,
            'type' => RechargeOrder::LEGACY_ORDER_TYPE,
            'status' => (int) $this->status,
            'total_amount' => (int) $this->deposit_amount,
            'handling_amount' => $this->handling_amount === null ? null : (int) $this->handling_amount,
            'pay_amount' => (int) $this->pay_amount,
            'bonus_amount' => (int) $this->bonus_amount,
            'total_credit' => (int) $this->total_credit,
            'bounus' => (int) $this->bonus_amount,
            'get_amount' => (int) $this->total_credit,
            'balance_amount' => 0,
            'discount_amount' => 0,
            'surplus_amount' => 0,
            'refund_amount' => 0,
            'coupon_id' => null,
            'payment_id' => $this->payment_id === null ? null : (int) $this->payment_id,
            'payment' => $this->whenLoaded('payment', fn () => $this->payment ? [
                'id' => $this->payment->id,
                'name' => $this->payment->name,
                'payment' => $this->payment->payment,
                'icon' => $this->payment->icon,
            ] : null),
            'commission_balance' => 0,
            'commission_status' => null,
            'callback_no' => $this->callback_no,
            'paid_at' => $this->paid_at === null ? null : (int) $this->paid_at,
            'created_at' => (int) $this->created_at,
            'updated_at' => (int) $this->updated_at,
        ];
    }
}
