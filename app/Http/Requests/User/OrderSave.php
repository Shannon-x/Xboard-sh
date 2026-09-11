<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class OrderSave extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'plan_id' => 'required',
            'options' => 'sometimes|array:transfer_enable,device_limit,speed_limit,addon_groups',
            'options.transfer_enable' => 'required_with:options|integer|min:1|max:1000000',
            'options.device_limit' => 'present_with:options|nullable|integer|min:0',
            'options.speed_limit' => 'present_with:options|nullable|integer|min:0',
            // 增值节点组：客户勾选的可选组 ID 列表。是否可售由 PlanCustomizationService 按套餐配置复核。
            'options.addon_groups' => 'sometimes|array|max:20',
            'options.addon_groups.*' => 'integer|min:1',
            'expected_amount' => 'sometimes|integer|min:1|max:100000000',
            'period' => 'required|in:month_price,quarter_price,half_year_price,year_price,two_year_price,three_year_price,onetime_price,reset_price,monthly,quarterly,half_yearly,yearly,two_yearly,three_yearly,onetime,reset_traffic'
        ];
    }

    public function messages()
    {
        return [
            'plan_id.required' => __('Plan ID cannot be empty'),
            'period.required' => __('Plan period cannot be empty'),
            'period.in' => __('Wrong plan period')
        ];
    }
}
