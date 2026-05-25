<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Jobs\OrderHandleJob;
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

        return DB::transaction(function () use ($user, $plan, $period, $couponCode, $userService) {
            $newPeriod = PlanService::getPeriodKey($period);

            $order = new Order([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'period' => $newPeriod,
                'trade_no' => Helper::generateOrderNo(),
                'total_amount' => (int) (optional($plan->prices)[$newPeriod] * 100),
            ]);

            $orderService = new self($order);

            if ($couponCode) {
                $orderService->applyCoupon($couponCode);
            }

            $orderService->setVipDiscount($user);
            $orderService->setOrderType($user);

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
        $preserveNextResetAt = null;
        $shouldPreserveResetSchedule = false;

        HookManager::call('order.open.before', $order);


        DB::transaction(function () use ($order, $plan, &$preserveNextResetAt, &$shouldPreserveResetSchedule) {
            $this->user = User::lockForUpdate()->find($order->user_id);

            if (!in_array((string) $order->period, [Plan::PERIOD_RESET_TRAFFIC], true)) {
                app(TrafficResetService::class)->checkAndReset($this->user, TrafficResetLog::SOURCE_ORDER);
                $this->user->refresh();
            }

            $shouldPreserveResetSchedule = $this->shouldPreserveResetSchedule($order);
            if ($shouldPreserveResetSchedule) {
                $preserveNextResetAt = $this->user->next_reset_at;
            }

            if ($order->refund_amount) {
                $this->user->balance += $order->refund_amount;
            }

            if ($order->surplus_order_ids) {
                Order::whereIn('id', $order->surplus_order_ids)
                    ->update(['status' => Order::STATUS_DISCOUNTED]);
            }

            match ((string) $order->period) {
                Plan::PERIOD_ONETIME => $this->buyByOneTime($plan),
                Plan::PERIOD_RESET_TRAFFIC => app(TrafficResetService::class)->performReset($this->user, TrafficResetLog::SOURCE_ORDER),
                default => $this->buyByPeriod($order, $plan),
            };

            $this->setSpeedLimit($plan->speed_limit);
            $this->setDeviceLimit($plan->device_limit);

            if (!$this->user->save()) {
                throw new \RuntimeException('用户信息保存失败');
            }

            if ($shouldPreserveResetSchedule && $preserveNextResetAt !== null) {
                User::withoutEvents(function () use ($preserveNextResetAt) {
                    $this->user->next_reset_at = $preserveNextResetAt;
                    $this->user->save();
                });
            }

            $order->status = Order::STATUS_COMPLETED;
            if (!$order->save()) {
                throw new \RuntimeException('订单信息保存失败');
            }
        });

        $eventId = match ((int) $order->type) {
            Order::STATUS_PROCESSING => admin_setting('new_order_event_id', 0),
            Order::TYPE_RENEWAL => admin_setting('renew_order_event_id', 0),
            Order::TYPE_UPGRADE => admin_setting('change_order_event_id', 0),
            default => 0,
        };

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
            if (!(int) admin_setting('plan_change_enable', 1))
                throw new ApiException('目前不允许更改订阅，请联系客服或提交工单操作');
            $order->type = Order::TYPE_UPGRADE;
            if ((int) admin_setting('surplus_enable', 1))
                $this->getSurplusValue($user, $order);
            if ($order->surplus_amount >= $order->total_amount) {
                $order->refund_amount = (int) ($order->surplus_amount - $order->total_amount);
                $order->total_amount = 0;
            } else {
                $order->total_amount = (int) ($order->total_amount - $order->surplus_amount);
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
        if ($user->discount) {
            $order->discount_amount = $order->discount_amount + ($order->total_amount * ($user->discount / 100));
        }
        $order->total_amount = $order->total_amount - $order->discount_amount;
    }

    public function setInvite(User $user): void
    {
        $order = $this->order;
        if ($user->invite_user_id && ($order->total_amount <= 0))
            return;
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
        if ($inviter->commission_rate) {
            $order->commission_balance = $order->total_amount * ($inviter->commission_rate / 100);
        } else {
            $order->commission_balance = $order->total_amount * (admin_setting('invite_commission', 10) / 100);
        }
    }

    private function haveValidOrder(User $user): Order|null
    {
        return Order::where('user_id', $user->id)
            ->whereNotIn('status', [Order::STATUS_PENDING, Order::STATUS_CANCELLED])
            ->first();
    }

    private function getSurplusValue(User $user, Order $order)
    {
        if ($user->expired_at === NULL) {
            $lastOneTimeOrder = Order::where('user_id', $user->id)
                ->where('period', Plan::PERIOD_ONETIME)
                ->where('status', Order::STATUS_COMPLETED)
                ->orderBy('id', 'DESC')
                ->first();
            if (!$lastOneTimeOrder)
                return;
            $nowUserTraffic = Helper::transferToGB($user->transfer_enable);
            if (!$nowUserTraffic)
                return;
            $paidTotalAmount = ($lastOneTimeOrder->total_amount + $lastOneTimeOrder->balance_amount);
            if (!$paidTotalAmount)
                return;
            $trafficUnitPrice = $paidTotalAmount / $nowUserTraffic;
            $notUsedTraffic = $nowUserTraffic - Helper::transferToGB($user->u + $user->d);
            $result = $trafficUnitPrice * $notUsedTraffic;
            $order->surplus_amount = (int) ($result > 0 ? $result : 0);
            $order->surplus_order_ids = Order::where('user_id', $user->id)
                ->where('period', '!=', Plan::PERIOD_RESET_TRAFFIC)
                ->where('status', Order::STATUS_COMPLETED)
                ->pluck('id')
                ->all();
        } else {
            $this->getSurplusValueByPeriod($user, $order);
        }
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

        $orderAmountSum = (int) $orders->sum(fn(Order $item) => max(0, ($item->total_amount ?? 0) + ($item->balance_amount ?? 0) + ($item->surplus_amount ?? 0) - ($item->refund_amount ?? 0)));
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
        $totalTraffic = (int) ($user->transfer_enable ?? 0);
        if ($totalTraffic <= 0) {
            return 0;
        }

        $usedTraffic = (int) ($user->u ?? 0) + (int) ($user->d ?? 0);
        return max(0, min(1, ($totalTraffic - $usedTraffic) / $totalTraffic));
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

    public function cancel(): bool
    {
        $order = $this->order;
        HookManager::call('order.cancel.before', $order);
        try {
            DB::beginTransaction();
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
        // change plan process
        if ((int) $order->type === Order::TYPE_UPGRADE) {
            $this->user->expired_at = time();
        }
        $this->user->transfer_enable = $plan->transfer_enable * 1073741824;
        // 从一次性转换到循环或者新购的时候，重置流量
        if ($this->user->expired_at === NULL || $order->type === Order::TYPE_NEW_PURCHASE)
            app(TrafficResetService::class)->performReset($this->user, TrafficResetLog::SOURCE_ORDER);
        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->expired_at = $this->getTime($order->period, $this->user->expired_at);
    }

    private function shouldPreserveResetSchedule(Order $order): bool
    {
        return (int) $order->type === Order::TYPE_UPGRADE
            && (int) admin_setting('change_order_event_id', 0) === 0;
    }

    private function buyByOneTime(Plan $plan)
    {
        app(TrafficResetService::class)->performReset($this->user, TrafficResetLog::SOURCE_ORDER);
        $this->user->transfer_enable = $plan->transfer_enable * 1073741824;
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
