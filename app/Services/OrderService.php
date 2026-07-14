<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Jobs\OrderHandleJob;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Plan;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Support\PaymentMetrics;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Services\PlanService;

class OrderService
{
    private const BYTES_PER_GB = 1073741824;

    /**
     * 迟到支付自动复活的最大迟到窗口（秒）。
     * 网关收款会话存活期是分钟级（BEpusdt 默认 10 分钟、易支付类同量级），正常迟到
     * 支付都发生在下单后极短时间内；超过该窗口的「复活」更可能是重放或异常场景，
     * 且折抵快照的价值漂移开始不可忽略，一律转人工。
     */
    private const REOPEN_MAX_LATE_SECONDS = 86400;

    const STR_TO_TIME = [
        Plan::PERIOD_MONTHLY => 1,
        Plan::PERIOD_QUARTERLY => 3,
        Plan::PERIOD_HALF_YEARLY => 6,
        Plan::PERIOD_YEARLY => 12,
        Plan::PERIOD_TWO_YEARLY => 24,
        Plan::PERIOD_THREE_YEARLY => 36
    ];
    public $order;
    public $user;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    /**
     * Create an order from a request.
     *
     * @param User $user
     * @param Plan $plan
     * @param string $period
     * @param string|null $couponCode
     * @return Order
     * @throws ApiException
     */
    public static function createFromRequest(
        User $user,
        Plan $plan,
        string $period,
        ?string $couponCode = null,
    ): Order {
        $userService = app(UserService::class);
        $planService = new PlanService($plan);

        $planService->validatePurchase($user, $period);
        HookManager::call('order.create.before', [$user, $plan, $period, $couponCode]);

        return DB::transaction(function () use ($user, $plan, $period, $couponCode, $userService, $planService) {
            $user = User::lockForUpdate()->find($user->id);
            if (!$user) {
                throw new ApiException(__('The user does not exist'));
            }
            if ($userService->isNotCompleteOrderByUserId($user->id)) {
                throw new ApiException(__('You have an unpaid or pending order, please try again later or cancel it'));
            }
            $planService->validatePurchase($user, $period);

            $newPeriod = PlanService::getPeriodKey($period);

            $order = new Order([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'period' => $newPeriod,
                'trade_no' => Helper::generateOrderNo(),
                'total_amount' => (int) (optional($plan->prices)[$newPeriod] * 100),
            ]);

            $orderService = new self($order);

            $orderService->setOrderType($user);

            // 套餐折抵必须先于优惠券/VIP 折扣计算。优惠券只能减少剩余应付额，
            // 不能把已计算出的旧套餐剩余价值继续放大。
            if ($couponCode && $order->total_amount > 0) {
                $orderService->applyCoupon($couponCode);
            }

            if ($order->total_amount > 0) {
                $orderService->setVipDiscount($user);
            }

            if ($user->balance && $order->total_amount > 0) {
                $orderService->handleUserBalance($user, $userService);
            }

            // 必须在 handleUserBalance 之后调用：佣金基数为净现金应付额，
            // 余额抵扣部分（含来自佣金转入的余额）不计入返佣，防止套利环路。
            $orderService->setInvite(user: $user);

            if (!$order->save()) {
                throw new ApiException(__('Failed to create order'));
            }

            HookManager::call('order.create.after', $order);
            // 兼容旧钩子
            HookManager::call('order.after_create', $order);

            return $order;
        });
    }

