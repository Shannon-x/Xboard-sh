<?php

namespace App\Services\Billing;

use App\Exceptions\ApiException;
use App\Models\BillingDocument;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\OrderService;
use App\Services\RenewService;
use App\Services\UserService;
use Illuminate\Support\Facades\DB;

/**
 * 续费账单的免登录付款：邮件里的按钮打开 /pay/<账单凭据>，不用密码就能把这一张账单付掉。
 *
 * 凭据只对应一张账单（见 BillingDocument::payToken），页面上只露出这张账单本身的信息（套餐、周期、金额、
 * 打码的邮箱），拿到链接的人除了替这张账单付款什么也做不了：看不到订单列表，改不了资料，动不了别的订单。
 * 付清、用别的方式续过费、或到期后超过 billing_pay_link_days 天，链接就作废。
 *
 * 下单走和登录后的一键续费完全相同的 OrderService::createFromRequest（按上次规格续费、余额自动抵扣、
 * 不叠加优惠券），只是省掉了登录；收款那一步共用 CheckoutService。创建的订单记在账单的 pay_order_id 上，
 * 再打开链接就接着付同一单，不会攒出一堆待付单。
 */
final class BillingPayService
{
    public const STATE_PAYABLE = 'payable';          // 可以付：还没下单，或上一单已取消
    public const STATE_PENDING = 'pending';          // 这张账单的订单已建、还没付（接着付或取消）
    public const STATE_PAID = 'paid';                // 这张账单的订单已付款（开通中 / 已完成）
    public const STATE_SETTLED = 'settled';          // 用别的方式续过费了，账单无需再付
    public const STATE_EXPIRED = 'expired';          // 到期后超过宽限天数（或账单已作废），链接失效
    public const STATE_UNAVAILABLE = 'unavailable';  // 站点关了免登录付款 / 账号或套餐现状不能按这张账单续费
    public const STATE_BLOCKED = 'blocked';          // 账号另有一笔待付订单，得先登录处理那一笔

    private const TOKEN_PATTERN = '/^(\d{1,12})-([a-f0-9]{32})$/';

    public static function enabled(): bool
    {
        return (bool) (int) admin_setting('billing_pay_link_enable', 1);
    }

    /** 到期后链接还能用的天数（0 = 到期即失效） */
    public static function graceDays(): int
    {
        return max(0, min(30, (int) admin_setting('billing_pay_link_days', 7)));
    }

    /** 到期后超过宽限天数：链接作废，不管付没付 */
    public static function lapsed(BillingDocument $doc): bool
    {
        return time() > (int) $doc->expired_at + self::graceDays() * 86400;
    }

    /** 邮件 / PDF 里能不能放这张账单的付款链接：开关开着、是续费账单、还没过宽限期 */
    public static function linkable(BillingDocument $doc): bool
    {
        return self::enabled() && $doc->kind === BillingDocument::KIND_INVOICE && !self::lapsed($doc);
    }

    public static function url(BillingDocument $doc): string
    {
        return rtrim((string) admin_setting('app_url', ''), '/') . $doc->payPath();
    }

    /** 凭据 → 账单。签名不对、不是续费账单都当不存在：没有签名的人连「这个 id 存在」都问不出来 */
    public function resolve(string $token): ?BillingDocument
    {
        if (!preg_match(self::TOKEN_PATTERN, $token, $m)) {
            return null;
        }
        $doc = BillingDocument::find((int) $m[1]);
        if (!$doc || $doc->kind !== BillingDocument::KIND_INVOICE || !hash_equals($doc->paySignature(), $m[2])) {
            return null;
        }
        return $doc;
    }

