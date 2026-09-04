<?php

namespace App\Http\Resources;

use App\Models\CommissionWithdrawal;
use App\Services\Commission\WithdrawalConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 用户端可见的提现记录。管理员备注 remark 不下发。
 */
class CommissionWithdrawalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CommissionWithdrawal $w */
        $w = $this->resource;
        $explorer = null;
        if ($w->txid) {
            $chain = WithdrawalConfig::fromSettings()->findChain($w->chain_code);
            if ($chain && $chain['explorer_tx'] !== '') {
                $explorer = str_replace('{txid}', rawurlencode($w->txid), $chain['explorer_tx']);
            }
        }

        return [
            'id' => $w->id,
            'ticket_id' => $w->ticket_id,
            'amount' => (int) $w->amount,
            'currency' => $w->currency,
            'chain_code' => $w->chain_code,
            'chain_name' => $w->chain_name,
            'network' => $w->network,
            'address' => $w->address,
            'usdt_rate' => $w->usdt_rate,
            'usdt_fee' => $w->usdt_fee,
            'usdt_amount' => $w->usdt_amount,
            'paid_usdt' => $w->paid_usdt,
            'settle_rate' => $w->settle_rate,
            'status' => (int) $w->status,
            'txid' => $w->txid,
            'explorer_url' => $explorer,
            'reject_reason' => $w->reject_reason,
            'settled_at' => $w->settled_at,
            'created_at' => $w->created_at,
        ];
    }
}
