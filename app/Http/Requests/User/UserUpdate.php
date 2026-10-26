<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class UserUpdate extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'remind_expire' => 'in:0,1',
            'remind_traffic' => 'in:0,1',
            // 自动续费开关：到期前余额足够时按上次配置自动续费
            'auto_renew' => 'in:0,1',
            // 上网按量的自动补充：按量余额低于门槛时从账户余额买一份（与自动续费分开）
            'payg_auto_topup' => 'in:0,1',
        ];
    }

    public function messages()
    {
        return [
            'show.in' => __('Incorrect format of expiration reminder'),
            'renew.in' => __('Incorrect traffic alert format')
        ];
    }
}
