<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class KnowledgeSave extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'category' => 'required|string|max:128',
            'language' => 'required|string|max:16',
            'title' => 'required|string|max:200',
            // 知识库正文允许较长，10w 字够任何 markdown
            'body' => 'required|string|max:100000',
            'show' => 'nullable|boolean',
        ];
    }

    public function messages()
    {
        return [
            'title.required' => '标题不能为空',
            'category.required' => '分类不能为空',
            'body.required' => '内容不能为空',
            'language.required' => '语言不能为空',
            'show.boolean' => '显示状态必须为布尔值'
        ];
    }
}
