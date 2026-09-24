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
use App\Support\PaymentGatewayBinding;
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
        ?array $options = null,
        ?int $expectedAmount = null,
    ): Order {
        $userService = app(UserService::class);
        $planService = new PlanService($plan);

        $planService->validatePurchase($user, $period);
        HookManager::call('order.create.before', [$user, $plan, $period, $couponCode]);

        return DB::transaction(function () use ($user, $plan, $period, $couponCode, $userService, $options, $expectedAmount) {
            $user = User::lockForUpdate()->find($user->id);
            if (!$user) {
                throw new ApiException(__('The user does not exist'));
            }
            if ($userService->isNotCompleteOrderByUserId($user->id)) {
                throw new ApiException(__('You have an unpaid or pending order, please try again later or cancel it'));
            }
            $plan = Plan::lockForUpdate()->findOrFail($plan->id);
            (new PlanService($plan))->validatePurchase($user, $period);
            $quote = app(PlanCustomizationService::class)->quote($plan, $period, $options, $user);
            if ($expectedAmount !== null && $expectedAmount !== $quote['amount']) {
                throw new ApiException('套餐价格已更新，请重新确认报价');
            }

            $newPeriod = PlanService::getPeriodKey($period);

            $order = new Order([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'period' => $newPeriod,
                'trade_no' => Helper::generateOrderNo(),
                'total_amount' => $quote['amount'],
                ...($quote['snapshot'] ? ['plan_snapshot' => $quote['snapshot']] : []),
            ]);

            $orderService = new self($order);

            $orderService->setOrderType($user);

            // 套餐折抵必须先于优惠券/VIP 折扣计算。优惠券只能减少剩余应付额，
            // 不能把已计算出的旧套餐剩余价值继续放大。
            $fixedTopupPrice = $period === Plan::PERIOD_TRAFFIC_TOPUP && !empty($quote['snapshot']['price_overridden']);
            if ($fixedTopupPrice && $couponCode) {
                throw new ApiException('线路流量包使用固定最终价，不叠加优惠券');
            }
            if ($couponCode && $order->total_amount > 0) {
                $orderService->applyCoupon($couponCode);
            }

            if ($order->total_amount > 0 && !$fixedTopupPrice) {
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
        // 加购包快照没有 options / group_id：它不碰套餐规格，这里不回填。
        if ($plan && $order->plan_snapshot && isset($order->plan_snapshot['options'])) {
            $plan = clone $plan;
            $snapshot = $order->plan_snapshot;
            // 只把三项资源回填到 Plan：addon_groups / granted_groups 不是 Plan 属性，
            // 它们由下面的 plan_options 写入承接，不能混进套餐字段。
            $plan->forceFill(array_intersect_key($snapshot['options'], PlanCustomizationService::LIMITS) + [
                'name' => $snapshot['name'], 'group_id' => $snapshot['group_id'],
                'reset_traffic_method' => $snapshot['reset_traffic_method'],
            ]);
        }

        HookManager::call('order.open.before', $order);


        DB::transaction(function () use ($order, $plan) {
            $this->user = User::lockForUpdate()->find($order->user_id);
            if (isset($order->plan_snapshot['options'], $order->plan_snapshot['pricing_context'])) {
                $currentPlan = Plan::find($order->plan_id);
                $groups = $currentPlan ? app(PlanCustomizationService::class)->purchaseTrafficGroups(
                    $currentPlan, $order->plan_snapshot['options'], $this->user
                ) : null;
                if ($groups !== ($order->plan_snapshot['pricing_context']['addon_group_ids'] ?? null)) {
                    throw new \RuntimeException('套餐加量的线路权限已变化，中止自动开通转人工处理');
                }
            }
            // 已报价的旧订单按原金额履约，但不能冲掉迁移后为本周期补的授权。
            // 仅信任服务端保存的订单 ID 截止线；新订单、显式取消和换套餐不适用。
            $migration = $this->user->plan_options[PlanCustomizationService::MIGRATION_KEY] ?? null;
            $preserveMigratedOptions = !$order->plan_snapshot && $plan && is_array($migration)
                && (int) $this->user->plan_id === (int) $plan->id
                && (int) ($migration['plan_id'] ?? 0) === (int) $plan->id
                && (int) $order->id > 0 && (int) $order->id <= (int) ($migration['before_order_id'] ?? 0)
                && !empty($this->user->plan_options[PlanCustomizationService::ADDON_KEY]);
            if ($preserveMigratedOptions) {
                $plan = app(PlanCustomizationService::class)->forGrandfatheredUser($plan, $this->user);
                $plan = clone $plan;
                // A subsequently purchased larger tier must not be reset to the historic base by a late old order.
                $plan->forceFill(array_intersect_key($this->user->plan_options, PlanCustomizationService::LIMITS));
            }

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
                Plan::PERIOD_RESET_TRAFFIC => $this->resetTrafficForOrder(),
                Plan::PERIOD_TRAFFIC_TOPUP => $this->applyTrafficTopup($order),
                default => $this->buyByPeriod($order, $plan),
            };

            // 加购包只加流量：限速 / 设备数 / 增值组 / 套餐规格一律不碰。
            $isTopup = (string) $order->period === Plan::PERIOD_TRAFFIC_TOPUP;
            // Keep legacy resets unchanged; custom resets preserve the purchased speed/devices.
            if (!$isTopup && ($order->period !== Plan::PERIOD_RESET_TRAFFIC || (!$order->plan_snapshot && !$this->user->plan_options))) {
                $this->setSpeedLimit($plan->speed_limit);
                $this->setDeviceLimit($plan->device_limit);
            }
            if (!$isTopup && $order->period !== Plan::PERIOD_RESET_TRAFFIC) {
                if (!$preserveMigratedOptions && ($order->plan_snapshot || $this->user->plan_options)) {
                    $options = $order->plan_snapshot['options'] ?? null;
                    // granted_groups（套餐赠送 ∪ 客户已购的增值组）随快照落到用户身上：
                    // 节点可见性与节点端名单只读这个键，管理员之后改套餐配置不影响已购用户。
                    if ($options !== null && array_key_exists(PlanCustomizationService::GRANTED_KEY, $order->plan_snapshot)) {
                        $options[PlanCustomizationService::GRANTED_KEY] = $order->plan_snapshot[PlanCustomizationService::GRANTED_KEY];
                    }
                    if ($options !== null && isset($order->plan_snapshot[PlanCustomizationService::GRANDFATHER_KEY])) {
                        $options[PlanCustomizationService::GRANDFATHER_KEY] = $order->plan_snapshot[PlanCustomizationService::GRANDFATHER_KEY];
                    }
                    if ($options !== null && isset($order->plan_snapshot[PlanCustomizationService::MIGRATION_KEY])) {
                        $options[PlanCustomizationService::MIGRATION_KEY] = $order->plan_snapshot[PlanCustomizationService::MIGRATION_KEY];
                    }
                    $this->user->plan_options = $options;
                }
            }

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
        if ($order->period === Plan::PERIOD_TRAFFIC_TOPUP) {
            $order->type = Order::TYPE_TRAFFIC_TOPUP;
        } else if ($order->period === Plan::PERIOD_RESET_TRAFFIC) {
            $order->type = Order::TYPE_RESET_TRAFFIC;
        } else if ($user->plan_id !== NULL && ($order->plan_id !== $user->plan_id || $this->hasChangedOptions($user)) && ($user->expired_at > time() || $user->expired_at === NULL)) {
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

    /**
     * 下单前预演 setOrderType：这张报价真下单会被判成续费还是套餐变更、折抵多少、折抵后应付多少。
     * 只在内存里建一个 Order 跑同一段逻辑，不落库、不锁行。续费助手让用户勾改增值线路时靠它
     * 把"改了线路 = 重开周期 + 折抵剩余价值"如实摆给用户看，而不是到收银台才发现金额变了。
     * VIP 折扣在折抵之后才算（与 createFromRequest 顺序一致），所以 payable 是折抵后、折扣前。
     *
     * @return array{type: int|null, surplus_amount: int, payable: int, blocked: string|null}
     */
    public static function previewOrderType(User $user, Plan $plan, string $period, array $quote): array
    {
        $order = new Order([
            'user_id' => $user->id, 'plan_id' => $plan->id,
            'period' => PlanService::getPeriodKey($period),
            'total_amount' => (int) $quote['amount'],
            ...(!empty($quote['snapshot']) ? ['plan_snapshot' => $quote['snapshot']] : []),
        ]);
        try {
            (new self($order))->setOrderType($user);
        } catch (ApiException $e) {
            return ['type' => null, 'surplus_amount' => 0, 'payable' => (int) $quote['amount'], 'blocked' => $e->getMessage()];
        }
        return [
            'type' => (int) $order->type,
            'surplus_amount' => (int) ($order->surplus_amount ?? 0),
            'payable' => (int) $order->total_amount,
            'blocked' => null,
        ];
    }

    private function hasChangedOptions(User $user): bool
    {
        $selected = $this->order->plan_snapshot['options'] ?? null;
        if (!$selected) {
            return false;
        }
        // 本周期加购的流量已加进 transfer_enable，比较规格时要剔掉，否则加购过的人续同一套餐会被误判成改规格。
        $baseTransfer = (int) $user->transfer_enable - (int) ($user->transfer_topup ?? 0);
        if ($selected['transfer_enable'] * self::BYTES_PER_GB !== $baseTransfer
            || (int) $selected['device_limit'] !== (int) $user->device_limit
            || (int) $selected['speed_limit'] !== (int) $user->speed_limit) {
            return true;
        }
        // 同套餐只多勾/少勾一个增值节点组也是「套餐变更」：旧周期折抵、新周期从付款时重开，
        // 否则用户在周期中途加购 10x 节点会被当成续费叠时长而拿不到节点。
        $wanted = array_map('intval', $selected[PlanCustomizationService::ADDON_KEY] ?? []);
        $owned = array_map('intval', $user->plan_options[PlanCustomizationService::ADDON_KEY] ?? []);
        sort($wanted);
        sort($owned);
        return $wanted !== $owned;
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
        $periodMonths = fn(Order $item): int => self::STR_TO_TIME[PlanService::getPeriodKey((string) $item->period)] ?? 0;
        $completed = Order::query()
            ->where('user_id', $user->id)
            ->whereNotIn('period', [Plan::PERIOD_RESET_TRAFFIC, Plan::PERIOD_ONETIME])
            ->where('status', Order::STATUS_COMPLETED)
            ->orderByDesc('id')
            ->get()
            ->filter(fn(Order $item) => $periodMonths($item) > 0);

        $orders = $completed->filter(
            fn(Order $item) => Carbon::createFromTimestamp($item->created_at)->addMonths($periodMonths($item))->timestamp > time()
        );

        // 「下单日 + 周期」只是订单覆盖期的近似：提前续费的单覆盖的是旧到期日之后那一段，
        // 补偿/人工延期的天数更没有任何订单对应。这类用户按下单日筛会一张单都剩不下，
        // 但 setOrderType 判套餐变更的前提恰恰是 expired_at > now —— 剩余天数被清零、折抵却是 ¥0。
        // 兜底只取最近一张已完成的周期单作计价基准，并且：
        //   - 折抵值仍按剩余时间×流量比例算，上限是这一张单的实付（不会超过用户真付过的钱）；
        //   - 只抵新单价格、不产生 refund_amount 退余额，零现金/余额流出。
        $fallback = false;
        if ($orders->isEmpty() && (int) $user->expired_at > time() && $completed->isNotEmpty()) {
            $orders = $completed->take(1);
            $fallback = true;
        }

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
        if ($fallback) {
            $surplusAmount = min($surplusAmount, max(0, (int) $order->total_amount));
        }

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
        // 折抵只按套餐基础配额算，本周期加购的流量不参与（它随换套餐一起失效，不作价）。
        $userTraffic = max(0, (int) ($user->transfer_enable ?? 0) - (int) ($user->transfer_topup ?? 0));
        if ($user->plan_options && $userTraffic > 0) {
            return min($userTraffic, (int) $user->plan_options['transfer_enable'] * self::BYTES_PER_GB);
        }
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
    public function paid(
        string $callbackNo,
        ?int $callbackPaymentId = null,
        bool $requireGatewayMatch = false
    ): bool
    {
        $tradeNo = $this->order->trade_no;

        if (trim($callbackNo) === '') {
            PaymentMetrics::warn('order.paid.callback_missing', ['trade_no' => $tradeNo]);
            return false;
        }

        try {
            $action = DB::transaction(function () use ($tradeNo, $callbackNo, $callbackPaymentId, $requireGatewayMatch) {
                $locked = Order::where('trade_no', $tradeNo)->lockForUpdate()->first();
                if (!$locked) {
                    return 'missing';
                }
                if ($locked->status !== Order::STATUS_PENDING) {
                    PaymentMetrics::inc('order.paid.duplicate', [
                        'status' => (string) $locked->status,
                    ]);
                    if (
                        in_array((int) $locked->status, [Order::STATUS_PROCESSING, Order::STATUS_COMPLETED], true)
                        && hash_equals((string) ($locked->callback_no ?? ''), $callbackNo)
                    ) {
                        return 'duplicate';
                    }
                    return 'unexpected';
                }
                // 同源判定与 controller 预检一致（插件类 + 商户凭证指纹），但必须在持有
                // 行锁后重做一遍：预检与此处之间 payment_id 可能被并发 checkout 改写。
                if (
                    $requireGatewayMatch
                    && !PaymentGatewayBinding::equivalent(
                        $locked->payment_id === null ? null : (int) $locked->payment_id,
                        $callbackPaymentId
                    )
                ) {
                    PaymentMetrics::warn('webhook.payment_id_mismatch', [
                        'trade_no' => $tradeNo,
                        'order_payment_id' => $locked->payment_id,
                        'callback_payment_id' => $callbackPaymentId,
                    ]);
                    return 'gateway_mismatch';
                }
                if (
                    $callbackPaymentId !== null
                    && !$this->reservePaymentCallback($locked, $callbackPaymentId, $callbackNo)
                ) {
                    return 'callback_conflict';
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

        if (in_array($action, ['missing', 'unexpected', 'gateway_mismatch', 'callback_conflict'], true)) {
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
    public function reopenFromCancelled(string $callbackNo, ?int $callbackPaymentId = null): string
    {
        $tradeNo = $this->order->trade_no;

        if (trim($callbackNo) === '') {
            return 'manual';
        }

        try {
            $action = DB::transaction(function () use ($tradeNo, $callbackNo, $callbackPaymentId) {
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

                // 网关绑定必须在持有订单行锁时复核，避免 controller 预读后 checkout
                // 并发改写 payment_id 形成 TOCTOU。同源判定只认「插件类 + 商户凭证指纹」，
                // 仅凭插件类相同不算等价（那会放回跨商户翻单的口子）。
                if (
                    $callbackPaymentId !== null
                    && !PaymentGatewayBinding::equivalent(
                        $locked->payment_id === null ? null : (int) $locked->payment_id,
                        $callbackPaymentId
                    )
                ) {
                    return 'manual';
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

                if (
                    $callbackPaymentId !== null
                    && !$this->reservePaymentCallback($locked, $callbackPaymentId, $callbackNo)
                ) {
                    return 'manual';
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

    /**
     * 为外部网关流水号建立不可重复的 SHA-256 指纹。
     *
     * v2_order.callback_no 没有唯一约束，仅在代码里先查再写仍会被并发击穿。
     * 独立表的唯一 fingerprint 让「同一支付配置 + 同一网关流水」最多绑定一张订单。
     */
    private function reservePaymentCallback(Order $order, int $paymentId, string $callbackNo): bool
    {
        $fingerprint = hash('sha256', $paymentId . "\0" . $callbackNo);
        $existing = DB::table('v2_payment_callback')
            ->where('fingerprint', $fingerprint)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            return (int) $existing->order_id === (int) $order->id;
        }

        DB::table('v2_payment_callback')->insert([
            'fingerprint' => $fingerprint,
            'payment_id' => $paymentId,
            'order_id' => (int) $order->id,
            'trade_no' => (string) $order->trade_no,
            'created_at' => time(),
        ]);

        return true;
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

    /**
     * 流量重置包订单的开通动作。
     *
     * 必须检查 performReset 的返回值：它内部 catch(\Exception) 后只记日志并 return false，
     * u/d 清零会随内层事务一起回滚。原实现把返回值丢在 match 分支里，导致重置失败时
     * 订单照样被置为 COMPLETED —— 用户付了钱、流量没恢复、连 TrafficResetLog 都没有，
     * 且没有任何自愈路径（订单已终态，cron 不会再碰）。任何插件 hook 抛异常都能触发。
     *
     * 抛异常让外层 open() 事务整体回滚，订单停在 PROCESSING，由 check:order 每分钟重投
     * OrderHandleJob 重试 —— 与套餐变更路径（buyByPeriod 内）保持完全一致的失败语义。
     *
     * preserveSchedule: 重置包只清零流量、不动到期日，因此也不能改动已排定的下次重置日。
     * 不传这个参数时 performReset 会按 expired_at 重新锚定 next_reset_at，一旦二者此前
     * 因故障补偿等原因被拉开（补偿只 +expired_at 不动 next_reset_at），用户花钱买的重置包
     * 反而会把本该到来的那次免费重置吞掉并往后推。详见 TrafficResetService。
     */
    private function resetTrafficForOrder(): void
    {
        if (!app(TrafficResetService::class)->performReset($this->user, TrafficResetLog::SOURCE_ORDER, preserveSchedule: true)) {
            throw new \RuntimeException('流量重置包开通失败：流量重置未成功');
        }
    }

    /**
     * 流量加购包开通：把 N GB 同时加进 transfer_enable（节点端 / 旧前端立即看到新总量）
     * 和 transfer_topup（记账）。到下一次任何清零动作时由 TrafficResetService::performReset
     * 统一扣回归零 —— 加购只活在买它的那个周期。
     *
     * 若开通前恰好到了重置日，open() 顶部的 checkAndReset 已先做过本轮重置（旧加购随之收回），
     * 这份新加购落在新周期里，对用户是有利的一侧。
     */
    private function applyTrafficTopup(Order $order): void
    {
        $gb = (int) ($order->plan_snapshot['topup_gb'] ?? 0);
        if ($gb < 1) {
            throw new \RuntimeException('流量加购包开通失败：订单快照缺少加购数量');
        }
        if ((int) $this->user->plan_id !== (int) $order->plan_id
            || $this->user->banned
            || ($this->user->expired_at !== null && (int) $this->user->expired_at <= time())) {
            throw new \RuntimeException('流量加购的订阅状态已变化，中止自动开通转人工处理');
        }
        $customizer = app(PlanCustomizationService::class);
        $context = $order->plan_snapshot['pricing_context'] ?? null;
        if (is_array($context)) {
            if (($context['addon_group_ids'] ?? null) !== $customizer->purchaseTopupGroups($this->user)) {
                throw new \RuntimeException('流量加购的线路权限已变化，中止自动开通转人工处理');
            }
        } else {
            // Legacy pending orders have no entitlement snapshot. Do not silently
            // deliver a cheaper ordinary pack into a now-premium subscription.
            $plan = Plan::find($order->plan_id);
            if ($plan) $customizer->assertTrafficPricingAvailable($plan, $this->user->addonGroupIds(), 'topup_price_per_gb');
            $rule = $plan ? $customizer->topupRule($plan, $this->user) : null;
            if ($rule && ($rule['addon_surcharge_per_gb'] > 0 || !empty($rule['price_overridden']))) {
                $quote = $customizer->quoteTopup($plan, $this->user, ['topup_gb' => $gb]);
                if ($quote['amount'] > (int) ($order->plan_snapshot['amount'] ?? 0)) {
                    throw new \RuntimeException('旧流量加购订单与当前线路价格不匹配，中止自动开通转人工处理');
                }
            }
        }
        $bytes = $gb * self::BYTES_PER_GB;
        $this->user->transfer_enable = (int) ($this->user->transfer_enable ?? 0) + $bytes;
        $this->user->transfer_topup = (int) ($this->user->transfer_topup ?? 0) + $bytes;
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
        // 必须在覆盖 transfer_enable 之前取，否则量到的是新配额而不是用户实际被发放并消耗掉的那份。
        $usedTraffic = (int) ($this->user->u ?? 0) + (int) ($this->user->d ?? 0);
        $oldTransferEnable = (int) ($this->user->transfer_enable ?? 0);
        // 本周期的加购流量。下面会用套餐配额覆盖 transfer_enable，走「清零」分支时它随周期
        // 结束一并收回（先归零再 performReset，避免 performReset 从新配额里再扣一次）；
        // 普通续费只是叠时长、周期继续，加购要原样加回来。
        $topup = (int) ($this->user->transfer_topup ?? 0);

        $this->user->transfer_enable = $plan->transfer_enable * self::BYTES_PER_GB;
        // 从一次性转换到循环或者新购的时候，重置流量
        if ($oldExpiredAt === NULL || $order->type === Order::TYPE_NEW_PURCHASE) {
            $this->user->transfer_topup = 0;
            $topup = 0;
            app(TrafficResetService::class)->performReset($this->user, TrafficResetLog::SOURCE_ORDER);
        }
        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;

        if ($this->shouldRestartRenewalCycle($order, $oldExpiredAt, $usedTraffic, $oldTransferEnable)) {
            // 周期从付款时刻重开，并发一份新配额。旧周期剩下的那段是"零流量空壳"，
            // 对用户没有使用价值，放弃它换取立即可用的配额。
            $this->user->expired_at = $this->getTime((string) $order->period, time());
            // performReset 内部按 user->plan + user->expired_at 推算 next_reset_at，
            // 必须先把新到期日和 plan 关系装好再重置，否则锚点会落在旧周期上。
            $this->user->setRelation('plan', $plan);
            $this->user->transfer_topup = 0;
            if (!app(TrafficResetService::class)->performReset($this->user, TrafficResetLog::SOURCE_ORDER)) {
                throw new \RuntimeException('续费重开周期失败：流量重置未成功');
            }
            return;
        }

        $this->user->transfer_enable = (int) $this->user->transfer_enable + $topup;
        $this->user->expired_at = $this->getTime($order->period, $this->user->expired_at);
    }

    /**
     * 续费时是否应当「重开周期」——到期日从付款时刻重算，并立即重置流量。
     *
     * 解决的问题：月付用户第 5 天把流量跑完后再买一次同套餐，原逻辑把时间叠加到旧到期日
     * 之后（1/1 买 → 2/1 到期；1/5 续费 → 3/1 到期）却不重置流量，用户付了钱在 2/1 之前
     * 一滴流量都没有——他要的是"现在能上网"，拿到的是"3 月才到期"。
     *
     * 两个条件缺一不可：
     *   ① 流量确实已耗尽 —— 挡住"手滑提前续费"。还剩 90GB / 29 天的用户若被重开，
     *      会白丢 28 天；未达阈值就维持原叠加逻辑。
     *   ② 从今天重开不会缩短到期日 —— 挡住"长周期用户买短周期"。年付剩 11 个月的用户
     *      即使流量跑完也满足条件①，若只看条件① 就会被一张月付单把 11 个月烧成 1 个月。
     *
     * 阈值复用 advance_cycle_used_ratio：它和 AdvanceCycleService 问的是同一个问题
     * （"流量算不算跑完了"），两处口径必须一致，不另立新配置项。
     */
    private function shouldRestartRenewalCycle(
        Order $order,
        ?int $oldExpiredAt,
        int $usedTraffic,
        int $transferEnable
    ): bool {
        if ((int) $order->type !== Order::TYPE_RENEWAL) {
            return false;
        }
        // 一次性(永久)订阅没有周期可重开，且 expired_at 为 null 时条件② 无从比较。
        if ($oldExpiredAt === null || $transferEnable <= 0) {
            return false;
        }

        $ratio = admin_setting('advance_cycle_used_ratio', 0.95);
        $ratio = is_numeric($ratio) ? (float) $ratio : 0.95;
        $ratio = max(0.5, min(1.0, $ratio));

        // 条件①：流量已耗尽
        if ($usedTraffic < (int) ceil($transferEnable * $ratio)) {
            return false;
        }

        // 条件②：重开后的到期日不得早于原到期日（长周期买短周期时自动落回叠加）
        return $this->getTime((string) $order->period, time()) > $oldExpiredAt;
    }

    private function applyPlanChangeCycle(Order $order, Plan $plan): void
    {
        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        // 换套餐 = 旧周期终止，本周期加购随之失效；先归零再由随后的 performReset 清零流量。
        $this->user->transfer_topup = 0;
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
