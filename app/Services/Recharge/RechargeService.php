<?php

namespace App\Services\Recharge;

use App\Exceptions\ApiException;
use App\Jobs\OrderHandleJob;
use App\Models\BalanceLog;
use App\Models\Payment;
use App\Models\RechargeOrder;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Plugin\HookManager;
use App\Services\UserService;
use App\Support\AdminAlert;
use App\Support\FeatureFlag;
use App\Support\PaymentGatewayBinding;
use App\Support\PaymentMetrics;
use App\Support\PaymentSessions;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 余额充值：下单、收银台、入账、取消都在这里，网关回调与后台手动确认走同一个入账入口。
 *
 * 支付链路与套餐订单共用：收银台的绑定 / 手续费 / 支付会话规则在 CheckoutService，回调先经核心
 * PaymentController（验签、停用网关宽限、钩子），金额守卫 PaymentGuard 原生认充值单的应付额。
 *
 * 入账的不变量：
 * - 一张充值单只入账一次（行锁 + 锁内重读状态）；一笔网关流水只能入账一张单据（v2_payment_callback 去重）；
 * - 已取消的充值单收到真钱照样入账（充值不占任何资源，没有「开通不了」的情形），并通知管理员；
 * - 已到账的单又来一笔不同流水：有支付会话时按实付额退回余额，否则转人工，绝不静默吞掉。
 */
class RechargeService
{
    /** 待支付充值单多久没付就自动取消，与套餐订单一致 */
    public const PENDING_CANCEL_AFTER = OrderHandleJob::PENDING_CANCEL_AFTER;

    /** 每个用户一小时内最多新建多少张充值单（被新单替换掉的旧单也算）：挡住脚本刷单 */
    public const CREATE_LIMIT_PER_HOUR = 20;

