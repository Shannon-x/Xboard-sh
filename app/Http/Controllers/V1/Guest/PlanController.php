<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
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
    public function quote(Request $request)
    {
        $data = $request->validate([
            'plan_id' => 'required|integer',
            'period' => 'required|string',
            'options' => 'sometimes|array:transfer_enable,device_limit,speed_limit',
        ]);
        $plan = Plan::findOrFail($data['plan_id']);
        $user = null;
        if (!$plan->show || !$plan->sell || !(new PlanService($plan))->hasCapacity($plan)) {
            throw new \App\Exceptions\ApiException('此套餐暂不可购买');
        }
        $quote = app(\App\Services\PlanCustomizationService::class)->quote($plan, $data['period'], $data['options'] ?? null, $user);
        unset($quote['snapshot']);
        return $this->success($quote);
    }

    public function fetch(Request $request)
    {
        $plan = $this->planService->getAvailablePlans();
        return $this->success(PlanResource::collection($plan));
    }
}
