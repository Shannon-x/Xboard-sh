<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\BillingDocument;
use App\Services\Billing\BillingPayService;
use Illuminate\Http\Request;

/**
 * 续费账单的免登录付款（/pay/<凭据> 页面的接口）。凭据放在请求体里，不进访问日志；
 * 签名不对一律 404，和「账单不存在」分不开。能不能付、付到哪一步由 BillingPayService 按账单现状判断。
 */
class BillingPayController extends Controller
{
    public function fetch(Request $request, BillingPayService $pay)
    {
        return $this->success($pay->view($this->doc($request, $pay)));
    }

    public function check(Request $request, BillingPayService $pay)
    {
        return $this->success($pay->check($this->doc($request, $pay)));
    }

    /** 与 /user/order/checkout 同样的响应体（type / data），多带一个 trade_no */
    public function checkout(Request $request, BillingPayService $pay)
    {
        $request->validate([
            'method' => 'nullable|integer',
            'expected_amount' => 'nullable|integer|min:0',
        ]);
        $doc = $this->doc($request, $pay);
        $method = $request->input('method');
        $expected = $request->input('expected_amount');
        return response($pay->checkout($doc, $method !== null ? (int) $method : null, $expected !== null ? (int) $expected : null));
    }

    public function cancel(Request $request, BillingPayService $pay)
    {
        if (!$pay->cancel($this->doc($request, $pay))) {
            return $this->fail([400, __('Cancel failed')]);
        }
        return $this->success(true);
    }

    private function doc(Request $request, BillingPayService $pay): BillingDocument
    {
        $request->validate(['token' => 'required|string|max:64']);
        $doc = $pay->resolve((string) $request->input('token'));
        if (!$doc) {
            abort(404);
        }
        return $doc;
    }
}
