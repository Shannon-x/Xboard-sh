<?php

namespace App\Console\Commands;

use App\Models\CommissionLog;
use App\Services\Plugin\HookManager;
use Illuminate\Console\Command;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        // 改 chunkById：原 ->get() 在待结算订单量大时直接拉全量 id 到内存
        // 同时把"单笔异常即 re-throw 终止整轮"改为"per-order 捕获 + 日志"，单笔失败不再影响后续订单结算
        Order::where('commission_status', 1)
            ->where('invite_user_id', '!=', NULL)
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($orders) {
                foreach ($orders as $order) {
                    try {
                        DB::transaction(function () use ($order) {
                            $lockedOrder = Order::where('id', $order->id)
                                ->lockForUpdate()
                                ->first();

                            // 多层防御：除了 commission_status=1 之外，必须额外校验
                            //   ① order.status = STATUS_COMPLETED（已支付完成的订单才发佣）
                            //   ② invite_user_id 非空
                            //   ③ commission_balance > 0（订单本身有佣金额度可分；防御 admin 把 status
                            //      改回 1 但 commission_balance=0/负数 时仍然走 payHandle）
                            //
                            // 历史实现只信 commission_status==1：admin 误操作 / 后台权限被滥用 / 数据回填
                            // 都能反复触发佣金发放。CommissionLog 已经有 uniq_commission_trade_inviter
                            // 唯一索引兜底（2026_05_29 迁移），但唯一索引只挡同 (trade_no, invite_user_id)
                            // 重复发，不挡"该订单本来就不该发佣"的情况。
                            // 非法 / 不该发佣：跳过且不改状态
                            if (
                                !$lockedOrder
                                || (int) $lockedOrder->commission_status !== 1
                                || (int) $lockedOrder->status !== Order::STATUS_COMPLETED
                                || !$lockedOrder->invite_user_id
                            ) {
                                return;
                            }

                            // 零佣订单（commission_rate=0 的用户、或被余额/折扣抵成 0 元仍保留邀请关系的单）：
                            // 无佣可发，直接推进到终态，否则会永久卡在 commission_status=1 被每分钟反复扫描、
                            // 随零佣订单累积而拖慢结算。commission_balance=0 的单不进 admin is_commission 视图，
                            // 置 2 对后台统计透明。
                            if ((int) ($lockedOrder->commission_balance ?? 0) <= 0) {
                                $lockedOrder->commission_status = 2;
                                $lockedOrder->save();
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
                    } catch (\Throwable $e) {
                        // 单笔失败不应阻断后续订单。常见原因：inviter 被删 / lockForUpdate 超时 /
                        // CommissionLog uniq_commission_trade_inviter 冲突（已结算过的订单重新被置回 status=1）。
                        // 这里只记日志、继续；运营可基于 commission_status=1 且 updated_at 长期不变筛出滞留单。
                        Log::error('commission pay failed', [
                            'order_id' => $order->id,
                            'msg' => $e->getMessage(),
                        ]);
                        continue;
                    }
                }
            });
    }

    public function payHandle($inviteUserId, Order $order)
    {
        $commissionShareLevels = $this->getCommissionShareLevels();
        // 防环：链上任何 inviter 第二次出现都立即停（A→B→A 这种循环邀请，或 admin 误把
        // user 自己设成 inviter 自指）。inviter == 下单人本人也立刻停（自邀订单）。
        $visited = [];
        for ($l = 0; $l < 3 && $inviteUserId; $l++) {
            if (isset($visited[$inviteUserId]) || (int) $inviteUserId === (int) $order->user_id) {
                break;
            }
            $visited[$inviteUserId] = true;

            $inviter = User::where('id', $inviteUserId)
                ->lockForUpdate()
                ->first();
            if (!$inviter) {
                // 链断了，下层也无法续上
                break;
            }
            // 立即推进：原实现是把这一句放在循环尾，导致 continue 跳过推进，下层重新加载到同一个
            // inviter，比例为 0 的层会把上层比例错发给同一人；getCommissionShareLevels 返回稀疏数组
            // （跳过 share=0 的层）时这个 bug 必现。
            $nextInviteUserId = $inviter->invite_user_id;

            if (!isset($commissionShareLevels[$l])) {
                $inviteUserId = $nextInviteUserId;
                continue;
            }
            $commissionBalance = (int) floor($order->commission_balance * ($commissionShareLevels[$l] / 100));
            if (!$commissionBalance) {
                $inviteUserId = $nextInviteUserId;
                continue;
            }
            if ((int)admin_setting('withdraw_close_enable', 0)) {
                $inviter->balance = (int) ($inviter->balance ?? 0) + $commissionBalance;
            } else {
                $inviter->commission_balance = (int) ($inviter->commission_balance ?? 0) + $commissionBalance;
            }
            if (!$inviter->save()) {
                return false;
            }
            CommissionLog::create([
                'invite_user_id' => $inviter->id,
                'user_id' => $order->user_id,
                'trade_no' => $order->trade_no,
                'order_amount' => $order->total_amount,
                'get_amount' => $commissionBalance
            ]);
            // 佣金到账事件：给插件留扩展点（Telegram/邮件通知等）。
            // 必须吞掉 hook 异常：本方法在结算事务内执行，插件抛错会回滚整笔结算并卡单。
            try {
                HookManager::call('commission.paid', [
                    'invite_user_id' => $inviter->id,
                    'user_id' => $order->user_id,
                    'trade_no' => $order->trade_no,
                    'get_amount' => $commissionBalance,
                    'level' => $l + 1,
                ]);
            } catch (\Throwable $e) {
                // 本段在结算事务内：Log::warning 若因日志通道故障再抛，会回滚整笔结算并卡单。
                // 套一层吞掉，hook 失败绝不能影响佣金落账。
                try {
                    Log::warning('commission.paid hook failed', [
                        'trade_no' => $order->trade_no,
                        'msg' => $e->getMessage(),
                    ]);
                } catch (\Throwable $ignored) {
                }
            }
            // update order actual commission balance
            $order->actual_commission_balance = (int) ($order->actual_commission_balance ?? 0) + $commissionBalance;

            $inviteUserId = $nextInviteUserId;
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