    public function open(): void
    {
        $order = $this->order;
        $plan = Plan::find($order->plan_id);

        HookManager::call('order.open.before', $order);


        DB::transaction(function () use ($order, $plan) {
            $this->user = User::lockForUpdate()->find($order->user_id);

            if (
                !in_array((string) $order->period, [Plan::PERIOD_RESET_TRAFFIC], true)
                && (int) $order->type !== Order::TYPE_UPGRADE
            ) {
                app(TrafficResetService::class)->checkAndReset($this->user, TrafficResetLog::SOURCE_ORDER);
                $this->user->refresh();
            }

            if ($order->surplus_order_ids) {
                $surplusIds = array_values(array_filter(array_map('intval', (array) $order->surplus_order_ids)));
                // 条件更新：只允许「已完成 → 已折抵」。affected 数不足说明部分折抵源
                // 已被其他订单折抵（典型：取消单复活与重下的新单引用同一批折抵源，
                // 先开通的一单消耗后另一单又完成支付）。此时继续开通等于同一批旧订单
                // 的剩余价值被折抵两次，必须中止转人工；事务回滚不留半套状态。
                $marked = Order::whereIn('id', $surplusIds)
                    ->where('status', Order::STATUS_COMPLETED)
                    ->update(['status' => Order::STATUS_DISCOUNTED]);
                if ($marked !== count($surplusIds)) {
                    PaymentMetrics::warn('order.open.surplus_conflict', [
                        'trade_no' => (string) $order->trade_no,
                        'expected' => count($surplusIds),
                        'marked' => (int) $marked,
                    ]);
                    throw new \RuntimeException('折抵源订单状态已变化（疑似重复折抵），中止自动开通转人工');
                }
            }

            match ((string) $order->period) {
                Plan::PERIOD_ONETIME => $this->buyByOneTime($plan),
                Plan::PERIOD_RESET_TRAFFIC => app(TrafficResetService::class)->performReset($this->user, TrafficResetLog::SOURCE_ORDER),
                default => $this->buyByPeriod($order, $plan),
            };

            $this->setSpeedLimit($plan->speed_limit);
            $this->setDeviceLimit($plan->device_limit);

            if ((int) ($order->refund_amount ?? 0) > 0) {
                $this->user->balance = (int) ($this->user->balance ?? 0) + (int) $order->refund_amount;
            }

            if (!$this->user->save()) {
                throw new \RuntimeException('用户信息保存失败');
            }

            $order->status = Order::STATUS_COMPLETED;
            if (!$order->save()) {
                throw new \RuntimeException('订单信息保存失败');
            }
        });

        $eventId = $this->getOpenEventId($order);

        if ($eventId) {
            $this->openEvent($eventId);
        }

        HookManager::call('order.open.after', $order);
    }


    public function setOrderType(User $user)
    {
        $order = $this->order;
        if ($order->period === Plan::PERIOD_RESET_TRAFFIC) {
            $order->type = Order::TYPE_RESET_TRAFFIC;
        } else if ($user->plan_id !== NULL && $order->plan_id !== $user->plan_id && ($user->expired_at > time() || $user->expired_at === NULL)) {
            // 套餐变更：旧套餐立即终止，新套餐从支付完成时间重新开始。
            // 这条规则一次性消除三类问题：
            //   - 旧周期剩余时间吃新套餐额度、再叠加下一完整周期
            //   - 烧流量后降级按剩余流量/时间折抵，不按标价套现
            //   - pending 机制的双重权益/年付被砍 11 个月等坑
            if (!(int) admin_setting('plan_change_enable', 1))
                throw new ApiException('目前不允许更改订阅，请联系客服或提交工单操作');
            $order->type = Order::TYPE_UPGRADE;
            if ((int) admin_setting('surplus_enable', 1)) {
                $this->getSurplusValue($user, $order);
                $this->applySurplusDiscount($order);
            }
        } else if (($user->expired_at === null || $user->expired_at > time()) && $order->plan_id == $user->plan_id) { // 用户订阅未过期或按流量订阅 且购买订阅与当前订阅相同 === 续费
            $order->type = Order::TYPE_RENEWAL;
        } else { // 新购
            $order->type = Order::TYPE_NEW_PURCHASE;
        }
    }

    public function setVipDiscount(User $user)
    {
        $order = $this->order;
        $orderAmount = max(0, (int) $order->total_amount);
        $discountAmount = max(0, (int) round((float) ($order->discount_amount ?? 0)));
        if ($user->discount) {
            $discountAmount += (int) round($orderAmount * ($user->discount / 100));
        }
        $order->discount_amount = min($discountAmount, $orderAmount);
        $order->total_amount = $orderAmount - $order->discount_amount;
    }

