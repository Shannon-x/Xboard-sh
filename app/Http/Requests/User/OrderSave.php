<?php

namespace App\Http\Requests\User;

use App\Http\Requests\Concerns\ValidatesPlanOptions;
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
        return [
            'plan_id' => 'required',
            'expected_amount' => 'sometimes|nullable|integer|min:1|max:100000000',
            'period' => 'required|in:month_price,quarter_price,half_year_price,year_price,two_year_price,three_year_price,onetime_price,reset_price,monthly,quarterly,half_yearly,yearly,two_yearly,three_yearly,onetime,reset_traffic,traffic_topup'
        ] + $this->planOptionsRules();
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
