<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\Mcp\OrderPayService;
use Illuminate\Http\Request;

/**
 * 订单的免登录付款（/pay/o-<订单号>-<签名> 页面的接口），AI 助手下单后交给客户的链接落在这里。
 * 与续费账单的 BillingPayController 同一套做法：凭据在请求体里、签名不对一律 404，
 * 能不能付按订单现状每次重算，付款走面板收银台同一个核心。
 */
class OrderPayController extends Controller
{
    public function fetch(Request $request, OrderPayService $pay)
    {
        return $this->success($pay->view($this->target($request, $pay)));
    }

    public function check(Request $request, OrderPayService $pay)
    {
        return $this->success($pay->check($this->target($request, $pay)));
    }

    /** 与 /user/order/checkout 同样的响应体（type / data），多带一个 trade_no */
    public function checkout(Request $request, OrderPayService $pay)
    {
        $request->validate(['method' => 'nullable|integer']);
        $target = $this->target($request, $pay);
        $method = $request->input('method');
        return response($pay->checkout($target, $method !== null ? (int) $method : null));
    }

    public function cancel(Request $request, OrderPayService $pay)
    {
        if (!$pay->cancel($this->target($request, $pay))) {
            return $this->fail([400, __('Cancel failed')]);
        }
        return $this->success(true);
    }

    private function target(Request $request, OrderPayService $pay): array
    {
        $request->validate(['token' => 'required|string|max:96']);
        $target = $pay->resolve((string) $request->input('token'));
        if (!$target) {
            abort(404);
        }
        return $target;
    }
}
