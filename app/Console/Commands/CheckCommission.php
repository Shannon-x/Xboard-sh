<?php

namespace App\Console\Commands;

use App\Models\CommissionLog;
use Illuminate\Console\Command;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CheckCommission extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:commission';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '返佣服务';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->autoCheck();
        $this->autoPayCommission();
    }

    public function autoCheck()
    {
        if ((int)admin_setting('commission_auto_check_enable', 1)) {
            Order::where('commission_status', 0)
                ->where('invite_user_id', '!=', NULL)
                ->where('status', 3)
                ->where('updated_at', '<=', strtotime('-3 day', time()))
                ->update([
                    'commission_status' => 1
                ]);
        }
    }

    public function autoPayCommission()
    {
        $orders = Order::where('commission_status', 1)
            ->where('invite_user_id', '!=', NULL)
            ->select('id')
            ->get();
        foreach ($orders as $order) {
            try{
                DB::transaction(function () use ($order) {
                    $lockedOrder = Order::where('id', $order->id)
                        ->lockForUpdate()
                        ->first();

                    if (
                        !$lockedOrder
                        || (int) $lockedOrder->commission_status !== 1
                        || !$lockedOrder->invite_user_id
                    ) {
                        return;
                    }

                    if (!$this->payHandle($lockedOrder->invite_user_id, $lockedOrder)) {
                        throw new \RuntimeException('Failed to pay commission');
                    }

                    $lockedOrder->commission_status = 2;
                    if (!$lockedOrder->save()) {
                        throw new \RuntimeException('Failed to save commission status');
                    }
                });
            } catch (\Exception $e){
                throw $e;
            }
        }
    }

    public function payHandle($inviteUserId, Order $order)
    {
        $commissionShareLevels = $this->getCommissionShareLevels();
        for ($l = 0; $l < 3; $l++) {
            $inviter = User::where('id', $inviteUserId)
                ->lockForUpdate()
                ->first();
            if (!$inviter) continue;
            if (!isset($commissionShareLevels[$l])) continue;
            $commissionBalance = (int) floor($order->commission_balance * ($commissionShareLevels[$l] / 100));
            if (!$commissionBalance) continue;
            if ((int)admin_setting('withdraw_close_enable', 0)) {
                $inviter->balance = (int) ($inviter->balance ?? 0) + $commissionBalance;
            } else {
                $inviter->commission_balance = (int) ($inviter->commission_balance ?? 0) + $commissionBalance;
            }
            if (!$inviter->save()) {
                return false;
            }
            CommissionLog::create([
                'invite_user_id' => $inviteUserId,
                'user_id' => $order->user_id,
                'trade_no' => $order->trade_no,
                'order_amount' => $order->total_amount,
                'get_amount' => $commissionBalance
            ]);
            $inviteUserId = $inviter->invite_user_id;
            // update order actual commission balance
            $order->actual_commission_balance = (int) ($order->actual_commission_balance ?? 0) + $commissionBalance;
        }
        return true;
    }

    private function getCommissionShareLevels(): array
    {
        if (!(int)admin_setting('commission_distribution_enable', 0)) {
            return [0 => 100];
        }

        $remaining = 100;
        $levels = [];
        foreach (['commission_distribution_l1', 'commission_distribution_l2', 'commission_distribution_l3'] as $index => $key) {
            $share = max(0, min(100, (int)admin_setting($key, 0)));
            $share = min($share, $remaining);
            if ($share > 0) {
                $levels[$index] = $share;
            }
            $remaining -= $share;
            if ($remaining <= 0) {
                break;
            }
        }

        return $levels;
    }

}