    public function __construct(private readonly CheckoutService $checkoutService)
    {
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  下单 / 取消
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 新建充值单。同金额的待支付单直接复用（连点两次「立即充值」不会多出一张）；
     * 金额不同则把旧的待支付单作废——用户想改金额不必先手动取消。旧单的收款链接若已经付了钱，
     * 回调按「取消后到账」照样入账，不会丢钱。
     *
     * @param int $amount 充值金额（分）
     * @throws ApiException
     */
    public function create(User $user, int $amount): RechargeOrder
    {
        $config = RechargeConfig::fromSettings();
        if (!$config->enable) {
            throw new ApiException(__('Balance recharge is not available'));
        }
        if ($amount < $config->minAmount) {
            throw new ApiException(__('The minimum recharge amount is :amount', ['amount' => self::yuan($config->minAmount)]));
        }
        if ($amount > $config->maxAmount) {
            throw new ApiException(__('The maximum recharge amount is :amount', ['amount' => self::yuan($config->maxAmount)]));
        }

        return DB::transaction(function () use ($user, $amount, $config) {
            // 锁用户行：同一用户的并发下单串行化，「复用 / 作废旧单」的判断才不会两边各做一遍
            $locked = User::lockForUpdate()->find($user->id);
            if (!$locked) {
                throw new ApiException(__('The user does not exist'));
            }

            $pending = RechargeOrder::where('user_id', $locked->id)
                ->where('status', RechargeOrder::STATUS_PENDING)
                ->orderByDesc('id')
                ->get();
            $reusable = $pending->first(fn (RechargeOrder $r) => (int) $r->deposit_amount === $amount
                && (int) $r->created_at > time() - self::PENDING_CANCEL_AFTER);
            if ($reusable) {
                return $reusable;
            }

            $recent = RechargeOrder::where('user_id', $locked->id)
                ->where('created_at', '>=', time() - 3600)
                ->count();
            if ($recent >= self::CREATE_LIMIT_PER_HOUR) {
                throw new ApiException(__('Too many recharge orders, please try again later'));
            }

            if ($pending->isNotEmpty()) {
                RechargeOrder::whereIn('id', $pending->pluck('id'))
                    ->where('status', RechargeOrder::STATUS_PENDING)
                    ->update(['status' => RechargeOrder::STATUS_CANCELLED, 'updated_at' => time()]);
            }

            $bonus = $config->bonusFor($amount);
            return RechargeOrder::create([
                'user_id' => $locked->id,
                'trade_no' => Helper::generateOrderNo(),
                'deposit_amount' => $amount,
                'bonus_amount' => $bonus,
                'total_credit' => $amount + $bonus,
                'pay_amount' => $amount,
                'status' => RechargeOrder::STATUS_PENDING,
                'invite_user_id' => $locked->invite_user_id,
                'commission_balance' => 0,
            ]);
        });
    }

    /**
     * 取消待支付的充值单（用户主动取消、后台取消、超时自动取消）。条件更新而不是先查后写：
     * 与回调入账并发时，绝不能把刚入账的单改成已取消。
     */
    public function cancel(RechargeOrder $recharge): bool
    {
        return RechargeOrder::where('id', $recharge->id)
            ->where('status', RechargeOrder::STATUS_PENDING)
            ->update(['status' => RechargeOrder::STATUS_CANCELLED, 'updated_at' => time()]) === 1;
    }

    /** 超时未付的充值单批量取消，返回取消张数（check:order 每分钟调一次） */
    public function cancelExpired(?int $now = null): int
    {
        $now ??= time();
        return RechargeOrder::where('status', RechargeOrder::STATUS_PENDING)
            ->where('created_at', '<=', $now - self::PENDING_CANCEL_AFTER)
            ->update(['status' => RechargeOrder::STATUS_CANCELLED, 'updated_at' => $now]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  收银台
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 选定支付方式并向网关要收款地址。绑定 / 换支付方式 / 手续费规则与套餐订单相同（CheckoutService::mustRebind）。
     *
     * @param array{stripe_token?: string|null, return_url?: string|null} $extra
     * @return array{type: int, data: mixed}
     * @throws ApiException
     */
    public function checkout(RechargeOrder $recharge, ?Payment $payment, array $extra = []): array
    {
        if (!RechargeConfig::fromSettings()->enable) {
            throw new ApiException(__('Balance recharge is not available'));
        }
        if (!$payment || !$payment->enable) {
            throw new ApiException(__('Payment method is not available'));
        }
        // 订阅式网关会按周期反复扣款；自己接 webhook 的网关（StripeCheckout 等）回调只认套餐订单，付了也入不了账
        if (!CheckoutService::supportsRecharge($payment)) {
            throw new ApiException(__('This payment method cannot be used for balance recharge'));
        }

        $recharge = DB::transaction(function () use ($recharge, $payment) {
            $locked = RechargeOrder::where('id', $recharge->id)->lockForUpdate()->first();
            if (!$locked || (int) $locked->status !== RechargeOrder::STATUS_PENDING) {
                throw new ApiException(__('Order does not exist or has been paid'));
            }
            if ((int) $locked->deposit_amount <= 0) {
                throw new ApiException('充值金额异常，请重新下单');
            }

            if (CheckoutService::mustRebind($locked->payment_id === null ? null : (int) $locked->payment_id, $payment)) {
                $handling = CheckoutService::handlingFee($payment, (int) $locked->deposit_amount);
                $locked->payment_id = $payment->id;
                $locked->payment_method = (string) $payment->payment;
                $locked->handling_amount = $handling > 0 ? $handling : null;
                $locked->pay_amount = (int) $locked->deposit_amount + $handling;
                if (!$locked->save()) {
                    throw new ApiException(__('Request failed, please try again later'));
                }
            }
            if ((int) $locked->pay_amount <= 0) {
                throw new ApiException('充值应付金额异常，请重新下单');
            }
            return $locked;
        });

        return $this->checkoutService->requestPayment(
            $payment,
            PaymentSessions::SUBJECT_RECHARGE,
            (int) $recharge->id,
            (string) $recharge->trade_no,
            (int) $recharge->user_id,
            (int) $recharge->pay_amount,
            $extra
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  入账
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 已验签的网关回调落到这张充值单。PaymentController 在 v2_order 里找不到 trade_no 时调用。
     *
     * @param array $verify 网关插件 notify() 的返回（trade_no / callback_no，可选 paid_amount、attempt_ref）
     * @return bool true = 向网关 ACK；false = 拒收（网关会重投）
     */
    public function handleCallback(RechargeOrder $recharge, Payment $payment, string $callbackNo, array $verify): bool
    {
        $attemptRef = is_string($verify['attempt_ref'] ?? null) ? (string) $verify['attempt_ref'] : null;
        $attempt = PaymentSessions::findFor($attemptRef, PaymentSessions::SUBJECT_RECHARGE, (int) $recharge->id, (int) $payment->id);
        $paidMinor = is_numeric($verify['paid_amount'] ?? null) ? (int) $verify['paid_amount'] : null;

        // 网关绑定：充值单当初在哪条支付配置上发起，就只认它（或同一商户的别名配置）的回调，
        // 防止「持任一启用网关的密钥即可为任意 trade_no 标记已付」。命中支付会话时由会话把关：
        // 会话号不可枚举，且会话钉死了 payment_id（findFor 已复核）——用户切过支付方式后又付了旧通道
        // 的链接，正需要这条路，否则钱进商户号余额不到账。
        $bindMode = FeatureFlag::mode('payment_gateway_bind');
        if (!$attempt && $bindMode !== 'off'
            && !PaymentGatewayBinding::equivalent($recharge->payment_id === null ? null : (int) $recharge->payment_id, (int) $payment->id)) {
            PaymentMetrics::warn('webhook.payment_id_mismatch', [
                'subject' => 'recharge',
                'trade_no' => (string) $recharge->trade_no,
                'order_payment_id' => $recharge->payment_id,
                'callback_payment_id' => (int) $payment->id,
            ]);
            if ($bindMode === 'enforce') {
                return false;
            }
        }

        // 欠额：网关回传了实付额（BEpusdt 等）就必须够数。易支付一类不回传实付额的网关由插件里的
        // PaymentGuard 按应付额严格比对（PaymentGuard 认充值单），这里不重复。
        $expected = $attempt ? (int) $attempt->expected_amount : (int) $recharge->pay_amount;
        if ($paidMinor !== null && $paidMinor < $expected) {
            PaymentMetrics::warn('webhook.amount_mismatch', [
                'subject' => 'recharge',
                'trade_no' => (string) $recharge->trade_no,
                'expected' => $expected,
                'actual' => $paidMinor,
            ]);
            return false;
        }

        $outcome = $this->credit((int) $recharge->id, $callbackNo, $payment, $attempt);

        switch ($outcome) {
            case 'credited':
            case 'late':
                PaymentSessions::settle($attemptRef, $callbackNo);
                $recharge->refresh();
                $this->afterCredit($recharge, $payment, $outcome === 'late');
                return true;
            case 'adopted':
                PaymentSessions::settle($attemptRef, $callbackNo);
                $this->notifyAdopted($recharge->refresh(), $paidMinor);
                return true;
            case 'duplicate':
                return true;
            case 'second_payment':
                return $this->handleSecondPayment($recharge->refresh(), $payment, $callbackNo, $attemptRef, $paidMinor);
            case 'callback_conflict':
                PaymentMetrics::warn('webhook.callback_reused', [
                    'subject' => 'recharge',
                    'trade_no' => (string) $recharge->trade_no,
                    'payment_id' => (int) $payment->id,
                ]);
                return false;
            default:
                return false;
        }
    }

    /**
     * 后台手动确认到账（网关回调没进来、但已在商户后台核实收到钱）。待支付与已取消的单都能确认。
     *
     * @return string 'credited' | 'late' | 'duplicate' | 'second_payment' | 'missing'
     */
    public function confirmByAdmin(RechargeOrder $recharge, ?int $adminId): string
    {
        $outcome = $this->credit((int) $recharge->id, RechargeOrder::MANUAL_CALLBACK_NO, null, null, $adminId);
        if (in_array($outcome, ['credited', 'late'], true)) {
            $recharge->refresh();
            Log::info('[recharge] 管理员手动确认到账', [
                'trade_no' => (string) $recharge->trade_no,
                'user_id' => (int) $recharge->user_id,
                'total_credit' => (int) $recharge->total_credit,
                'admin_id' => $adminId,
            ]);
            $this->fireHooks($recharge, $recharge->payment);
        }
        return $outcome;
    }

    /**
     * 唯一的入账入口：把充值单翻成已到账并加余额，同一张单无论被调用多少次都只加一次钱。
     *
     * @param Payment|null $payment 网关回调所用的支付配置；手动确认传 null（不占流水去重表）
     * @param object|null  $attempt 认领到的支付会话：入账时把单据改绑到真正收到钱的通道与会话冻结的应付额
     * @return string 'credited'（本次入账）| 'late'（已取消的单本次入账）| 'adopted'（管理员已手动确认过，
     *                网关回调迟到：记下流水号，不再加钱）| 'duplicate'（同一流水重投）| 'second_payment'（已到账的单
     *                又来一笔不同流水）| 'callback_conflict'（这笔流水已入账过别的单据）| 'missing'
     * @throws \Throwable 写库失败时整个事务回滚，不会留下「单已到账、钱没加」的半套状态
     */
    private function credit(int $rechargeId, string $callbackNo, ?Payment $payment, ?object $attempt = null, ?int $operatorId = null): string
    {
        return DB::transaction(function () use ($rechargeId, $callbackNo, $payment, $attempt, $operatorId) {
            $locked = RechargeOrder::where('id', $rechargeId)->lockForUpdate()->first();
            if (!$locked) {
                return 'missing';
            }
            if ((int) $locked->status === RechargeOrder::STATUS_COMPLETED) {
                if (hash_equals((string) ($locked->callback_no ?? ''), $callbackNo)) {
                    return 'duplicate';
                }
                // 回调没进来、管理员先手动确认了，网关随后重投成功：这就是那一笔钱，认下流水号，绝不再加一次
                if ($payment !== null && $locked->callback_no === RechargeOrder::MANUAL_CALLBACK_NO) {
                    if (!$this->reserveCallback($locked, (int) $payment->id, $callbackNo)) {
                        return 'callback_conflict';
                    }
                    $locked->callback_no = $callbackNo;
                    $this->rebindTo($locked, $payment, $attempt);
                    if (!$locked->save()) {
                        throw new \RuntimeException('充值单状态写入失败');
                    }
                    return 'adopted';
                }
                return 'second_payment';
            }
            if ($payment !== null && !$this->reserveCallback($locked, (int) $payment->id, $callbackNo)) {
                return 'callback_conflict';
            }

            $late = (int) $locked->status === RechargeOrder::STATUS_CANCELLED;
            $locked->status = RechargeOrder::STATUS_COMPLETED;
            $locked->paid_at = time();
            $locked->callback_no = $callbackNo;
            if ($payment !== null) {
                $this->rebindTo($locked, $payment, $attempt);
            }
            if (!$locked->save()) {
                throw new \RuntimeException('充值单状态写入失败');
            }

            $remark = '充值 ¥' . self::yuan((int) $locked->deposit_amount)
                . ((int) $locked->bonus_amount > 0 ? '，赠送 ¥' . self::yuan((int) $locked->bonus_amount) : '')
                . ($late ? '（单据取消后到账）' : '')
                . ($operatorId !== null ? '（管理员确认到账）' : '');
            // addBalance 内部是 lockForUpdate + 读改写，依赖这里的事务才真正持锁
            $ok = (new UserService())->addBalance((int) $locked->user_id, (int) $locked->total_credit, BalanceLog::TYPE_RECHARGE, [
                'ref_type' => 'recharge',
                'ref_id' => (string) $locked->trade_no,
                'remark' => $remark,
                'operator_id' => $operatorId,
            ]);
            if (!$ok) {
                throw new \RuntimeException('余额入账失败');
            }

            return $late ? 'late' : 'credited';
        });
    }

    /**
     * 把单据记到真正收到钱的那条支付配置上（用户切过支付方式、或付的是同商户的另一个按钮）。
     * 事后核验、退款回查、对账都按 payment_id 去查网关、按应付额比对，记错通道就会把正常付款判成异常。
     * 命中支付会话时应付额取会话冻结的值（那才是报给这个网关的金额）。
     */
    private function rebindTo(RechargeOrder $recharge, Payment $payment, ?object $attempt): void
    {
        $recharge->payment_id = $payment->id;
        $recharge->payment_method = (string) $payment->payment;
        if ($attempt && (int) $attempt->expected_amount > 0) {
            $handling = max(0, (int) $attempt->expected_amount - (int) $recharge->deposit_amount);
            $recharge->handling_amount = $handling > 0 ? $handling : null;
            $recharge->pay_amount = (int) $recharge->deposit_amount + $handling;
        }
    }

    /** 同一笔网关流水只能入账一张单据（订单与充值单共用 v2_payment_callback）。须在事务内调用。 */
    private function reserveCallback(RechargeOrder $recharge, int $paymentId, string $callbackNo): bool
    {
        $fingerprint = hash('sha256', $paymentId . "\0" . $callbackNo);
        $existing = DB::table('v2_payment_callback')->where('fingerprint', $fingerprint)->lockForUpdate()->first();
        if ($existing) {
            return ($existing->subject_type ?? PaymentSessions::SUBJECT_ORDER) === PaymentSessions::SUBJECT_RECHARGE
                && (int) $existing->order_id === (int) $recharge->id;
        }
        DB::table('v2_payment_callback')->insert([
            'fingerprint' => $fingerprint,
            'payment_id' => $paymentId,
            'order_id' => (int) $recharge->id,
            'trade_no' => (string) $recharge->trade_no,
            'subject_type' => PaymentSessions::SUBJECT_RECHARGE,
            'created_at' => time(),
        ]);
        return true;
    }

    /**
     * 已到账的充值单又收到一笔不同流水的钱：用户切过支付方式、两条收款链接都付了。
     * 有支付会话时交给会话层按实付额退回余额（重投它自己会判成 noop）；没有会话就无从知道这笔钱
     * 是不是真的另付了一次，转人工。两种情况都向网关 ACK——钱已经在商户号里了，重投没有意义。
     */
    private function handleSecondPayment(RechargeOrder $recharge, Payment $payment, string $callbackNo, ?string $attemptRef, ?int $paidMinor): bool
    {
        $attempt = PaymentSessions::findFor($attemptRef, PaymentSessions::SUBJECT_RECHARGE, (int) $recharge->id, (int) $payment->id);
        $settled = $attempt && in_array((int) $attempt->status, [PaymentSessions::ATTEMPT_PAID, PaymentSessions::ATTEMPT_REFUNDED], true);
        if ($settled && hash_equals((string) ($attempt->callback_no ?? ''), $callbackNo)) {
            return true; // 这条会话收过的那一笔（已入账 / 已退余额），网关重投
        }
        // 已经收过钱的会话又来一笔不同流水：插件会把它当成重投放过，这里转人工
        $result = $settled ? 'session_paid_twice' : PaymentSessions::creditDuplicate($attemptRef, $callbackNo, $paidMinor, $recharge->callback_no);
        if (in_array($result, ['noop', 'refunded'], true)) {
            return true;
        }

        $context = [
            'trade_no' => (string) $recharge->trade_no,
            'user_id' => (int) $recharge->user_id,
            'payment_id' => (int) $payment->id,
            'callback_no' => $callbackNo,
            'first_callback_no' => (string) $recharge->callback_no,
            'paid_amount' => $paidMinor,
            'reason' => $result,
        ];
        PaymentMetrics::warn('recharge.second_payment.manual_review', $context);
        Log::error('[recharge] 已到账的充值单又收到一笔付款，无法自动处理，需人工核实后补余额或退款', $context);
        AdminAlert::send(
            "⚠️ 充值单重复付款需人工处理\n"
            . "充值单：{$recharge->trade_no}\n"
            . "用户ID：{$recharge->user_id}\n"
            . '本笔金额：' . ($paidMinor !== null ? '¥' . self::yuan($paidMinor) : '网关未回传') . "\n"
            . "本笔流水：{$callbackNo}\n"
            . "首笔流水：{$recharge->callback_no}\n"
            . '这张单已经入账过一次。请到商户后台核实：确是另付的一笔，就在后台给用户补余额或在商户后台退款。',
            'markdown',
            true
        );
        return true;
    }

    /**
     * 管理员手动确认过的充值单收到了网关回调：按同一笔钱处理（不再加余额）。用户也可能是真付了两次
     *（比如手动确认的依据是线下转账），所以告诉管理员去商户后台核对一下。
     */
    private function notifyAdopted(RechargeOrder $recharge, ?int $paidMinor): void
    {
        PaymentMetrics::inc('recharge.manual_confirmed.callback_adopted');
        Log::info('[recharge] 手动确认过的充值单收到网关回调，已记为同一笔', [
            'trade_no' => (string) $recharge->trade_no,
            'callback_no' => (string) $recharge->callback_no,
        ]);
        AdminAlert::send(
            "ℹ️ 手动确认过的充值单收到了网关回调\n"
            . "充值单：{$recharge->trade_no}\n"
            . "用户ID：{$recharge->user_id}\n"
            . '本笔金额：' . ($paidMinor !== null ? '¥' . self::yuan($paidMinor) : '网关未回传') . "\n"
            . "回调流水：{$recharge->callback_no}\n"
            . '已按同一笔钱处理，没有重复入账。若手动确认时依据的是另一笔付款（用户付了两次），请到商户后台核对后给用户补余额或退款。'
        );
    }

    private function afterCredit(RechargeOrder $recharge, Payment $payment, bool $late): void
    {
        Log::info('[recharge] 充值到账', [
            'trade_no' => (string) $recharge->trade_no,
            'user_id' => (int) $recharge->user_id,
            'deposit' => (int) $recharge->deposit_amount,
            'bonus' => (int) $recharge->bonus_amount,
            'total_credit' => (int) $recharge->total_credit,
            'payment_id' => (int) $payment->id,
            'late' => $late,
        ]);
        if ($late) {
            PaymentMetrics::inc('recharge.late_paid.credited');
            AdminAlert::send(
                "✅ 已取消的充值单收到付款，已自动入账\n"
                . "充值单：{$recharge->trade_no}\n"
                . "用户ID：{$recharge->user_id}\n"
                . '入账：¥' . self::yuan((int) $recharge->total_credit) . "\n"
                . "回调流水：{$recharge->callback_no}"
            );
        }
        $this->fireHooks($recharge, $payment);
    }

    /** 收款通知等钩子。钩子异常绝不能影响已提交的入账，吞掉记日志。 */
    private function fireHooks(RechargeOrder $recharge, ?Payment $payment): void
    {
        try {
            HookManager::call('recharge.credited', $recharge);
            // 与订单共用收款通知钩子；载荷不是 Order，监听方按对象字段取值（Telegram 插件已兼容）
            HookManager::call('payment.notify.success', (object) [
                'trade_no' => (string) $recharge->trade_no,
                'total_amount' => (int) $recharge->pay_amount,
                'user_id' => (int) $recharge->user_id,
                'type' => '余额充值',
                'payment' => $payment,
            ]);
        } catch (\Throwable $e) {
            Log::error('[recharge] 到账钩子异常', ['trade_no' => (string) $recharge->trade_no, 'error' => $e->getMessage()]);
        }
    }

    public static function yuan(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }
}
