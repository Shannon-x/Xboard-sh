<?php

namespace App\Http\Requests\User;

use App\Http\Requests\Concerns\ValidatesPlanOptions;
use App\Models\RechargeOrder;
use Illuminate\Foundation\Http\FormRequest;

class OrderSave extends FormRequest
{
    use ValidatesPlanOptions;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        // 余额充值和套餐下单共用 /user/order/save（wyx2685/v2board 前端约定：plan_id=0、period=deposit、
        // deposit_amount 单位分）。金额上下限是站点设置，在 RechargeService 里校验。
        if ($this->isDeposit()) {
            return [
                'plan_id' => 'required|in:0',
                'period' => 'sometimes|in:' . RechargeOrder::LEGACY_PERIOD,
                'deposit_amount' => 'required|integer|min:1|max:100000000',
            ];
        }

        return [
            'plan_id' => 'required',
            'expected_amount' => 'sometimes|nullable|integer|min:1|max:100000000',
            'confirm_repeat' => 'sometimes|boolean',
            'period' => 'required|in:month_price,quarter_price,half_year_price,year_price,two_year_price,three_year_price,onetime_price,reset_price,monthly,quarterly,half_yearly,yearly,two_yearly,three_yearly,onetime,reset_traffic,traffic_topup'
        ] + $this->planOptionsRules();
    }

    /** 插件时代只按 plan_id=0 分流，个别老前端不带 period，这里两种写法都认 */
    public function isDeposit(): bool
    {
        return $this->input('period') === RechargeOrder::LEGACY_PERIOD
            || ((string) $this->input('plan_id') === (string) RechargeOrder::LEGACY_PLAN_ID && $this->has('deposit_amount'));
    }

    public function messages()
    {
        return [
            'deposit_amount.required' => __('Please enter the recharge amount'),
            'deposit_amount.integer' => __('Please enter the recharge amount'),
            'deposit_amount.min' => __('Please enter the recharge amount'),
            'plan_id.required' => __('Plan ID cannot be empty'),
            'period.required' => __('Plan period cannot be empty'),
            'period.in' => __('Wrong plan period')
        ];
    }
}
