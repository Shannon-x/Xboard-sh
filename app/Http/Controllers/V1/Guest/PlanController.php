<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Http\Requests\User\PlanQuote;
use App\Models\Plan;
use App\Services\PlanService;
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
        unset($quote['snapshot']);
        return $this->success($quote);
    }

    public function fetch(Request $request)
    {
        $customizer = app(\App\Services\PlanCustomizationService::class);
        $plan = $this->planService->getAvailablePlans()->map(fn ($plan) => $customizer->forClient(
            $plan, null, true, $request->boolean('include_addon_groups')
        ));
        return $this->success(PlanResource::collection($plan));
    }
}
