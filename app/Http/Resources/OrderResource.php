<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'period' => PlanService::getLegacyPeriod((string)$this->period),
            'plan' => $this->whenLoaded('plan', function () {
                if (!$this->plan) return null;
                $plan = clone $this->plan;
                if ($this->plan_snapshot) {
                    $plan->forceFill($this->plan_snapshot['options']);
                    $plan->name = $this->plan_snapshot['name'];
                    $prices = $plan->prices ?? [];
                    $prices[$this->period] = $this->plan_snapshot['amount'] / 100;
                    $plan->prices = $prices;
                    $plan->customization = null;
                }
                return PlanResource::make($plan);
            }),
            'payment' => $this->whenLoaded('payment', fn() => $this->payment ? [
                'id' => $this->payment->id,
                'name' => $this->payment->name,
                'payment' => $this->payment->payment,
                'icon' => $this->payment->icon,
            ] : null),
        ];
    }
}
