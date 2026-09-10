<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    protected PlanService $planService;

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
        $user = $request->user();
        (new PlanService($plan))->validatePurchase($user, $data['period']);
        $quote = app(\App\Services\PlanCustomizationService::class)->quote($plan, $data['period'], $data['options'] ?? null, $user);
        unset($quote['snapshot']);
        return $this->success($quote);
    }

    public function fetch(Request $request)
    {
        $user = User::find($request->user()->id);
        if ($request->input('id')) {
            $plan = Plan::where('id', $request->input('id'))->first();
            if (!$plan) {
                return $this->fail([400, __('Subscription plan does not exist')]);
            }
            if (!$this->planService->isPlanAvailableForUser($plan, $user)) {
                return $this->fail([400, __('Subscription plan does not exist')]);
            }
            if (!$request->boolean('include_customization')) {
                $plan = app(\App\Services\PlanCustomizationService::class)->forLegacyUser($plan, $user);
            }
            return $this->success(PlanResource::make($plan));
        }

        $plans = $this->planService->getAvailablePlans();
        if (!$request->boolean('include_customization')) {
            $plans = $plans->map(fn ($plan) => app(\App\Services\PlanCustomizationService::class)->forLegacyUser($plan, $user));
        }
        return $this->success(PlanResource::collection($plans));
    }
}
