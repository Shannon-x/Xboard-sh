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
            // 先经 /ticket/attachment/upload 拿到的待绑定附件 id；归属与数量在 TicketAttachmentService 里校验
            'attachment_ids' => 'nullable|array|max:10',
            'attachment_ids.*' => 'integer|min:1',
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