    public function setInvite(User $user): void
    {
        $order = $this->order;
        if (!$user->invite_user_id) {
            return;
        }
        // 排除自邀：原实现没拦自指，自邀订单会让 inviter == 下单人，CheckCommission 仍会发佣金。
        // payHandle 层面有 $visited 防环，但脏数据落到 order.invite_user_id 后续 admin 查询/统计依旧会错。
        if ((int) $user->invite_user_id === (int) $user->id) {
            return;
        }

        // 原实现 `if ($user->invite_user_id && total_amount<=0) return` 会让 0 元订单（被余额/折扣抵掉）
        // 丢失 invite_user_id 字段。admin 端 is_commission 过滤、邀请关系回溯都会漏掉这类订单。
        // 现在改为：总是写 invite_user_id（保留邀请关系），只是当金额 0 时 commission_balance 自然算出 0。
        $order->invite_user_id = $user->invite_user_id;
        $inviter = User::find($user->invite_user_id);
        if (!$inviter)
            return;
        $commissionType = (int) $inviter->commission_type;
        if ($commissionType === User::COMMISSION_TYPE_SYSTEM) {
            $commissionType = (bool) admin_setting('commission_first_time_enable', true) ? User::COMMISSION_TYPE_ONETIME : User::COMMISSION_TYPE_PERIOD;
        }
        $isCommission = false;
        switch ($commissionType) {
            case User::COMMISSION_TYPE_PERIOD:
                $isCommission = true;
                break;
            case User::COMMISSION_TYPE_ONETIME:
                $isCommission = !$this->haveValidOrder($user);
                break;
        }

        if (!$isCommission)
            return;
        // 保持原 truthy 语义（commission_rate 为 0/null 都回落全局默认）：
        // XBoard-admin 用户编辑页「清空比例 = 跟随站点默认」实际会下发 0（el-input 清空→Number('')=0），
        // 若改成 `!== null` 把 0 当惩罚性归零，会让 admin 的「清空=跟随默认」静默变成「该用户零返佣」，
        // 破坏既有 admin 前端契约。惩罚性归零本就从未生效，无人依赖，故维持 `?:` 不动。
        $commissionRate = $inviter->commission_rate ?: admin_setting('invite_commission', 10);
        $commissionRate = max(0, min(100, (float) $commissionRate));
        // total_amount 已经是被折扣/余额抵扣后的最终金额，乘比例可能为 0（合法 → 不发佣金）
        $order->commission_balance = (int) floor(max(0, (int) $order->total_amount) * ($commissionRate / 100));
    }

    private function applySurplusDiscount(Order $order): void
    {
        $orderAmount = max(0, (int) $order->total_amount);
        $surplusAmount = max(0, (int) ($order->surplus_amount ?? 0));

        if ($surplusAmount >= $orderAmount) {
            $order->surplus_amount = $orderAmount;
            $order->refund_amount = $surplusAmount - $orderAmount;
            $order->total_amount = 0;
            return;
        }

        $order->surplus_amount = $surplusAmount;
        $order->refund_amount = 0;
        $order->total_amount = $orderAmount - $surplusAmount;
    }

    private function haveValidOrder(User $user): Order|null
    {
        return Order::where('user_id', $user->id)
            ->whereNotIn('status', [Order::STATUS_PENDING, Order::STATUS_CANCELLED])
            ->first();
    }

    private function getSurplusValue(User $user, Order $order): void
    {
        if ($user->expired_at === null) {
            $lastOneTimeOrder = Order::where('user_id', $user->id)
                ->where('period', Plan::PERIOD_ONETIME)
                ->where('status', Order::STATUS_COMPLETED)
                ->orderBy('id', 'DESC')
                ->first();
            if (!$lastOneTimeOrder) {
                return;
            }

            $nowUserTraffic = Helper::transferToGB($this->getSurplusTrafficLimit($user));
            if (!$nowUserTraffic) {
                return;
            }

            $paidTotalAmount = (int) (($lastOneTimeOrder->total_amount ?? 0) + ($lastOneTimeOrder->balance_amount ?? 0));
            if (!$paidTotalAmount) {
                return;
            }

            $trafficUnitPrice = $paidTotalAmount / $nowUserTraffic;
            $notUsedTraffic = $nowUserTraffic - Helper::transferToGB((int) ($user->u ?? 0) + (int) ($user->d ?? 0));
            $result = $trafficUnitPrice * $notUsedTraffic;
            $order->surplus_amount = (int) ($result > 0 ? $result : 0);
            $order->surplus_order_ids = Order::where('user_id', $user->id)
                ->where('period', '!=', Plan::PERIOD_RESET_TRAFFIC)
                ->where('status', Order::STATUS_COMPLETED)
                ->pluck('id')
                ->all();
            return;
        }

        $this->getSurplusValueByPeriod($user, $order);
    }

