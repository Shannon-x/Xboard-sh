<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\CommissionWithdrawalResource;
use App\Models\CommissionWithdrawal;
use App\Services\CommissionWithdrawalService;
use App\Services\TicketAttachmentService;
use Illuminate\Http\Request;

class WithdrawController extends Controller
{
    /**
     * 提现配置（链列表 / 上下限 / 汇率 / 是否必须二维码）+ 当前可提现余额 + 是否有待处理申请。
     */
    public function config(Request $request)
    {
        $service = new CommissionWithdrawalService();
        $data = $service->config()->toPublicArray();
        $data['commission_balance'] = (int) $request->user()->commission_balance;
        $data['has_pending'] = CommissionWithdrawal::where('user_id', $request->user()->id)
            ->where('status', CommissionWithdrawal::STATUS_PENDING)
            ->exists();
        // 上次的收款信息：链 / 地址 / 二维码，前端据此预填、可一键沿用二维码
        $data['saved'] = $service->savedProfile($request->user());
        return $this->success($data);
    }

    /**
     * 清除已保存的收款信息（不影响历史申请）。
     */
    public function clearSaved(Request $request)
    {
        (new CommissionWithdrawalService())->clearSavedProfile($request->user());
        return $this->success(true);
    }

    public function fetch(Request $request)
    {
        $list = CommissionWithdrawal::where('user_id', $request->user()->id)
            ->orderBy('id', 'DESC')
            ->limit(100)
            ->get();
        return $this->success(CommissionWithdrawalResource::collection($list));
    }

    public function apply(Request $request)
    {
        $request->validate([
            'amount' => 'required|integer|min:1',
            'chain' => 'required|string|max:32',
            'address' => 'required|string|max:255',
            'attachment_ids' => 'nullable|array|max:10',
            'attachment_ids.*' => 'integer|min:1',
            'reuse_qr' => 'nullable|boolean',
        ], [
            'amount.required' => __('Invalid parameter'),
            'chain.required' => __('The withdrawal method cannot be empty'),
            'address.required' => __('The withdrawal account cannot be empty'),
        ]);

        $service = new CommissionWithdrawalService();
        $chain = $service->config()->findChain((string) $request->input('chain'));
        if (!$chain) {
            return $this->fail([422, __('Unsupported withdrawal method')]);
        }

        $attachmentIds = (new TicketAttachmentService())->validatePendingIds(
            $request->input('attachment_ids', []),
            $request->user()->id
        );

        $withdrawal = $service->apply(
            $request->user(),
            (int) $request->input('amount'),
            $chain,
            (string) $request->input('address'),
            $attachmentIds,
            (bool) $request->boolean('reuse_qr')
        );

        return $this->success(CommissionWithdrawalResource::make($withdrawal));
    }

    public function cancel(Request $request)
    {
        $request->validate(['id' => 'required|integer|min:1']);
        $withdrawal = (new CommissionWithdrawalService())->cancel($request->user(), (int) $request->input('id'));
        return $this->success(CommissionWithdrawalResource::make($withdrawal));
    }
}
