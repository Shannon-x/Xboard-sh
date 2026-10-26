<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Http\Requests\User\PlanQuote;
use App\Models\Plan;
use App\Models\User;
use App\Services\Payg\PaygConfig;
use App\Services\Payg\PaygService;
use App\Services\PlanCapacity;
use App\Services\PlanService;
use App\Services\RestockNotifier;
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
        // 登录用户附带下单预演（续费 / 变更、折抵额、折抵后应付）。纯新增键：旧前端不读，游客路径没有。
        if ($user) {
            $quote['order'] = \App\Services\OrderService::previewOrderType($user, $plan, $data['period'], $quote);
        }
        // 上网按量：这一单开通时会送多少（档位 + 首充），纯新增键
        if (PaygConfig::fromSettings()->isPaygPlanId($plan->id) && PlanService::getPeriodKey($data['period']) === Plan::PERIOD_ONETIME) {
            $quote['payg_bonus'] = app(PaygService::class)->quoteBonus($plan, (int) $quote['amount'], $user);
        }
        unset($quote['snapshot']);
        return $this->success($quote);
    }

    /**
     * 售罄套餐的到货提醒：用账户邮箱登记；cancel=1 取消。只有在售、此刻满员的套餐能登记。
     * 新端点，已登记进 sufe-middleware-rs 的路径表。
     */
    public function restockNotify(Request $request)
    {
        $data = $request->validate([
            'plan_id' => 'required|integer|min:1',
            'cancel' => 'nullable|in:0,1',
        ]);
        $plan = Plan::find($data['plan_id']);
        if (!$plan) {
            return $this->fail([400, __('Subscription plan does not exist')]);
        }
        $user = User::findOrFail($request->user()->id);
        $notifier = app(RestockNotifier::class);
        if ((int) ($data['cancel'] ?? 0) === 1) {
            $notifier->cancel($plan, (string) $user->email);
            return $this->success(['subscribed' => false]);
        }
        $notifier->subscribe($plan, (string) $user->email, $user);
        return $this->success(['subscribed' => true]);
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
            $request->attributes->set(PlanResource::STOCK_ATTRIBUTE, PlanCapacity::remaining([$plan]));
            $request->attributes->set(PlanResource::RESTOCK_ATTRIBUTE, RestockNotifier::pendingPlanIds($user));
            $plan = $forClient($plan);
            return $this->success(PlanResource::make($plan));
        }

        // include_sold_out=1（新套餐页）：满员的也列出来，带 stock=sold_out；不带参数与原来相同
        $plans = $this->planService->getAvailablePlans($request->boolean('include_sold_out'));
        $request->attributes->set(PlanResource::STOCK_ATTRIBUTE, PlanCapacity::remaining($plans));
        $request->attributes->set(PlanResource::RESTOCK_ATTRIBUTE, RestockNotifier::pendingPlanIds($user));
        $plans = $plans->map($forClient);
        return $this->success(PlanResource::collection($plans));
    }
}