    private function getSurplusValueByPeriod(User $user, Order $order): void
    {
        $orders = Order::query()
            ->where('user_id', $user->id)
            ->whereNotIn('period', [Plan::PERIOD_RESET_TRAFFIC, Plan::PERIOD_ONETIME])
            ->where('status', Order::STATUS_COMPLETED)
            ->get()
            ->filter(function (Order $item) {
                $months = self::STR_TO_TIME[PlanService::getPeriodKey((string) $item->period)] ?? 0;
                if ($months <= 0) {
                    return false;
                }

                return Carbon::createFromTimestamp($item->created_at)->addMonths($months)->timestamp > time();
            });

        if ($orders->isEmpty()) {
            $order->surplus_amount = 0;
            $order->surplus_order_ids = [];
            return;
        }

        // surplus_amount 已在 applySurplusDiscount() 被封顶到订单价，超出部分作为 refund_amount
        // 单独退回余额（open() 内 user.balance += refund_amount）。因此 total+balance+surplus 已
        // 精确等于"消耗进该套餐的金额"，不能再减 refund_amount——否则退款被双扣（既进余额又从套餐
        // 折抵基数抹掉），客户每次"折抵超价退款"的套餐变更都会损失一笔=refund_amount 的钱。
        $orderAmountSum = (int) $orders->sum(fn(Order $item) => max(0, (int) (($item->total_amount ?? 0) + ($item->balance_amount ?? 0) + ($item->surplus_amount ?? 0))));
        $orderMonthSum = (int) $orders->sum(fn(Order $item) => self::STR_TO_TIME[PlanService::getPeriodKey((string) $item->period)] ?? 0);
        if ($orderAmountSum <= 0 || $orderMonthSum <= 0) {
            $order->surplus_amount = 0;
            $order->surplus_order_ids = $orders->pluck('id')->all();
            return;
        }

        $now = time();
        $expiredAt = (int) $user->expired_at;
        if ($expiredAt <= $now) {
            $order->surplus_amount = 0;
            $order->surplus_order_ids = $orders->pluck('id')->all();
            return;
        }

        $monthlyAmount = $orderAmountSum / $orderMonthSum;
        $trafficRatio = $this->getCurrentCycleTrafficRatio($user);
        [$currentCycleTimeRatio, $futureCycleRatio] = $this->getRemainingCycleRatios($user, $now, $expiredAt);

        $currentCycleValue = $monthlyAmount * min($currentCycleTimeRatio, $trafficRatio);
        $futureCycleValue = $monthlyAmount * $futureCycleRatio;
        $surplusAmount = min($orderAmountSum, $currentCycleValue + $futureCycleValue);

        $order->surplus_amount = (int) max(0, $surplusAmount);
        $order->surplus_order_ids = $orders->pluck('id')->all();
    }

    private function getCurrentCycleTrafficRatio(User $user): float
    {
        $totalTraffic = $this->getSurplusTrafficLimit($user);
        if ($totalTraffic <= 0) {
            return 0;
        }

        $usedTraffic = (int) ($user->u ?? 0) + (int) ($user->d ?? 0);
        return max(0, min(1, ($totalTraffic - $usedTraffic) / $totalTraffic));
    }

    private function getSurplusTrafficLimit(User $user): int
    {
        $userTraffic = max(0, (int) ($user->transfer_enable ?? 0));
        $planTraffic = $user->plan
            ? max(0, (int) $user->plan->transfer_enable * self::BYTES_PER_GB)
            : 0;

        if ($userTraffic > 0 && $planTraffic > 0) {
            return min($userTraffic, $planTraffic);
        }

        return max($userTraffic, $planTraffic);
    }

