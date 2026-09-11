<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Http\Requests\User\PlanQuote;
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
    public function quote(PlanQuote $request)
    {
        $data = $request->validated();
        $plan = Plan::findOrFail($data['plan_id']);
        $user = $request->user();
        (new PlanService($plan))->validatePurchase($user, $data['period']);
        $quote = app(\App\Services\PlanCustomizationService::class)->quote($plan, $data['period'], $request->planOptions(), $user);
        unset($quote['snapshot']);
        return $this->success($quote);
    }

    public function fetch(Request $request)
    {
        $user = User::find($request->user()->id);
        $customizer = app(\App\Services\PlanCustomizationService::class);
        $forClient = fn ($plan) => $customizer->forClient(
            $plan, $user, $request->boolean('include_customization'), $request->boolean('include_addon_groups')
        );
        if ($request->input('id')) {
            $plan = Plan::where('id', $request->input('id'))->first();
            if (!$plan) {
                return $this->fail([400, __('Subscription plan does not exist')]);
            }
            if (!$this->planService->isPlanAvailableForUser($plan, $user)) {
                return $this->fail([400, __('Subscription plan does not exist')]);
            }
            $plan = $forClient($plan);
            return $this->success(PlanResource::make($plan));
        }

        $plans = $this->planService->getAvailablePlans();
        $plans = $plans->map($forClient);
        return $this->success(PlanResource::collection($plans));
    }
}
