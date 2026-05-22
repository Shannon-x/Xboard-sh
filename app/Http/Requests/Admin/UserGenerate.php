<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UserGenerate extends FormRequest
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
            'expired_at' => 'nullable|integer',
            'plan_id' => 'nullable|integer',
            'email_prefix' => 'nullable|string|max:64',
            'email_suffix' => 'required|string|max:64',
            'password' => 'nullable|string|min:8|max:64'
        ];
    }

    public function messages()
    {
        return [
            'generate_count.integer' => '生成数量必须为数字',
            'generate_count.max' => '生成数量最大为500个'
        ];
    }
}