    private function getRemainingCycleRatios(User $user, int $now, int $expiredAt): array
    {
        $monthSeconds = 30 * 86400;
        $cycleEnd = (int) ($user->next_reset_at ?: 0);

        if ($cycleEnd <= $now || $cycleEnd > $expiredAt) {
            $cycleEnd = min($expiredAt, $now + $monthSeconds);
        }

        $cycleStart = (int) ($user->last_reset_at ?: 0);
        if ($cycleStart <= 0 || $cycleStart >= $cycleEnd) {
            $cycleStart = max($now - $monthSeconds, $cycleEnd - $monthSeconds);
        }

        $cycleSeconds = max(1, $cycleEnd - $cycleStart);
        $currentRemainSeconds = max(0, $cycleEnd - $now);
        $futureSeconds = max(0, $expiredAt - $cycleEnd);

        return [
            min(1, $currentRemainSeconds / $cycleSeconds),
            $futureSeconds / $monthSeconds,
        ];
    }

    /**
     * 标记订单已支付。
     *
     * 行锁 + 事务保证同一笔 trade_no 并发 webhook 只会成功翻转状态一次：
     *   1. lockForUpdate 必须在 DB::transaction 内才真正持锁（autocommit 下立即释放）；
     *   2. 锁内重新读取 status，已 PROCESSING/COMPLETED 的视为重复回调，幂等返回 true；
     *   3. 状态翻转 commit 后再 dispatchSync 开通逻辑——保留同步派发避免对队列 worker
     *      产生硬依赖，升级镜像后即使 Horizon 没起来也不会卡单；
     *   4. 任何环节抛异常都不向网关暴露，避免 webhook 重投把订单锁死在 PROCESSING。
     */
    public function paid(string $callbackNo): bool
    {
        $tradeNo = $this->order->trade_no;

        try {
            $action = DB::transaction(function () use ($tradeNo, $callbackNo) {
                $locked = Order::where('trade_no', $tradeNo)->lockForUpdate()->first();
                if (!$locked) {
                    return 'missing';
                }
                if ($locked->status !== Order::STATUS_PENDING) {
                    PaymentMetrics::inc('order.paid.duplicate', [
                        'status' => (string) $locked->status,
                    ]);
                    return 'duplicate';
                }
                $locked->status = Order::STATUS_PROCESSING;
                $locked->paid_at = time();
                $locked->callback_no = $callbackNo;
                if (!$locked->save()) {
                    throw new \RuntimeException('order save failed');
                }
                $this->order = $locked;
                return 'paid';
            });
        } catch (\Throwable $e) {
            Log::error('OrderService::paid transaction failed', [
                'trade_no' => $tradeNo,
                'message' => $e->getMessage(),
            ]);
            PaymentMetrics::inc('order.paid.exception');
            return false;
        }

        if ($action === 'missing') {
            return false;
        }
        if ($action === 'duplicate') {
            return true;
        }

        try {
            OrderHandleJob::dispatchSync($tradeNo);
        } catch (\Throwable $e) {
            Log::error('OrderHandleJob dispatchSync failed', [
                'trade_no' => $tradeNo,
                'message' => $e->getMessage(),
            ]);
            PaymentMetrics::inc('order.dispatch.failed');
            return false;
        }
        return true;
    }

