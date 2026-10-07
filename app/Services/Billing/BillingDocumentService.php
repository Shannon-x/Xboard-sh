<?php

namespace App\Services\Billing;

use App\Models\BillingDocument;
use App\Models\CommissionWithdrawal;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\Commission\WithdrawalConfig;
use App\Services\PlanCustomizationService;
use App\Services\RenewService;
use Illuminate\Support\Facades\App;
use Mpdf\Mpdf;

/**
 * 收据 / 续费账单 / 提现结果：组装数据 → 渲染邮件正文与 PDF。
 *
 * 模板在 resources/views/billing/，刻意不放 resources/views/mail/：生产上那个目录被宿主机挂载覆盖，
 * 放进去的新模板不会生效，也不该受 email_template 主题切换影响。视觉沿用 editorial 的米色纸感 + 衬线标题。
 *
 * 金额全部是「分」。字体是仓库内子集化的 Noto Sans SC / Noto Serif SC（GB2312 + Big5 常用字），
 * PDF 只嵌入用到的字形，单份几十 KB，远低于 OCI Email Delivery 默认 2MB 的整封邮件上限。
 */
class BillingDocumentService
{
    public const STAGE_FIRST = 'first';
    public const STAGE_FINAL = 'final';

    public const LOCALES = ['zh-CN', 'zh-TW', 'en-US'];

