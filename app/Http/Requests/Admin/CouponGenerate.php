<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class CouponGenerate extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'generate_count' => 'nullable|integer|max:500',
            'name' => 'required|string|max:255',
            'type' => 'required|in:1,2',
            'value' => 'required|integer|min:1',
            'started_at' => 'required|integer',
            'ended_at' => 'required|integer',
            'limit_use' => 'nullable|integer|min:0',
            'limit_use_with_user' => 'nullable|integer|min:0',
            'limit_plan_ids' => 'nullable|array',
            'limit_period' => 'nullable|array',
            'code' => 'nullable|string|max:255'
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ((int) $this->input('type') === 2 && (int) $this->input('value') > 100) {
                $validator->errors()->add('value', '比例优惠不能超过 100');
            }
        });
    }

    public function messages()
    {
        return [
            'generate_count.integer' => '生成数量必须为数字',
            'generate_count.max' => '生成数量最大为500个',
            'name.required' => '名称不能为空',
            'type.required' => '类型不能为空',
            'type.in' => '类型格式有误',
            'value.required' => '金额或比例不能为空',
            'value.integer' => '金额或比例格式有误',
            'value.min' => '金额或比例必须大于0',
            'started_at.required' => '开始时间不能为空',
            'started_at.integer' => '开始时间格式有误',
            'ended_at.required' => '结束时间不能为空',
            'ended_at.integer' => '结束时间格式有误',
            'limit_use.integer' => '最大使用次数格式有误',
            'limit_use_with_user.integer' => '限制用户使用次数格式有误',
            'limit_plan_ids.array' => '指定订阅格式有误',
            'limit_period.array' => '指定周期格式有误'
        ];
    }
}