    /**
     * 这张账单现在处于什么状态（每次请求都按账号现状重算，链接本身不记状态）。
     * @return array{state: string, reason: ?string, message: ?string, user: ?User, order: ?Order, spec: ?array}
     */
    public function state(BillingDocument $doc): array
    {
        $at = fn (string $state, ?string $reason = null, ?string $message = null, ?User $user = null, ?Order $order = null, ?array $spec = null) =>
            compact('state', 'reason', 'message', 'user', 'order', 'spec');

        $user = User::find($doc->user_id);
        if (!$user || $user->banned) {
            return $at(self::STATE_UNAVAILABLE, 'account');
        }
        $order = $doc->pay_order_id ? Order::find($doc->pay_order_id) : null;
        if ($order && in_array((int) $order->status, [Order::STATUS_PROCESSING, Order::STATUS_COMPLETED, Order::STATUS_DISCOUNTED], true)) {
            return $at(self::STATE_PAID, null, null, $user, $order);
        }
        if ($order && (int) $order->status !== Order::STATUS_PENDING) {
            $order = null;   // 取消了（手动 / 超时回收）的单不算，可以重新下
        }
        $status = $doc->statusFor($user);
        if ($status === 'settled') {
            return $at(self::STATE_SETTLED, null, null, $user, $order);
        }
        if ($status === 'void' || self::lapsed($doc)) {
            return $at(self::STATE_EXPIRED, null, null, $user, $order);
        }
        if (!self::enabled()) {
            return $at(self::STATE_UNAVAILABLE, 'disabled', null, $user, $order);
        }
        if ($order) {
            return $at(self::STATE_PENDING, null, null, $user, $order);
        }
        if (app(UserService::class)->isNotCompleteOrderByUserId($user->id)) {
            return $at(self::STATE_BLOCKED, 'other_order', null, $user);
        }
        $spec = app(RenewService::class)->resolveSpec($user);
        if (!$spec['available']) {
            return $at(self::STATE_UNAVAILABLE, 'spec', (string) $spec['message'], $user);
        }
        // 账单开出后换了套餐（后台改的，到期日没变）：按这张账单续的已经不是用户现在的套餐，不能照付
        $billedPlan = (int) ($doc->payload['plan_id'] ?? 0);
        if ($billedPlan && $billedPlan !== (int) $spec['plan_id']) {
            return $at(self::STATE_UNAVAILABLE, 'plan_changed', null, $user);
        }
        return $at(self::STATE_PAYABLE, null, null, $user, null, $spec);
    }

    /** 付款页要的一切：状态、账单摘要、现价与余额抵扣、已建订单、可用支付方式 */
    public function view(BillingDocument $doc): array
    {
        $s = $this->state($doc);
        $user = $s['user'];
        $payload = is_array($doc->payload) ? $doc->payload : [];
        $out = [
            'state' => $s['state'],
            'reason' => $s['reason'],
            'message' => $s['message'],
            'bill' => [
                'doc_no' => (string) $doc->doc_no,
                'stage' => $doc->stage,
                'plan_name' => (string) ($payload['plan_name'] ?? ''),
                'period' => (string) ($payload['items'][0]['period'] ?? ''),
                'details' => array_values((array) ($payload['items'][0]['details'] ?? [])),
                'amount' => (int) $doc->amount,
                'expired_at' => (int) $doc->expired_at,
                'issued_at' => (int) $doc->created_at,
                'locale' => (string) $doc->locale,
            ],
            'email' => $user ? self::maskEmail((string) $user->email) : null,
            'pay_until' => (int) $doc->expired_at + self::graceDays() * 86400,
            'quote' => null,
            'order' => $s['order'] ? self::orderInfo($s['order']) : null,
            'methods' => [],
        ];
        if (in_array($s['state'], [self::STATE_PAYABLE, self::STATE_PENDING], true)) {
            $out['methods'] = $this->methods();
        }
        if ($s['state'] === self::STATE_PAYABLE) {
            $spec = $s['spec'];
            $balance = (int) ($user->balance ?? 0);
            $applied = min($balance, (int) $spec['amount']);
            $out['quote'] = [
                'plan_name' => (string) $spec['plan_name'],
                'period' => (string) $spec['period'],
                'period_name' => (string) $spec['period_name'],
                'amount' => (int) $spec['amount'],
                'list_amount' => (int) $spec['list_amount'],
                'discount' => (int) $spec['discount'],
                'balance' => $balance,
                'balance_applied' => $applied,
                'cash_due' => (int) $spec['amount'] - $applied,
                'summary' => $spec['summary'],
            ];
        }
        return $out;
    }

    /** 付款页 4 秒一拍的轮询：只要状态和订单状态 */
    public function check(BillingDocument $doc): array
    {
        $s = $this->state($doc);
        return [
            'state' => $s['state'],
            'order_status' => $s['order'] ? (int) $s['order']->status : null,
        ];
    }

