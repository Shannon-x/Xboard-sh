<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\CouponResource;
use App\Services\CouponService;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    public function check(Request $request)
    {
        // 只认字符串：JSON 里的数字 / 数组原样进 where 时，MySQL 会按数字比较 varchar 列
        $code = $request->input('code');
        if (!is_string($code) || trim($code) === '') {
            return $this->fail([422, __('Coupon cannot be empty')]);
        }
        $couponService = new CouponService($code);
        $couponService->setPlanId($request->input('plan_id'));
        $couponService->setUserId($request->user()->id);
        $couponService->setPeriod($request->input('period'));
        $couponService->check();
        return $this->success(CouponResource::make($couponService->getCoupon()));
    }
}
