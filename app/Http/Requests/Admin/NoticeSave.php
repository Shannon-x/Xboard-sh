<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class NoticeSave extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        // 限制类型与长度，原 `required` 允许数组/超长串落库
        return [
            'title' => 'required|string|max:200',
            'content' => 'required|string|max:65535',
            'img_url' => 'nullable|url|max:500',
            'tags' => 'nullable|array|max:20',
            'tags.*' => 'string|max:32',
            // 与 Notice 模型实际可写字段对齐，避免 $request->only 漏掉前端能传的开关
            'popup' => 'nullable|in:0,1',
            'show' => 'nullable|in:0,1',
        ];
    }

    public function messages()
    {
        return [
            'title.required' => '标题不能为空',
            'content.required' => '内容不能为空',
            'img_url.url' => '图片URL格式不正确',
            'tags.array' => '标签格式不正确'
        ];
    }
}
