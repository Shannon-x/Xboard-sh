<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class OrderUpdate extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            // 与 Order::$statusMap 对齐：0 待支付 / 1 开通中 / 2 已取消 / 3 已完成 / 4 已折抵
            'status' => 'in:0,1,2,3,4',
            // 与 CheckCommission 写入值对齐：0 未开始 / 1 待发放 / 2 已发放 / 3 已终止
            // 缺值 2 的话，admin 无法看到/标记"已发放"，并且把 status 改回 1 会让 CheckCommission 重复发佣。
            'commission_status' => 'in:0,1,2,3'
        ];
    }

    public function messages()
    {
        return [
            'status.in' => '销售状态格式不正确',
            'commission_status.in' => '佣金状态格式不正确'
        ];
    }
}
