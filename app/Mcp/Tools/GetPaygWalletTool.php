<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\Mcp\McpPresenter as P;
use App\Services\Payg\PaygService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_payg_wallet')]
#[Title('按量钱包')]
#[Description('上网按量用户专用：按量余额（剩余 GB 与折合金额）、每 GB 单价、本月已扣、月度保底、上个月结算、自动补充状态，以及最近的充值 / 保底明细。不是按量套餐时会说明。')]
#[IsReadOnly]
#[IsIdempotent]
class GetPaygWalletTool extends McpTool
{
    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $user = User::findOrFail($user->id);
        $service = app(PaygService::class);
        $status = $service->status($user);
        if ($status === null) {
            return self::result(['is_payg' => false, 'note' => '当前不是上网按量套餐。']);
        }
        $ledger = $service->ledger($user);
        $unit = (float) $status['unit_price'];

        return self::result([
            'is_payg' => true,
            'remaining_gb' => P::gb($status['balance_bytes']),
            'remaining_value_yuan' => P::yuan(round($status['balance_bytes'] / P::BYTES_PER_GB * $unit)),
            'price_per_gb_yuan' => P::yuan($unit),
            'used_this_month_yuan' => P::yuan($status['month_used']),
            'used_this_month_gb' => P::gb($status['month_used_bytes']),
            'monthly_floor_yuan' => P::yuan($status['monthly_floor']),
            'last_month' => [
                'month' => $status['last_month']['month'],
                'used_yuan' => P::yuan($status['last_month']['used']),
                'floor_charged_yuan' => P::yuan($status['last_month']['floor_charged']),
                'settled' => (bool) $status['last_month']['settled'],
            ],
            'auto_topup' => [
                'enabled' => $status['auto_topup']['enabled'] && $status['auto_topup']['site_enabled'],
                'below_yuan' => P::yuan($status['auto_topup']['threshold']),
                'each_time_yuan' => P::yuan($status['auto_topup']['amount']),
                'account_balance_enough' => $status['auto_topup']['balance_enough'],
            ],
            'recent_entries' => array_map(fn (array $e) => [
                'type' => ['recharge' => '充值', 'auto_topup' => '自动补充', 'floor' => '月度保底补扣'][$e['type']] ?? $e['type'],
                'at' => P::time($e['at']),
                'amount_yuan' => P::yuan($e['amount']),
                'gb' => P::gb($e['bytes']),
                'bonus_gb' => P::gb($e['bonus_bytes'] + $e['first_bonus_bytes']),
            ], array_slice($ledger['entries'], 0, 10)),
            'note' => '自动补充开关只能在面板里改。',
        ]);
    }
}