    /**
     * 已取消订单收到真实迟到支付后，复核下单时占用的资源并重新开通。
     *
     * cancel() 会反向释放的资源有：退还 balance_amount、恢复优惠券用量；套餐折抵
     * （surplus_order_ids）虽在 open() 才真正消耗，但取消后折抵源可能已被后续订单
     * 折抵。重开的原则是「先复核、再占用、后翻状态」，任一资源无法完整恢复到下单
     * 时的占用状态就返回 'manual' 交人工，绝不部分开通：
     *   - 折抵源订单必须仍全部处于「已完成」（行锁防并发）；
     *   - 余额抵扣需用户当前余额仍足够，重新扣回；
     *   - 优惠券配额需仍可原子占用（全局 limit_use 条件扣减 + 每人限用复核）。
     *     券的时间窗不复核：用户在券有效期内下的单、按折后价真实付了款，迟到只是
     *     网关结算延迟，重占配额即可，不没收既定折扣。
     *
     * 所有只读校验先于所有写入执行；写入阶段的意外失败一律抛异常整体回滚，落入
     * 'error' 分支（与 'manual' 一样会转人工告警），不会留下半套状态。
     *
     * 安全性说明：本方法只在回调签名已由订单绑定网关验过之后（PaymentController::handle
     * 上游）才会被调用，且调用前已强制校验回调网关与订单 payment_id 同源，因此攻击者
     * 无法为他人订单触发重开，只能重开自己真实支付过的订单。
     *
     * @return string 'reopened' | 'duplicate' | 'manual' | 'missing' | 'unexpected' | 'error'
     */
    public function reopenFromCancelled(string $callbackNo): string
    {
        $tradeNo = $this->order->trade_no;

        try {
            $action = DB::transaction(function () use ($tradeNo, $callbackNo) {
                $locked = Order::where('trade_no', $tradeNo)->lockForUpdate()->first();
                if (!$locked) {
                    return 'missing';
                }
                // 幂等：并发回调 / 网关重投时若已被翻成开通中或已完成，视为重复通知。
                if (in_array((int) $locked->status, [Order::STATUS_PROCESSING, Order::STATUS_COMPLETED], true)) {
                    return 'duplicate';
                }
                // 只对「已取消」重开；其余状态不在本方法处理范围。
                if ((int) $locked->status !== Order::STATUS_CANCELLED) {
                    return 'unexpected';
                }

                // 安全闸门 0：迟到窗口，超过上限的复活转人工（见常量注释）。
                if (time() - (int) $locked->created_at > self::REOPEN_MAX_LATE_SECONDS) {
                    return 'manual';
                }

                // 安全闸门 B：该订单取消后用户又已完成/开通更晚的订单，重开会覆盖其当前订阅 → 人工。
                $hasNewerActiveOrder = Order::where('user_id', $locked->user_id)
                    ->where('id', '>', $locked->id)
                    ->whereIn('status', [Order::STATUS_PROCESSING, Order::STATUS_COMPLETED])
                    ->exists();
                if ($hasNewerActiveOrder) {
                    return 'manual';
                }

                // 安全闸门 C：套餐仍存在。
                if (!Plan::find($locked->plan_id)) {
                    return 'manual';
                }

                // ── 安全闸门 A：复核并重新占用下单时的资源（先只读校验，后统一写入）──

                // A1 套餐折抵：折抵源订单必须仍全部「已完成」。若已被其他订单折抵
                // （典型：用户取消本单后重下一单引用同一批折抵源且已开通）或被撤销，
                // 创建时的折抵快照失效 → 人工。行锁串行化与并发 open() 的竞争；真正
                // 翻成 DISCOUNTED 由本单后续 open() 在同样的条件守卫下完成。
                $surplusIds = array_values(array_filter(array_map('intval', (array) ($locked->surplus_order_ids ?? []))));
                if (!empty($surplusIds)) {
                    $stillCompleted = Order::whereIn('id', $surplusIds)
                        ->where('status', Order::STATUS_COMPLETED)
                        ->lockForUpdate()
                        ->count();
                    if ($stillCompleted !== count($surplusIds)) {
                        return 'manual';
                    }
                }

                // A2 余额抵扣（只读校验）：cancel() 已退回余额，重开需重新扣除；
                // 余额可能已被花掉，先锁用户行校验充足性。
                $balanceAmount = (int) ($locked->balance_amount ?? 0);
                if ($balanceAmount < 0) {
                    return 'manual'; // 异常数据，不自动处理
                }
                $lockedUser = User::lockForUpdate()->find($locked->user_id);
                if (!$lockedUser) {
                    return 'manual';
                }
                if ($balanceAmount > 0 && (int) $lockedUser->balance < $balanceAmount) {
                    return 'manual';
                }

                // A3 优惠券：cancel() 已恢复用量，重开需重新占用。券被删除 → 人工；
                // 每人限用按当前非待付/非取消订单数复核（本单此刻仍是 CANCELLED，不计入）；
                // 全局配额用条件 UPDATE 原子扣减，抢不到 → 人工。该扣减虽是写入，但失败
                // 即 affected=0 且不产生任何变更，放在只读校验末尾是安全的。
                if ($locked->coupon_id !== null) {
                    $coupon = Coupon::lockForUpdate()->find($locked->coupon_id);
                    if (!$coupon) {
                        return 'manual';
                    }
                    if ($coupon->limit_use_with_user !== null) {
                        $usedCount = Order::where('coupon_id', $coupon->id)
                            ->where('user_id', $locked->user_id)
                            ->whereNotIn('status', [Order::STATUS_PENDING, Order::STATUS_CANCELLED])
                            ->count();
                        if ($usedCount >= (int) $coupon->limit_use_with_user) {
                            return 'manual';
                        }
                    }
                    if ($coupon->limit_use !== null) {
                        $affected = Coupon::where('id', $coupon->id)
                            ->where('limit_use', '>', 0)
                            ->decrement('limit_use');
                        if ($affected === 0) {
                            return 'manual';
                        }
                    }
                }

                // ── 写入阶段：以下失败均抛异常，整体回滚（含上面的优惠券扣减）──

                if ($balanceAmount > 0) {
                    $lockedUser->balance = (int) $lockedUser->balance - $balanceAmount;
                    if (!$lockedUser->save()) {
                        throw new \RuntimeException('re-deduct balance failed');
                    }
                }

                $locked->status = Order::STATUS_PROCESSING;
                $locked->paid_at = time();
                $locked->callback_no = $callbackNo;
                if (!$locked->save()) {
                    throw new \RuntimeException('order save failed');
                }
                $this->order = $locked;
                return 'reopened';
            });
        } catch (\Throwable $e) {
            Log::error('OrderService::reopenFromCancelled transaction failed', [
                'trade_no' => $tradeNo,
                'message' => $e->getMessage(),
            ]);
            PaymentMetrics::inc('order.late_paid.exception');
            return 'error';
        }

        if ($action === 'reopened') {
            try {
                OrderHandleJob::dispatchSync($tradeNo); // 复用既有开通流程（PROCESSING → open()）
            } catch (\Throwable $e) {
                Log::error('OrderService::reopenFromCancelled open failed', [
                    'trade_no' => $tradeNo,
                    'message' => $e->getMessage(),
                ]);
                PaymentMetrics::inc('order.late_paid.open_failed');
                return 'error';
            }
        }

        return $action;
    }