    /**
     * 下单（第一次）或接着付这张账单的待付单，然后走收银台核心拿收款地址。
     * @param int|null $expectedAmount 页面上看到的应付金额：和此刻的报价对不上就拒绝，不悄悄按新价下单
     * @return array{type: int, data: mixed, trade_no: string}
     * @throws ApiException
     */
    public function checkout(BillingDocument $doc, ?int $methodId, ?int $expectedAmount = null): array
    {
        $s = $this->state($doc);
        if ($s['state'] === self::STATE_PENDING) {
            $order = $s['order'];
        } elseif ($s['state'] === self::STATE_PAYABLE) {
            if ($expectedAmount !== null && $expectedAmount !== (int) $s['spec']['amount']) {
                throw new ApiException('套餐价格已更新，请刷新后再确认');
            }
            $order = $this->createOrder($doc, $s['user'], $s['spec']);
        } else {
            throw new ApiException($this->stateMessage($doc, $s));
        }
        $payment = $methodId !== null ? Payment::where('id', $methodId)->where('enable', 1)->first() : null;
        if ($payment && CheckoutService::isSubscriptionGateway($payment)) {
            throw new ApiException(__('Payment method is not available'));
        }
        $result = app(CheckoutService::class)->checkout($order, $payment, ['return_url' => self::url($doc)]);
        return $result + ['trade_no' => (string) $order->trade_no];
    }

    /** 只能取消这张账单自己的待付单；退回余额抵扣的那部分 */
    public function cancel(BillingDocument $doc): bool
    {
        $order = $this->state($doc)['order'];
        if (!$order || (int) $order->status !== Order::STATUS_PENDING) {
            throw new ApiException(__('You can only cancel pending orders'));
        }
        return (new OrderService($order))->cancel();
    }

    private function createOrder(BillingDocument $doc, User $user, array $spec): Order
    {
        $plan = Plan::find($spec['plan_id']);
        if (!$plan) {
            throw new ApiException('套餐已不存在');
        }
        return DB::transaction(function () use ($doc, $user, $plan, $spec) {
            $order = OrderService::createFromRequest($user, $plan, $spec['period'], null, $spec['options'], null);
            BillingDocument::where('id', $doc->id)->update(['pay_order_id' => $order->id]);
            return $order;
        });
    }

    /** 可用的支付方式；订阅式网关（Stripe / PayPal Subscription）会在网关那边开周期扣款，一次性账单不用 */
    private function methods(): array
    {
        return Payment::select(['id', 'name', 'payment', 'icon', 'handling_fee_fixed', 'handling_fee_percent'])
            ->where('enable', 1)->orderBy('sort', 'ASC')->get()
            ->reject(fn (Payment $p) => CheckoutService::isSubscriptionGateway($p))
            ->map(fn (Payment $p) => [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'payment' => (string) $p->payment,
                'icon' => $p->icon,
                'handling_fee_fixed' => (int) ($p->handling_fee_fixed ?? 0),
                'handling_fee_percent' => (float) ($p->handling_fee_percent ?? 0),
            ])->values()->all();
    }

    private static function orderInfo(Order $order): array
    {
        return [
            'trade_no' => (string) $order->trade_no,
            'status' => (int) $order->status,
            'total_amount' => (int) $order->total_amount,
            'balance_amount' => (int) ($order->balance_amount ?? 0),
            'discount_amount' => (int) ($order->discount_amount ?? 0),
            'surplus_amount' => (int) ($order->surplus_amount ?? 0),
            'handling_amount' => (int) ($order->handling_amount ?? 0),
            'payment_id' => $order->payment_id !== null ? (int) $order->payment_id : null,
            'created_at' => (int) $order->created_at,
        ];
    }

    /** 不能付时给前端的一句话，按账单开具时的语言 */
    private function stateMessage(BillingDocument $doc, array $s): string
    {
        return BillingDocumentService::withLocale(fn () => $s['message'] ?: __('billing.pay.' . $s['state']), $doc->locale ?: null);
    }

    /** 页面上只认得出是自己的邮箱就够了：shan***@example.com */
    public static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return $email === '' ? '' : mb_substr($email, 0, 1) . '***';
        }
        $local = substr($email, 0, $at);
        $keep = mb_strlen($local) >= 4 ? 2 : 1;
        return mb_substr($local, 0, $keep) . '***' . substr($email, $at);
    }
}
