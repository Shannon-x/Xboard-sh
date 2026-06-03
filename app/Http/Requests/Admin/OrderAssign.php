<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class OrderAssign extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'plan_id' => 'required|integer',
            'email' => 'required|string|email:strict|max:64',
            // total_amount 单位为"分"（与 createFromRequest 内部一致）。
            // 之前 `numeric|min:0` 允许浮点和 1e18 这种极端值：浮点会让 int 列丢精度；
            // 极端值再传 surplus 折算/佣金计算时会全链路错。
            'total_amount' => 'required|integer|min:0|max:99999999',
            'period' => 'required|in:month_price,quarter_price,half_year_price,year_price,two_year_price,three_year_price,onetime_price,reset_price'
        ];
    }

    public function messages()
    {
        return [
            'plan_id.required' => '订阅不能为空',
            'email.required' => '邮箱不能为空',
            'total_amount.required' => '支付金额不能为空',
            'period.required' => '订阅周期不能为空',
            'period.in' => '订阅周期格式有误'
        ];
    }
}