    public function cancel(): bool
    {
        $order = $this->order;
        HookManager::call('order.cancel.before', $order);
        try {
            DB::beginTransaction();
            $order = Order::where('id', $order->id)
                ->lockForUpdate()
                ->first();
            if (!$order || (int) $order->status !== Order::STATUS_PENDING) {
                DB::rollBack();
                return false;
            }
            $this->order = $order;

            $order->status = Order::STATUS_CANCELLED;
            if (!$order->save()) {
                throw new \Exception('Failed to save order status.');
            }
            if ($order->balance_amount) {
                $userService = new UserService();
                if (!$userService->addBalance($order->user_id, $order->balance_amount)) {
                    throw new \Exception('Failed to add balance.');
                }
            }
            $this->restoreCouponUsage($order);
            DB::commit();
            HookManager::call('order.cancel.after', $order);
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return false;
        }
    }

    private function setSpeedLimit($speedLimit)
    {
        $this->user->speed_limit = $speedLimit;
    }

    private function setDeviceLimit($deviceLimit)
    {
        $this->user->device_limit = $deviceLimit;
    }

    private function buyByPeriod(Order $order, Plan $plan)
    {
        $isPlanChange = (int) $order->type === Order::TYPE_UPGRADE;

        if ($isPlanChange) {
            $this->applyPlanChangeCycle($order, $plan);
            if (!app(TrafficResetService::class)->performReset($this->user, TrafficResetLog::SOURCE_ORDER)) {
                throw new \RuntimeException('套餐变更流量重置失败');
            }
            return;
        }

        // 续费 / 新购原有逻辑
        $oldExpiredAt = $this->user->expired_at;
        $this->user->transfer_enable = $plan->transfer_enable * self::BYTES_PER_GB;
        // 从一次性转换到循环或者新购的时候，重置流量
        if ($oldExpiredAt === NULL || $order->type === Order::TYPE_NEW_PURCHASE) {
            app(TrafficResetService::class)->performReset($this->user, TrafficResetLog::SOURCE_ORDER);
        }
        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->expired_at = $this->getTime($order->period, $this->user->expired_at);
    }

