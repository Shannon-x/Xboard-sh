<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Http\Requests\User\PlanQuote;
use App\Models\Plan;
use App\Services\Payg\PaygConfig;
use App\Services\Payg\PaygService;
use App\Services\PlanCapacity;
use App\Services\PlanService;
use App\Services\RestockNotifier;
use Auth;
use Illuminate\Http\Request;

class PlanController extends Controller
{

    protected $planService;
    public function __construct(PlanService $planService)
    {
        $this->planService = $planService;
    }
    public function quote(PlanQuote $request)
    {
        $data = $request->validated();
        $plan = Plan::findOrFail($data['plan_id']);
        $user = null;
        if (!$plan->show || !$plan->sell || !(new PlanService($plan))->hasCapacity($plan)) {
            throw new \App\Exceptions\ApiException('此套餐暂不可购买');
        }
        $quote = app(\App\Services\PlanCustomizationService::class)->quote($plan, $data['period'], $request->planOptions(), $user);
        if (PaygConfig::fromSettings()->isPaygPlanId($plan->id) && PlanService::getPeriodKey($data['period']) === Plan::PERIOD_ONETIME) {
            $quote['payg_bonus'] = app(PaygService::class)->quoteBonus($plan, (int) $quote['amount'], null);
        }
        unset($quote['snapshot']);
        return $this->success($quote);
    }

    public function fetch(Request $request)
    {
        $customizer = app(\App\Services\PlanCustomizationService::class);
        // include_sold_out=1（新套餐页）：满员的也列出来，带 stock=sold_out；不带参数与原来相同
        $plans = $this->planService->getAvailablePlans($request->boolean('include_sold_out'));
        $request->attributes->set(PlanResource::STOCK_ATTRIBUTE, PlanCapacity::remaining($plans));
        $plan = $plans->map(fn ($plan) => $customizer->forClient(
            $plan, null, true, $request->boolean('include_addon_groups')
        ));
        return $this->success(PlanResource::collection($plan));
    }

    /**
     * 游客的到货提醒：留邮箱。不论邮箱有没有注册过，回应都一样。
     * 新端点，已登记进 sufe-middleware-rs 的路径表。
     */
    public function restockNotify(Request $request)
    {
        $data = $request->validate([
            'plan_id' => 'required|integer|min:1',
            'email' => 'required|email:strict|max:64',
        ]);
        $plan = Plan::find($data['plan_id']);
        if (!$plan) {
            return $this->fail([400, __('Subscription plan does not exist')]);
        }
        app(RestockNotifier::class)->subscribe($plan, (string) $data['email']);
        return $this->success(['subscribed' => true]);
    }
}
