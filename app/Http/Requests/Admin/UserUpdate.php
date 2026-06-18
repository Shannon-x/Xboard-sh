<?php

namespace App\Http\Requests\Admin;

use App\Services\Plugin\HookManager;
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
        // 单位与上限说明（与 UserController.update 实际处理逻辑严格对齐 —— 不能凭注释揣测）：
        //
        //   transfer_enable / u / d 单位为 **byte**（admin 前端在表单层已经把 GB 乘 1073741824 提交）。
        //     上限给到 9 EiB（MySQL bigint 上限的 2^62），覆盖任何合法套餐而不至于挡住 100GB 这种正常值。
        //     这里只是兜底防御"被钓的 admin token 一次写 1e18 让 bigint 溢出"的极端场景。
        //     之前误写成 1048576 是把 GB 当作单位 → 100GB 提交后直接被 422 挡死，这是回归 bug，已修。
        //
        //   balance / commission_balance：admin 前端按"元"传，UserController.update 会 *100 换算到"分"。
        //     上限 1,000,000 元（与历史前端实际能填的范围一致）。
        //
        //   expired_at 上限 9999999999 ≈ 2286 年；下限 0（清空到期）。
        $maxBigint = 4611686018427387904; // 2^62，安全的 bigint 上限
        $rules = [
            'id' => 'required|integer',
            'email' => 'nullable|string|email:strict|max:64',
            'password' => 'nullable|string|min:8|max:64',
            'transfer_enable' => "numeric|min:0|max:{$maxBigint}",
            'expired_at' => 'nullable|integer|min:0|max:9999999999',
            'banned' => 'bool',
            'plan_id' => 'nullable|integer',
            'commission_rate' => 'nullable|integer|min:0|max:100',
            'discount' => 'nullable|integer|min:0|max:100',
            'is_admin' => 'boolean',
            'is_staff' => 'boolean',
            'u' => "integer|min:0|max:{$maxBigint}",
            'd' => "integer|min:0|max:{$maxBigint}",
            'balance' => 'numeric|min:0|max:1000000',
            'commission_type' => 'integer|in:0,1,2',
            'commission_balance' => 'numeric|min:0|max:1000000',
            'remarks' => 'nullable|string|max:1024',
            'speed_limit' => 'nullable|integer|min:0|max:10000000',
            'device_limit' => 'nullable|integer|min:0|max:10000'
        ];

        return HookManager::filter('admin.user.update.rules', $rules, $this);
    }

    public function messages()
    {
        $messages = [
            'email.required' => '邮箱不能为空',
            'email.email' => '邮箱格式不正确',
            'transfer_enable.numeric' => '流量格式不正确',
            'expired_at.integer' => '到期时间格式不正确',
            'banned.in' => '是否封禁格式不正确',
            'is_admin.required' => '是否管理员不能为空',
            'is_admin.in' => '是否管理员格式不正确',
            'is_staff.required' => '是否员工不能为空',
            'is_staff.in' => '是否员工格式不正确',
            'plan_id.integer' => '订阅计划格式不正确',
            'commission_rate.integer' => '推荐返利比例格式不正确',
            'commission_rate.nullable' => '推荐返利比例格式不正确',
            'commission_rate.min' => '推荐返利比例最小为0',
            'commission_rate.max' => '推荐返利比例最大为100',
            'discount.integer' => '专属折扣比例格式不正确',
            'discount.nullable' => '专属折扣比例格式不正确',
            'discount.min' => '专属折扣比例最小为0',
            'discount.max' => '专属折扣比例最大为100',
            'u.integer' => '上行流量格式不正确',
            'd.integer' => '下行流量格式不正确',
            'balance.integer' => '余额格式不正确',
            'commission_balance.integer' => '佣金格式不正确',
            'password.min' => '密码长度最小8位',
            'speed_limit.integer' => '限速格式不正确',
            'device_limit.integer' => '设备数量格式不正确'
        ];

        return HookManager::filter('admin.user.update.messages', $messages, $this);
    }
}
