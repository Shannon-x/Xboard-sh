<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class TicketSave extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            // 之前 `required` 允许数组/超长串/控制字符。这里收敛为字符串+长度上限
            'subject' => 'required|string|max:200',
            'level' => 'required|in:0,1,2',
            'message' => 'required|string|max:10000',
        ];
    }

    public function messages()
    {
        return [
            'subject.required' => __('Ticket subject cannot be empty'),
            'level.required' => __('Ticket level cannot be empty'),
            'level.in' => __('Incorrect ticket level format'),
            'message.required' => __('Message cannot be empty')
        ];
    }
}
