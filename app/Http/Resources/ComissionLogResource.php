<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ComissionLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            "id"=> $this['id'],
            "order_amount" => $this['order_amount'],
            "trade_no" => $this['trade_no'],
            "get_amount" => $this['get_amount'],
            "created_at" => $this['created_at'],
            // additive：买家脱敏邮箱，由 InviteController::details 批量注入；
            // 未注入时为 null，老前端忽略该字段
            "email_masked" => $this['email_masked'] ?? null
        ];
    }
}