    private function applyPlanChangeCycle(Order $order, Plan $plan): void
    {
        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->transfer_enable = $plan->transfer_enable * self::BYTES_PER_GB;
        $this->user->expired_at = $this->getTime((string) $order->period, time());
        $this->user->setRelation('plan', $plan);
    }

    private function getOpenEventId(Order $order): int
    {
        return match ((int) $order->type) {
            Order::TYPE_NEW_PURCHASE => (int) admin_setting('new_order_event_id', 0),
            Order::TYPE_RENEWAL => (int) admin_setting('renew_order_event_id', 0),
            // 套餐变更已在 buyByPeriod 内重开周期并重置流量，避免事件再次清零。
            Order::TYPE_UPGRADE => 0,
            default => 0,
        };
    }

    private function buyByOneTime(Plan $plan)
    {
        app(TrafficResetService::class)->performReset($this->user, TrafficResetLog::SOURCE_ORDER);
        $this->user->transfer_enable = $plan->transfer_enable * self::BYTES_PER_GB;
        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->expired_at = NULL;
    }

    /**
     * 计算套餐到期时间
     * @param string $periodKey
     * @param int $timestamp
     * @return int
     * @throws ApiException
     */
    private function getTime(string $periodKey, ?int $timestamp = null): int
    {
        $timestamp = $timestamp < time() ? time() : $timestamp;
        $periodKey = PlanService::getPeriodKey($periodKey);

        if (isset(self::STR_TO_TIME[$periodKey])) {
            $months = self::STR_TO_TIME[$periodKey];
            return Carbon::createFromTimestamp($timestamp)->addMonths($months)->timestamp;
        }

        throw new ApiException('无效的套餐周期');
    }

    private function openEvent($eventId)
    {
        switch ((int) $eventId) {
            case 0:
                break;
            case 1:
                app(TrafficResetService::class)->performReset($this->user, TrafficResetLog::SOURCE_ORDER);
                break;
        }
    }

    private function restoreCouponUsage(Order $order): void
    {
        if (!$order->coupon_id) {
            return;
        }

        $coupon = Coupon::lockForUpdate()->find($order->coupon_id);
        if (!$coupon || $coupon->limit_use === null) {
            return;
        }

        $coupon->increment('limit_use');
    }

    protected function applyCoupon(string $couponCode): void
    {
        $couponService = new CouponService($couponCode);
        if (!$couponService->use($this->order)) {
            throw new ApiException(__('Coupon failed'));
        }
        $this->order->coupon_id = $couponService->getId();
    }

    /**
     * Summary of handleUserBalance
     * @param User $user
     * @param UserService $userService
     * @return void
     */
    protected function handleUserBalance(User $user, UserService $userService): void
    {
        $remainingBalance = $user->balance - $this->order->total_amount;

        if ($remainingBalance >= 0) {
            if (!$userService->addBalance($this->order->user_id, -$this->order->total_amount)) {
                throw new ApiException(__('Insufficient balance'));
            }
            $this->order->balance_amount = $this->order->total_amount;
            $this->order->total_amount = 0;
        } else {
            if (!$userService->addBalance($this->order->user_id, -$user->balance)) {
                throw new ApiException(__('Insufficient balance'));
            }
            $this->order->balance_amount = $user->balance;
            $this->order->total_amount = $this->order->total_amount - $user->balance;
        }
    }
}