    /** Crockford base32：去掉 I、L、O、U，口头报编号时不会把 1 和 I、0 和 O 弄混 */
    private const NUMBER_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * 对外编号：前缀 + 日期 + 8 位码，如 RC-20261007-7KQ2M9XA。
     *
     * 8 位码是 app key 对单据身份（哪张订单的收据 / 哪位用户哪个到期日的账单）做的 HMAC，
     * 不是自增号：别人拿到一张收据看不出站点有多少订单、多少用户。同一份单据每次算出来都一样，
     * 所以 24 小时档的账单沿用 7 天档的编号，不用先查库占号。40 位空间，同一天里撞号的概率可以忽略。
     */
    public static function documentNumber(string $prefix, int $date, string $identity): string
    {
        $hash = hash_hmac('sha256', $identity, (string) config('app.key'), true);
        $bits = 0;
        foreach (str_split(substr($hash, 0, 5)) as $byte) {
            $bits = ($bits << 8) | ord($byte);
        }
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code = self::NUMBER_ALPHABET[$bits & 31] . $code;
            $bits >>= 5;
        }
        return sprintf('%s-%s-%s', $prefix, date('Ymd', $date), $code);
    }

    public static function receiptEnabled(): bool
    {
        return (bool) (int) admin_setting('billing_receipt_enable', 1);
    }

    /** 到期前几天发首张续费账单；0 或 1 = 不发提前账单，只保留到期前 24 小时的最后提醒。 */
    public static function invoiceDays(): int
    {
        return max(0, min(30, (int) admin_setting('billing_invoice_days', 7)));
    }

    /** 到期前 24 小时的最后提醒是否改用带账单 PDF 的新模板；关掉则回落旧的 remindExpire。 */
    public static function invoiceEnabled(): bool
    {
        return (bool) (int) admin_setting('billing_invoice_enable', 1);
    }

    /** 到期当天的「服务已暂停」通知 */
    public static function expiredEnabled(): bool
    {
        return (bool) (int) admin_setting('billing_expired_enable', 1);
    }

    /** 已续费 / 已失效的账单记录保留多少天（按到期日算），0 = 永久；收据始终保留。 */
    public static function invoiceRetentionDays(): int
    {
        return max(0, min(3650, (int) admin_setting('billing_invoice_retention_days', 0)));
    }

    public static function winbackEnabled(): bool
    {
        return (bool) (int) admin_setting('billing_winback_enable', 1);
    }

    /** 到期后第几天发挽回邮件：升序、去重、1–365 天，最多 3 档（对应 lifecycle_stage 2–4）。 */
    public static function winbackDays(): array
    {
        $days = array_map('intval', explode(',', (string) admin_setting('billing_winback_days', '7,30')));
        $days = array_values(array_unique(array_filter($days, fn ($d) => $d >= 1 && $d <= 365)));
        sort($days);
        return array_slice($days, 0, 3);
    }

    /** 用户表没有语言字段，收据 / 账单按站点统一语言出；默认跟 app.locale（zh-CN）。 */
    public static function locale(): string
    {
        $locale = (string) admin_setting('billing_locale', config('app.locale', 'zh-CN'));
        return in_array($locale, self::LOCALES, true) ? $locale : 'zh-CN';
    }

    /**
     * 在账单语言下执行，结束后恢复（队列 worker 常驻，不能把 locale 留给下一个任务）。
     * 传 $locale 则用它（按快照重新生成时用开具时的语言，表头标签才和快照里的文案对得上）。
     */
    public static function withLocale(callable $fn, ?string $locale = null)
    {
        $previous = App::getLocale();
        App::setLocale($locale !== null && in_array($locale, self::LOCALES, true) ? $locale : self::locale());
        try {
            return $fn();
        } finally {
            App::setLocale($previous);
        }
    }

    // ---------------------------------------------------------------- 收据

    /** 已完成订单的收据数据；免费单（实付 0）或用户没邮箱时返回 null，不发收据。 */
    public function receipt(Order $order): ?array
    {
        $order->loadMissing(['user', 'payment']);
        $user = $order->user;
        if (!$user || !$user->email) return null;

        $cash = max(0, (int) $order->total_amount);
        $balance = max(0, (int) ($order->balance_amount ?? 0));
        $discount = max(0, (int) ($order->discount_amount ?? 0));
        $surplus = max(0, (int) ($order->surplus_amount ?? 0));
        $handling = max(0, (int) ($order->handling_amount ?? 0));
        if ($cash + $balance + $handling <= 0) return null;

        $subtotal = $cash + $balance + $discount + $surplus;
        $total = $subtotal - $discount - $surplus + $handling;
        $paidAt = (int) ($order->paid_at ?: $order->updated_at ?: time());
        $isAuto = !empty($order->auto_renew);

        $payments = [];
        if ($balance > 0) {
            $payments[] = ['label' => __('billing.field.paid_balance'), 'method' => __('billing.payment.balance'), 'amount' => $balance];
        }
        if ($cash + $handling > 0) {
            $payments[] = ['label' => __('billing.field.paid_online'), 'method' => $order->payment?->name ?: '—', 'amount' => $cash + $handling];
        }

        $plan = Plan::find($order->plan_id);
        $data = $this->base() + [
            'kind' => 'receipt',
            'doc_title' => __('billing.receipt.doc_title'),
            'doc_title_en' => __('billing.receipt.doc_title_en'),
            'stamp' => __('billing.receipt.stamp'),
            'stamp_soft' => false,
            'doc_no' => self::documentNumber('RC', $paidAt, 'receipt:' . $order->id),
            'trade_no' => (string) $order->trade_no,
            'issued_at' => $this->date(time()),
            'paid_at' => $this->dateTime($paidAt),
            'order_type' => __('billing.type.' . (int) $order->type),
            'payment_method' => implode(' + ', array_column($payments, 'method')),
            'bill_to' => ['email' => $user->email, 'id' => (int) $user->id],
            'items' => [$this->orderItem($order, $plan, $user)],
            'totals' => array_values(array_filter([
                ['label' => __('billing.field.subtotal'), 'amount' => $subtotal],
                $discount > 0 ? ['label' => __('billing.field.discount'), 'amount' => -$discount] : null,
                $surplus > 0 ? ['label' => __('billing.field.surplus'), 'amount' => -$surplus] : null,
                $handling > 0 ? ['label' => __('billing.field.handling'), 'amount' => $handling] : null,
            ])),
            'total' => $total,
            'payments' => $payments,
            'balance_due' => 0,
            'notes' => array_values(array_filter([
                $isAuto ? __('billing.receipt.auto_renew_note') : null,
                (int) ($order->refund_amount ?? 0) > 0 ? __('billing.receipt.refund_note', ['amount' => $this->money((int) $order->refund_amount)]) : null,
            ])),
            'headline' => __('billing.receipt.headline'),
            'intro' => __('billing.receipt.intro'),
            'cta_label' => __('billing.receipt.cta'),
            'cta_url' => $this->url('/dashboard'),
            'has_pdf' => true,
            'archive_note' => true,
            'alternatives' => [],
        ];
        $data['subject'] = __('billing.receipt.subject', ['no' => $data['doc_no'], 'app' => $data['app_name']]);
        $data['meta'] = [
            [__('billing.field.receipt_no'), $data['doc_no']],
            [__('billing.field.trade_no'), $data['trade_no']],
            [__('billing.field.paid_at'), $data['paid_at']],
            [__('billing.field.payment_method'), $data['payment_method']],
            [__('billing.field.order_type'), $data['order_type']],
        ];
        return $this->decorate($data);
    }

    private function orderItem(Order $order, ?Plan $plan, User $user): array
    {
        $period = (string) $order->period;
        $snapshot = (array) ($order->plan_snapshot ?? []);
        $name = (string) ($snapshot['name'] ?? $plan?->name ?? ('#' . $order->plan_id));

        if ($period === Plan::PERIOD_TRAFFIC_TOPUP) {
            $details = isset($snapshot['topup_gb']) ? [__('billing.field.traffic') . ' +' . (int) $snapshot['topup_gb'] . ' GB'] : [];
        } elseif ($period === Plan::PERIOD_RESET_TRAFFIC) {
            $details = [];
        } else {
            $options = $snapshot['options'] ?? null;
            $pick = fn (string $key) => is_array($options) && array_key_exists($key, $options) ? $options[$key] : $plan?->{$key};
            $details = $this->specLines($pick('transfer_enable'), $pick('device_limit'), $pick('speed_limit'),
                $plan ? array_column(app(PlanCustomizationService::class)->addonGroupsForUser($plan, $user), 'name') : []);
        }

        $periodic = !in_array($period, [Plan::PERIOD_ONETIME, Plan::PERIOD_RESET_TRAFFIC, Plan::PERIOD_TRAFFIC_TOPUP], true);
        return [
            'name' => $name,
            'period' => $this->periodName($period),
            'details' => $details,
            'until' => $periodic && $user->expired_at ? $this->date((int) $user->expired_at) : null,
            'amount' => max(0, (int) $order->total_amount) + max(0, (int) ($order->balance_amount ?? 0))
                + max(0, (int) ($order->discount_amount ?? 0)) + max(0, (int) ($order->surplus_amount ?? 0)),
        ];
    }

    // ---------------------------------------------------------------- 续费账单

    /**
     * 到期前续费账单。spec 不可续（套餐下架 / 不允许续费…）时 has_pdf=false：
     * 邮件只引导换套餐、不附 PDF —— 一张没有应付金额的账单没有意义。
     */
    public function invoice(User $user, string $stage): array
    {
        $renew = app(RenewService::class);
        $spec = $renew->resolveSpec($user);
        $auto = $renew->autoState($user, $spec);
        $expiredAt = (int) $user->expired_at;
        $days = max(1, (int) ceil(($expiredAt - time()) / 86400));
        $plan = Plan::find($spec['plan_id'] ?? $user->plan_id);
        $planName = (string) ($spec['plan_name'] ?? ($plan?->name ?? ''));

        $autoCovered = $spec['available'] && $auto['enabled'] && $auto['site_enabled'] && $auto['balance_enough'];
        $autoShort = $spec['available'] && $auto['enabled'] && $auto['site_enabled'] && !$auto['balance_enough'];

        $data = $this->base() + [
            'kind' => 'invoice',
            'stage' => $stage,
            'doc_title' => __('billing.invoice.doc_title'),
            'doc_title_en' => __('billing.invoice.doc_title_en'),
            'stamp' => $autoCovered ? __('billing.invoice.stamp_auto') : __('billing.invoice.stamp_unpaid'),
            'stamp_soft' => $autoCovered,
            'has_pdf' => (bool) $spec['available'],
            'archive_note' => (bool) $spec['available'],
            'auto_covered' => $autoCovered,
            'doc_no' => self::documentNumber('INV', $expiredAt, 'invoice:' . $user->id . ':' . $expiredAt),
            'issued_at' => $this->date(time()),
            'due_at' => $this->dateTime($expiredAt),
            'due_date' => $this->date($expiredAt),
            'days' => $days,
            'plan_name' => $planName,
            'bill_to' => ['email' => $user->email, 'id' => (int) $user->id],
            'alternatives' => $this->alternatives($user, $plan),
            'browse_url' => $this->url('/plans'),
            'headline' => $stage === self::STAGE_FINAL
                ? __('billing.invoice.headline_final')
                : __('billing.invoice.headline_first', ['days' => $days]),
            'meta' => [],
            'items' => [],
            'totals' => [],
            'total' => 0,
            'payments' => [],
            'balance_due' => 0,
            'notes' => [],
        ];

        if (!$spec['available']) {
            $data['subject'] = __('billing.invoice.subject_unavailable', ['app' => $data['app_name']]);
            $data['intro'] = __('billing.invoice.intro_unavailable', ['reason' => $spec['message']]);
            $data['cta_label'] = __('billing.invoice.cta_browse');
            $data['cta_url'] = $data['browse_url'];
            $data['meta'] = [
                [__('billing.field.invoice_no'), $data['doc_no']],
                [__('billing.field.expires_at'), $data['due_at']],
            ];
            return $this->decorate($data);
        }

        $list = (int) $spec['list_amount'];
        $amount = (int) $spec['amount'];
        $data = array_replace($data, [
            'items' => [[
                'name' => $planName,
                'period' => $this->periodName($spec['period']),
                'details' => $this->specLines($spec['summary']['transfer_enable'], $spec['summary']['device_limit'],
                    $spec['summary']['speed_limit'], $spec['summary']['addon_names']),
                'until' => null,
                'amount' => $list,
            ]],
            'totals' => array_values(array_filter([
                ['label' => __('billing.field.subtotal'), 'amount' => $list],
                $list > $amount ? ['label' => __('billing.field.vip_discount', ['pct' => (int) $spec['discount']]), 'amount' => $amount - $list] : null,
            ])),
            'total' => $amount,
            'balance_due' => $amount,
            'account_balance' => (int) $auto['balance'],
            'meta' => [
                [__('billing.field.invoice_no'), $data['doc_no']],
                [__('billing.field.issued_at'), $data['issued_at']],
                [__('billing.field.due_at'), $data['due_at']],
                [__('billing.field.expires_at'), $data['due_at']],
            ],
            'subject' => $stage === self::STAGE_FINAL
                ? __('billing.invoice.subject_final', ['plan' => $planName, 'app' => $data['app_name']])
                : __('billing.invoice.subject_first', ['no' => $data['doc_no'], 'plan' => $planName, 'date' => $data['due_date'], 'app' => $data['app_name']]),
            'intro' => $autoCovered
                ? __('billing.invoice.intro_auto')
                : ($autoShort
                    ? __('billing.invoice.intro_auto_short', ['amount' => $this->money((int) $auto['shortfall'])])
                    : __('billing.invoice.intro')),
            'cta_label' => $autoCovered ? __('billing.invoice.cta_auto') : __('billing.invoice.cta'),
            'cta_url' => $autoCovered ? $this->url('/dashboard') : $this->url('/plans?mode=renew'),
        ]);
        return $this->decorate($data);
    }

    /**
     * 「也可以看看」：后台 billing_recommend_plan_ids（逗号分隔）优先；
     * 没配就挑在售套餐里月均价最接近当前套餐的 3 个。
     */
    public function alternatives(User $user, ?Plan $current): array
    {
        $configured = array_values(array_filter(array_map('intval', explode(',', (string) admin_setting('billing_recommend_plan_ids', '')))));
        $query = Plan::where('show', true)->where('sell', true)->where('id', '!=', (int) $user->plan_id);
        if ($configured) $query->whereIn('id', $configured);
        $candidates = [];
        foreach ($query->orderBy('sort')->get() as $plan) {
            $best = $this->cheapestMonthly($plan);
            if ($best === null) continue;
            $candidates[] = ['plan' => $plan, 'monthly' => $best['monthly']];
        }
        if ($configured) {
            usort($candidates, fn ($a, $b) => array_search($a['plan']->id, $configured) <=> array_search($b['plan']->id, $configured));
        } else {
            $anchor = $current ? ($this->cheapestMonthly($current)['monthly'] ?? null) : null;
            if ($anchor !== null) {
                usort($candidates, fn ($a, $b) => abs($a['monthly'] - $anchor) <=> abs($b['monthly'] - $anchor));
            }
        }
        return array_map(fn ($c) => [
            'name' => (string) $c['plan']->name,
            'price' => __('billing.invoice.from_price', ['price' => $this->money($c['monthly']), 'period' => __('billing.period.unit_monthly')]),
            'details' => $this->specLines($c['plan']->transfer_enable, $c['plan']->device_limit, $c['plan']->speed_limit, []),
            'url' => $this->url('/plans?plan=' . $c['plan']->id),
        ], array_slice($candidates, 0, 3));
    }

    /** 最划算周期折算的月均价（分）。只看周期价，不算一次性 / 重置包。 */
    private function cheapestMonthly(Plan $plan): ?array
    {
        $best = null;
        foreach (Plan::getAvailablePeriods() as $key => $meta) {
            $price = ($plan->prices ?? [])[$key] ?? null;
            if (!is_numeric($price) || $price <= 0 || in_array($key, [Plan::PERIOD_ONETIME, Plan::PERIOD_RESET_TRAFFIC], true)) continue;
            $months = max(1, (int) ($meta['value'] ?? 1));
            $monthly = (int) round($price * 100 / $months);
            if ($best === null || $monthly < $best['monthly']) $best = ['monthly' => $monthly, 'period' => $key];
        }
        return $best;
    }

    // ---------------------------------------------------------------- 佣金提现

    /**
     * 提现打款 / 驳回的通知邮件（不附 PDF）。待处理或用户自己取消的申请没有邮件，返回 null。
     * 金额、USDT、链、地址、交易哈希都来自提现记录本身，和工单回复里写的一致。
     */
    public function withdrawal(CommissionWithdrawal $w): ?array
    {
        $user = User::find($w->user_id);
        if (!$user || !$user->email) return null;
        $status = (int) $w->status;
        if (!in_array($status, [CommissionWithdrawal::STATUS_COMPLETED, CommissionWithdrawal::STATUS_REJECTED], true)) return null;
        $completed = $status === CommissionWithdrawal::STATUS_COMPLETED;

        $config = WithdrawalConfig::fromSettings();
        $chain = $config->findChain((string) $w->chain_code);
        $explorer = $w->txid && $chain && $chain['explorer_tx'] !== ''
            ? str_replace('{txid}', rawurlencode($w->txid), $chain['explorer_tx'])
            : null;
        $usdt = $w->paid_usdt ?? $w->usdt_amount;
        $rate = $w->settle_rate ?? $w->usdt_rate;
        $fee = $w->usdt_fee !== null && (float) $w->usdt_fee > 0 ? $this->usdt((string) $w->usdt_fee) : null;
        $outcome = $completed ? 'completed' : 'rejected';
        $settledAt = $this->dateTime((int) ($w->settled_at ?: time()));
        $historyUrl = $this->url('/invite');

        $data = $this->base() + [
            'kind' => 'withdrawal',
            'outcome' => $outcome,
            'doc_title' => __('billing.withdrawal.doc_title'),
            'doc_title_en' => __('billing.withdrawal.doc_title_en'),
            'doc_no' => '#' . (int) $w->id,
            'stamp' => __('billing.withdrawal.stamp_' . $outcome),
            'stamp_soft' => !$completed,
            'has_pdf' => false,
            'withdrawal_id' => (int) $w->id,
            'usdt' => $usdt !== null ? $this->usdt((string) $usdt) : null,
            'usdt_is_actual' => $w->paid_usdt !== null,
            'usdt_fee' => $fee,
            'usdt_rate' => $rate !== null ? $this->usdt((string) $rate) : null,
            'chain' => $w->network ? "{$w->chain_name} · {$w->network}" : (string) $w->chain_name,
            'address' => (string) $w->address,
            'txid' => $w->txid ?: null,
            'explorer_url' => $explorer,
            'reason' => $completed ? null : ($w->reject_reason ?: null),
            'thanks' => $completed ? $config->thanks : null,
            'settled_at' => $settledAt,
            'bill_to' => ['email' => $user->email, 'id' => (int) $user->id],
            'headline' => __('billing.withdrawal.headline_' . $outcome),
            'intro' => $completed
                ? __('billing.withdrawal.intro_completed', ['id' => $w->id, 'date' => $settledAt])
                : __('billing.withdrawal.intro_rejected', ['id' => $w->id, 'reason' => $w->reject_reason ?: '—', 'amount' => $this->money((int) $w->amount)]),
            // 有交易哈希就把区块浏览器放在主按钮上，佣金记录退到下面的文字链接
            'cta_label' => $explorer ? __('billing.withdrawal.explorer') : __('billing.withdrawal.cta_' . $outcome),
            'cta_url' => $explorer ?: $historyUrl,
            'secondary_label' => $explorer ? __('billing.withdrawal.cta_completed') : null,
            'secondary_url' => $explorer ? $historyUrl : null,
            'subject' => __('billing.withdrawal.subject_' . $outcome, ['id' => $w->id, 'app' => (string) admin_setting('app_name', 'XBoard')]),
            'meta' => [],
            'items' => [],
            'totals' => [],
            'total' => (int) $w->amount,
            'payments' => [],
            'balance_due' => 0,
            'notes' => [],
            'alternatives' => [],
        ];
        return $this->decorate($data);
    }

    // ---------------------------------------------------------------- 到期后

    /** 到期当天：服务已暂停，附按原配置续费的金额与入口；套餐不可续时只引导换套餐。不附 PDF，这期的账单已归档。 */
    public function expired(User $user): array
    {
        $spec = app(RenewService::class)->resolveSpec($user);
        $expiredAt = (int) $user->expired_at;
        $plan = Plan::find($spec['plan_id'] ?? $user->plan_id);
        $planName = (string) ($spec['plan_name'] ?? ($plan?->name ?? ''));
        $available = (bool) $spec['available'];
        $expiredDate = $this->dateTime($expiredAt);
        $hasInvoice = BillingDocument::where('user_id', $user->id)
            ->where('kind', BillingDocument::KIND_INVOICE)->where('expired_at', $expiredAt)->exists();

        $data = $this->base() + [
            'kind' => 'expired',
            'doc_title' => __('billing.expired.doc_title'),
            'doc_title_en' => __('billing.expired.doc_title_en'),
            'doc_no' => $this->date($expiredAt),
            'stamp' => __('billing.expired.stamp'),
            'stamp_soft' => false,
            'has_pdf' => false,
            'plan_name' => $planName,
            'expired_date' => $expiredDate,
            'available' => $available,
            'period' => $available ? $this->periodName($spec['period']) : null,
            'details' => $available
                ? $this->specLines($spec['summary']['transfer_enable'], $spec['summary']['device_limit'], $spec['summary']['speed_limit'], $spec['summary']['addon_names'])
                : [],
            'invoice_url' => $hasInvoice ? $this->url('/billing') : null,
            'alternatives' => $this->alternatives($user, $plan),
            'headline' => __('billing.expired.headline'),
            'intro' => $available
                ? __('billing.expired.intro', ['plan' => $planName, 'date' => $expiredDate])
                : __('billing.expired.intro_unavailable', ['plan' => $planName, 'date' => $expiredDate, 'reason' => $spec['message']]),
            'cta_label' => $available ? __('billing.expired.cta') : __('billing.invoice.cta_browse'),
            'cta_url' => $available ? $this->url('/plans?mode=renew') : $this->url('/plans'),
            'subject' => __('billing.expired.subject', ['plan' => $planName, 'app' => (string) admin_setting('app_name', 'XBoard')]),
            'bill_to' => ['email' => (string) $user->email, 'id' => (int) $user->id],
            'meta' => [],
            'items' => [],
            'totals' => [],
            'payments' => [],
            'notes' => [],
            'total' => $available ? (int) $spec['amount'] : 0,
            'balance_due' => $available ? (int) $spec['amount'] : 0,
        ];
        return $this->decorate($data);
    }

    /** 到期后第 N 天的挽回邮件：后台配了仍有效的优惠券就带上，套餐推荐按原套餐价位挑。不附 PDF。 */
    public function winback(User $user, int $stage): array
    {
        $expiredAt = (int) $user->expired_at;
        $days = max(1, (int) floor((time() - $expiredAt) / 86400));
        $plan = $user->plan_id ? Plan::find($user->plan_id) : null;
        $coupon = $this->winbackCoupon();
        $appName = (string) admin_setting('app_name', 'XBoard');

        $data = $this->base() + [
            'kind' => 'winback',
            'stage' => $stage,
            'doc_title' => __('billing.winback.doc_title'),
            'doc_title_en' => __('billing.winback.doc_title_en'),
            'doc_no' => '',
            'stamp' => $coupon ? __('billing.winback.stamp_coupon') : null,
            'stamp_soft' => false,
            'has_pdf' => false,
            'days' => $days,
            'plan_name' => (string) ($plan?->name ?? ''),
            'coupon' => $coupon,
            'alternatives' => $this->alternatives($user, $plan),
            'headline' => $coupon ? __('billing.winback.headline_coupon') : __('billing.winback.headline'),
            'intro' => $coupon
                ? __('billing.winback.intro_coupon', ['days' => $days, 'desc' => $coupon['desc']])
                : __('billing.winback.intro', ['days' => $days]),
            'cta_label' => $coupon ? __('billing.winback.cta_coupon') : __('billing.winback.cta'),
            'cta_url' => $coupon ? $this->url('/plans?coupon=' . rawurlencode($coupon['code'])) : $this->url('/plans'),
            'subject' => $coupon
                ? __('billing.winback.subject_coupon', ['desc' => $coupon['desc'], 'app' => $appName])
                : __('billing.winback.subject', ['app' => $appName]),
            'bill_to' => ['email' => (string) $user->email, 'id' => (int) $user->id],
            'meta' => [],
            'items' => [],
            'totals' => [],
            'payments' => [],
            'notes' => [],
            'total' => 0,
            'balance_due' => 0,
        ];
        return $this->decorate($data);
    }

    /** 后台 billing_winback_coupon 指定的优惠券：存在、在有效期内、还有余量才带进邮件。 */
    public function winbackCoupon(): ?array
    {
        $code = trim((string) admin_setting('billing_winback_coupon', ''));
        if ($code === '') return null;
        $coupon = Coupon::where('code', $code)->first();
        if (!$coupon) return null;
        $now = time();
        if (($coupon->started_at && (int) $coupon->started_at > $now) || ($coupon->ended_at && (int) $coupon->ended_at < $now)) return null;
        if ($coupon->limit_use !== null && (int) $coupon->limit_use <= 0) return null;
        $desc = (int) $coupon->type === 1
            ? __('billing.winback.coupon_amount', ['amount' => $this->money((int) $coupon->value)])
            : __('billing.winback.coupon_percent', ['pct' => (int) $coupon->value]);
        return [
            'code' => (string) $coupon->code,
            'name' => (string) $coupon->name,
            'desc' => $desc,
            'until' => $coupon->ended_at ? $this->date((int) $coupon->ended_at) : null,
        ];
    }

    /** 邮箱投递自测邮件（退信后用户在面板点「重新测试」）。 */
    public function testMail(User $user): array
    {
        return $this->decorate($this->base() + [
            'kind' => 'test',
            'doc_title' => __('billing.mail_test.headline'),
            'doc_title_en' => 'TEST',
            'doc_no' => '',
            'stamp' => null,
            'stamp_soft' => false,
            'has_pdf' => false,
            'headline' => __('billing.mail_test.headline'),
            'intro' => __('billing.mail_test.intro'),
            'note' => __('billing.mail_test.note'),
            'cta_label' => __('billing.mail_test.cta'),
            'cta_url' => $this->url('/dashboard'),
            'subject' => __('billing.mail_test.subject', ['app' => (string) admin_setting('app_name', 'XBoard')]),
            'bill_to' => ['email' => (string) $user->email, 'id' => (int) $user->id],
            'alternatives' => [],
            'meta' => [],
            'items' => [],
            'totals' => [],
            'payments' => [],
            'notes' => [],
            'total' => 0,
            'balance_due' => 0,
        ]);
    }

    // ---------------------------------------------------------------- 渲染

    public function pdf(array $data): string
    {
        $data['logo_data'] = BrandLogo::dataUri();   // 嵌进 PDF 的 logo（拉取 + 缓存见 BrandLogo），取不到就纯文字抬头
        $tempDir = storage_path('framework/cache/mpdf');
        if (!is_dir($tempDir)) @mkdir($tempDir, 0775, true);
        $defaults = (new \Mpdf\Config\ConfigVariables())->getDefaults();
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 18, 'margin_right' => 18, 'margin_top' => 16, 'margin_bottom' => 22,
            'margin_footer' => 10,
            'tempDir' => $tempDir,
            'fontDir' => array_merge([resource_path('fonts/billing')], $defaults['fontDir']),
            'fontdata' => [
                'notosans' => ['R' => 'NotoSansSC-Regular.ttf', 'B' => 'NotoSansSC-Bold.ttf'],
                'notoserif' => ['R' => 'NotoSerifSC-Bold.ttf', 'B' => 'NotoSerifSC-Bold.ttf'],
            ],
            'default_font' => 'notosans',
            // 子集只覆盖常用字，生僻字（比如用户邮箱 / 套餐名里的）回落到另一字体，而不是显示成方框
            'useSubstitutions' => true,
            'backupSubsFont' => ['notosans', 'dejavusans'],
            'autoScriptToLang' => false,
            'autoLangToFont' => false,
        ]);
        $mpdf->SetTitle($data['doc_title'] . ' ' . $data['doc_no']);
        $mpdf->SetAuthor($data['app_name']);
        $mpdf->SetCreator($data['app_name']);
        $mpdf->WriteHTML(view('billing.pdf.document', $data)->render());
        return $mpdf->Output('', 'S');
    }

    public function attachmentName(array $data): string
    {
        return $data['doc_no'] . '.pdf';
    }

    // ---------------------------------------------------------------- 工具

    private function base(): array
    {
        return [
            'app_name' => (string) admin_setting('app_name', 'XBoard'),
            'app_url' => $this->url(''),
            'issuer' => trim((string) admin_setting('billing_issuer', '')),
            'currency' => (string) admin_setting('currency', 'CNY'),
            'locale' => App::getLocale(),
            'settings_url' => $this->url('/settings'),
            'logo_url' => BrandLogo::url(),
            'logo_size' => BrandLogo::mailSize(),
            'archive_url' => $this->url('/billing'),
        ];
    }

    /** 给模板预格式化金额字符串（*_fmt），模板不碰数字。 */
    private function decorate(array $data): array
    {
        foreach ($data['items'] as &$item) $item['amount_fmt'] = $this->money((int) $item['amount']);
        foreach ($data['totals'] as &$row) $row['amount_fmt'] = $this->money((int) $row['amount']);
        foreach ($data['payments'] as &$row) $row['amount_fmt'] = $this->money((int) $row['amount']);
        unset($item, $row);
        $data['total_fmt'] = $this->money((int) $data['total']);
        $data['balance_due_fmt'] = $this->money((int) $data['balance_due']);
        if (isset($data['account_balance'])) $data['account_balance_fmt'] = $this->money((int) $data['account_balance']);
        $data['attachment_name'] = $data['has_pdf'] ? $this->attachmentName($data) : null;
        return $data;
    }

    public function money(int $cents): string
    {
        $symbol = (string) admin_setting('currency_symbol', '¥');
        // 用 ASCII 连字符而不是 U+2212：子集字体里没有后者，mPDF 会回落到 Symbol 字体，渲染成一道怪杠
        return ($cents < 0 ? '-' : '') . $symbol . number_format(abs($cents) / 100, 2);
    }

    /** USDT 金额 / 汇率：去掉库里 decimal 带的多余 0，但至少留 2 位小数（5.9000 → 5.90，1.0000 → 1.00，7.2125 → 7.2125）。 */
    private function usdt(string $value): string
    {
        $value = str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
        [$int, $dec] = array_pad(explode('.', $value, 2), 2, '');
        return ($int === '' ? '0' : $int) . '.' . str_pad($dec, 2, '0');
    }

    private function specLines($transferGb, $devices, $speed, array $addons): array
    {
        $unlimited = __('billing.field.unlimited');
        $lines = [];
        if ($transferGb !== null) {
            $lines[] = __('billing.field.traffic') . ' ' . ((int) $transferGb > 0 ? (int) $transferGb . ' GB' : $unlimited);
        }
        $lines[] = __('billing.field.devices') . ' ' . ($devices ? __('billing.field.devices_value', ['n' => (int) $devices]) : $unlimited);
        $lines[] = __('billing.field.speed') . ' ' . ($speed ? (int) $speed . ' Mbps' : $unlimited);
        if ($addons) $lines[] = __('billing.field.addons') . ' ' . implode('、', $addons);
        return $lines;
    }

    private function periodName(string $period): string
    {
        $key = 'billing.period.' . $period;
        $name = __($key);
        return $name === $key ? $period : $name;
    }

    private function date(int $ts): string
    {
        return date('Y-m-d', $ts);
    }

    private function dateTime(int $ts): string
    {
        return date('Y-m-d H:i', $ts);
    }

    /** 链接一律基于后台 app_url（用户端前端地址），不读 .env 的 APP_URL。 */
    private function url(string $path): string
    {
        return rtrim((string) admin_setting('app_url', ''), '/') . $path;
    }
}
