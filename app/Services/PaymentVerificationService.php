<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentVerification;
use App\Support\AdminAlert;
use App\Support\EpayOrderQuery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 付款事后核验：已付的订单 / 充值单入账几分钟后，向支付网关查一次这笔交易，核对「网关上真有
 * 这笔、已付、金额与单号一致」。只由定时任务 payment:verify 驱动，不碰回调与开通路径，
 * 不影响用户开通速度（先开通、后核验）。
 *
 * 每张单查到有结论就不再查：
 *   ok / mismatch / refunded / skipped —— 终态；mismatch 定论前再复查一次，两次一致才算；
 *   网关回答「没有这笔」 —— 10 分钟后再查一次，两次都没有才定为 not_found；
 *   网络 / 网关 / 鉴权出错 —— 按 5 分钟、30 分钟、2 小时退避重试，不当成异常。
 * not_found / mismatch 确认后自动封号、收回套餐（PaymentEnforcement，管理员不常看 Telegram，不能等人处理）；
 * refunded 交给退款回查复核两次后处置。异常都推送管理员与客服（每张单只推一次），并进每日邮件汇总。
 */
class PaymentVerificationService
{
    /** 能向网关反查的支付方式（v2_payment.payment） */
    public const GATEWAY_METHODS = ['EPay'];

    /** 入账后至少等这么久再查，给网关落单留余量 */
    public const MIN_AGE_SECONDS = 300;

    /** 默认只看最近这么多天已付的单 */
    public const DEFAULT_WINDOW_DAYS = 7;

    /** 网关回答「没有这笔」的次数达到这个数就定为 not_found（随后自动封号，所以两次就定：伪造的单越早处置越好） */
    public const MAX_MISSES = 2;

    /** 第 N 次「没有这笔」之后，隔多久再查（秒） */
    public const MISS_RETRY_DELAYS = [1 => 600];

    /** 这些结论确认后自动封号；refunded 由退款回查复核后处置 */
    private const ENFORCED_VERDICTS = [PaymentVerification::VERDICT_NOT_FOUND, PaymentVerification::VERDICT_MISMATCH];

    /** 出错后的退避（秒），超出后一直按最后一档 */
    public const ERROR_BACKOFF = [300, 1800, 7200];

    private const RECHARGE_TABLE = 'plugin_recharge_orders';
    private const RECHARGE_STATUS_COMPLETED = 3;
    private const AUTH_ALERT_TTL = 3600;
    private const REPORT_DETAIL_LIMIT = 20;

    /** @var callable(array $config, string $gatewayTradeNo): array */
    private $query;

    private ?bool $hasRechargeTable = null;

    private PaymentEnforcement $enforcement;

    public function __construct(?callable $query = null)
    {
        $this->query = $query ?? [EpayOrderQuery::class, 'query'];
        $this->enforcement = new PaymentEnforcement();
    }

    /**
     * 核验一批到期的单。
     *
     * @return array{checked: int, verdicts: array<string, int>}
     */
    public function run(int $limit = 100, int $windowDays = self::DEFAULT_WINDOW_DAYS, int $sleepMs = 200): array
    {
        $subjects = $this->dueSubjects(Carbon::now()->getTimestamp(), $limit, $windowDays);
        $verdicts = [];
        foreach ($subjects as $i => $subject) {
            if ($i > 0 && $sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
            $verdict = $this->verify($subject)->verdict;
            $verdicts[$verdict] = ($verdicts[$verdict] ?? 0) + 1;
        }
        return ['checked' => count($subjects), 'verdicts' => $verdicts];
    }

    /**
     * 需要（再）查的单：最近 $windowDays 天内已付、入账已过 MIN_AGE_SECONDS、走可反查网关、
     * 实付大于 0，且还没有核验记录或记录到了重试时间。按入账时间从早到晚，最多 $limit 笔。
     *
     * @return list<array{type: string, id: int, user_id: int, trade_no: string, callback_no: ?string, expected: int, payment_id: int, paid_at: int}>
     */
    public function dueSubjects(int $now, int $limit, int $windowDays = self::DEFAULT_WINDOW_DAYS): array
    {
        $paymentIds = $this->gatewayPaymentIds();
        if ($paymentIds === [] || $limit < 1) {
            return [];
        }
        $from = $now - $windowDays * 86400;
        $to = $now - self::MIN_AGE_SECONDS;

        $subjects = [];
        $orders = $this->dueQuery('v2_order', PaymentVerification::SUBJECT_ORDER, $paymentIds, $from, $to, $now)
            ->whereIn('s.status', [Order::STATUS_COMPLETED, Order::STATUS_DISCOUNTED])
            ->whereRaw('(s.total_amount + COALESCE(s.handling_amount, 0)) > 0')
            ->orderBy('s.paid_at')
            ->limit($limit)
            ->get(['s.id', 's.user_id', 's.trade_no', 's.callback_no', 's.total_amount', 's.handling_amount', 's.payment_id', 's.paid_at']);
        foreach ($orders as $o) {
            $subjects[] = $this->subject(PaymentVerification::SUBJECT_ORDER, $o, (int) $o->total_amount);
        }

        if ($this->hasRechargeTable()) {
            $recharges = $this->dueQuery(self::RECHARGE_TABLE, PaymentVerification::SUBJECT_RECHARGE, $paymentIds, $from, $to, $now)
                ->where('s.status', self::RECHARGE_STATUS_COMPLETED)
                ->whereRaw('(s.pay_amount + COALESCE(s.handling_amount, 0)) > 0')
                ->orderBy('s.paid_at')
                ->limit($limit)
                ->get(['s.id', 's.user_id', 's.trade_no', 's.callback_no', 's.pay_amount', 's.handling_amount', 's.payment_id', 's.paid_at']);
            foreach ($recharges as $r) {
                $subjects[] = $this->subject(PaymentVerification::SUBJECT_RECHARGE, $r, (int) $r->pay_amount);
            }
        }

        usort($subjects, fn ($a, $b) => $a['paid_at'] <=> $b['paid_at'] ?: strcmp($a['type'], $b['type']) ?: $a['id'] <=> $b['id']);
        return array_slice($subjects, 0, $limit);
    }

    /**
     * 核验一张单并落库，返回核验记录。
     *
     * @param array{type: string, id: int, user_id: int, trade_no: string, callback_no: ?string, expected: int, payment_id: int, paid_at: int} $subject
     */
    public function verify(array $subject): PaymentVerification
    {
        $now = Carbon::now()->getTimestamp();
        /** @var PaymentVerification $row */
        $row = PaymentVerification::firstOrNew(['subject_type' => $subject['type'], 'subject_id' => $subject['id']]);
        $row->fill([
            'user_id' => $subject['user_id'],
            'payment_id' => $subject['payment_id'],
            'callback_no' => $subject['callback_no'],
            'expected_amount' => $subject['expected'],
        ]);

        $skip = $this->skipReason($subject);
        // 每次都现读支付配置：管理员换了密钥，下一次查询就用新的
        $payment = $skip === null ? Payment::find($subject['payment_id']) : null;
        if ($skip === null && (!$payment || !in_array((string) $payment->payment, self::GATEWAY_METHODS, true))) {
            $skip = '支付配置不存在或不支持网关反查';
        }
        if ($skip !== null) {
            return $this->conclude($row, PaymentVerification::VERDICT_SKIPPED, $skip, $now);
        }

        $result = ($this->query)((array) $payment->config, (string) $subject['callback_no']);
        $row->attempts = (int) $row->attempts + 1;
        $row->checked_at = $now;

        switch ($result['outcome'] ?? EpayOrderQuery::ERROR) {
            case EpayOrderQuery::FOUND:
                $issue = self::issueOf($result, $subject);
                if ($issue !== null && $issue[0] === PaymentVerification::VERDICT_MISMATCH) {
                    // 金额 / 单号不符会自动封号：先复查一次，网关偶发返回脏数据不能凭一次就处置
                    $again = ($this->query)((array) $payment->config, (string) $subject['callback_no']);
                    $row->attempts = (int) $row->attempts + 1;
                    if (($again['outcome'] ?? null) !== EpayOrderQuery::FOUND) {
                        return $this->retryLater($row, '金额或单号不符，复查失败：' . ($again['msg'] ?? ''), $now);
                    }
                    $result = $again;
                    $issue = self::issueOf($again, $subject);
                }
                $row->gateway_status = $result['status'];
                $row->gateway_money = $result['money'];
                $row->gateway_type = $result['type'];
                if ($issue === null) {
                    return $this->conclude($row, PaymentVerification::VERDICT_OK, null, $now);
                }
                return $this->conclude($row, $issue[0], $issue[1], $now);

            case EpayOrderQuery::NOT_FOUND:
                $row->miss_count = (int) $row->miss_count + 1;
                if ($row->miss_count >= self::MAX_MISSES) {
                    return $this->conclude($row, PaymentVerification::VERDICT_NOT_FOUND, '网关 ' . $row->miss_count . ' 次查询都没有这笔交易', $now);
                }
                $row->verdict = PaymentVerification::VERDICT_PENDING;
                $row->last_error = '网关暂无这笔交易（第 ' . $row->miss_count . ' 次）';
                $row->next_check_at = $now + self::MISS_RETRY_DELAYS[$row->miss_count];
                $row->save();
                return $row;

            case EpayOrderQuery::AUTH_ERROR:
                $this->alertAuthError($payment, (string) ($result['msg'] ?? ''));
                return $this->retryLater($row, '网关拒绝查询：' . ($result['msg'] ?? ''), $now);

            default:
                return $this->retryLater($row, (string) ($result['msg'] ?? '查询失败'), $now);
        }
    }

    /**
     * 当天（应用时区）的核验日报，推送给管理员的纯文本。
     */
    public function dailyReport(Carbon $day): string
    {
        $start = $day->copy()->startOfDay()->getTimestamp();
        $end = $start + 86400;

        $counts = PaymentVerification::query()
            ->where('checked_at', '>=', $start)->where('checked_at', '<', $end)
            ->groupBy('verdict')
            ->selectRaw('verdict, COUNT(*) AS n')
            ->pluck('n', 'verdict')
            ->map(fn ($n) => (int) $n)
            ->all();
        $total = array_sum($counts);
        $anomalyCount = array_sum(array_intersect_key($counts, array_flip(PaymentVerification::ANOMALIES)));
        $outstanding = PaymentVerification::whereIn('verdict', PaymentVerification::RETRYABLE)->count();
        $unverified = $this->countUnverified($start, min($end, Carbon::now()->getTimestamp() - self::MIN_AGE_SECONDS));

        $lines = ['付款核验日报 ' . $day->format('Y-m-d')];
        $parts = [];
        foreach ([PaymentVerification::VERDICT_OK, PaymentVerification::VERDICT_NOT_FOUND, PaymentVerification::VERDICT_MISMATCH,
                     PaymentVerification::VERDICT_REFUNDED, PaymentVerification::VERDICT_PENDING, PaymentVerification::VERDICT_ERROR,
                     PaymentVerification::VERDICT_SKIPPED] as $verdict) {
            if (!empty($counts[$verdict])) {
                $parts[] = PaymentVerification::LABELS[$verdict] . ' ' . $counts[$verdict];
            }
        }
        $lines[] = "今日核验 {$total} 笔" . ($parts ? '：' . implode('，', $parts) : '');
        $lines[] = "异常 {$anomalyCount} 笔；待重试或出错 {$outstanding} 笔；今日已付但尚未核验 {$unverified} 笔";

        if ($anomalyCount > 0) {
            $lines[] = '异常明细：';
            $anomalies = PaymentVerification::whereIn('verdict', PaymentVerification::ANOMALIES)
                ->where('checked_at', '>=', $start)->where('checked_at', '<', $end)
                ->orderBy('checked_at')
                ->limit(self::REPORT_DETAIL_LIMIT)
                ->get();
            foreach ($anomalies as $row) {
                $lines[] = '· ' . $this->describe($row);
            }
            if ($anomalyCount > self::REPORT_DETAIL_LIMIT) {
                $lines[] = '…另有 ' . ($anomalyCount - self::REPORT_DETAIL_LIMIT) . ' 笔';
            }
        }
        return implode("\n", $lines);
    }

    /**
     * 佣金提现打款前的把关依据：邀请人 $inviterId 的佣金来源订单里，
     *   blocking   核验结论是 not_found / mismatch / refunded 的订单号；
     *   unverified 走可反查网关、实付大于 0、但还没有结论的订单数（历史单没有记录，不拦，只提示）。
     *
     * @return array{blocking: list<string>, unverified: int}
     */
    public function withdrawalIssues(int $inviterId): array
    {
        $empty = ['blocking' => [], 'unverified' => 0];
        if (!Schema::hasTable('v2_payment_verification')) {
            return $empty;
        }
        $rows = DB::table('v2_commission_log as c')
            ->join('v2_order as o', 'o.trade_no', '=', 'c.trade_no')
            ->leftJoin('v2_payment_verification as v', function ($join) {
                $join->on('v.subject_id', '=', 'o.id')->where('v.subject_type', '=', PaymentVerification::SUBJECT_ORDER);
            })
            ->leftJoin('v2_payment as p', 'p.id', '=', 'o.payment_id')
            ->where('c.invite_user_id', $inviterId)
            ->get(['o.trade_no', 'o.total_amount', 'v.verdict', 'p.payment']);

        $blocking = [];
        $unverified = 0;
        foreach ($rows as $r) {
            if (in_array($r->verdict, PaymentVerification::ANOMALIES, true)) {
                $blocking[] = (string) $r->trade_no;
            } elseif (($r->verdict === null || in_array($r->verdict, PaymentVerification::RETRYABLE, true))
                && in_array((string) $r->payment, self::GATEWAY_METHODS, true) && (int) $r->total_amount > 0) {
                $unverified++;
            }
        }
        return ['blocking' => array_values(array_unique($blocking)), 'unverified' => $unverified];
    }

    private function dueQuery(string $table, string $subjectType, array $paymentIds, int $from, int $to, int $now)
    {
        return DB::table($table . ' as s')
            ->leftJoin('v2_payment_verification as v', function ($join) use ($subjectType) {
                $join->on('v.subject_id', '=', 's.id')->where('v.subject_type', '=', $subjectType);
            })
            ->whereBetween('s.paid_at', [$from, $to])
            ->whereIn('s.payment_id', $paymentIds)
            ->where(function ($q) use ($now) {
                $q->whereNull('v.id')->orWhere(function ($due) use ($now) {
                    $due->whereIn('v.verdict', PaymentVerification::RETRYABLE)->where('v.next_check_at', '<=', $now);
                });
            });
    }

    private function countUnverified(int $from, int $to): int
    {
        $paymentIds = $this->gatewayPaymentIds();
        if ($paymentIds === [] || $to < $from) {
            return 0;
        }
        $orders = DB::table('v2_order as s')
            ->leftJoin('v2_payment_verification as v', function ($join) {
                $join->on('v.subject_id', '=', 's.id')->where('v.subject_type', '=', PaymentVerification::SUBJECT_ORDER);
            })
            ->whereBetween('s.paid_at', [$from, $to])
            ->whereIn('s.payment_id', $paymentIds)
            ->whereIn('s.status', [Order::STATUS_COMPLETED, Order::STATUS_DISCOUNTED])
            ->whereRaw('(s.total_amount + COALESCE(s.handling_amount, 0)) > 0')
            ->whereNull('v.id')
            ->count();
        $recharges = 0;
        if ($this->hasRechargeTable()) {
            $recharges = DB::table(self::RECHARGE_TABLE . ' as s')
                ->leftJoin('v2_payment_verification as v', function ($join) {
                    $join->on('v.subject_id', '=', 's.id')->where('v.subject_type', '=', PaymentVerification::SUBJECT_RECHARGE);
                })
                ->whereBetween('s.paid_at', [$from, $to])
                ->whereIn('s.payment_id', $paymentIds)
                ->where('s.status', self::RECHARGE_STATUS_COMPLETED)
                ->whereRaw('(s.pay_amount + COALESCE(s.handling_amount, 0)) > 0')
                ->whereNull('v.id')
                ->count();
        }
        return $orders + $recharges;
    }

    /** @return list<int> */
    private function gatewayPaymentIds(): array
    {
        return Payment::whereIn('payment', self::GATEWAY_METHODS)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function hasRechargeTable(): bool
    {
        return $this->hasRechargeTable ??= Schema::hasTable(self::RECHARGE_TABLE);
    }

    private function subject(string $type, object $row, int $amount): array
    {
        return [
            'type' => $type,
            'id' => (int) $row->id,
            'user_id' => (int) $row->user_id,
            'trade_no' => (string) $row->trade_no,
            'callback_no' => $row->callback_no !== null ? (string) $row->callback_no : null,
            'expected' => $amount + (int) ($row->handling_amount ?? 0),
            'payment_id' => (int) $row->payment_id,
            'paid_at' => (int) $row->paid_at,
        ];
    }

    /** 不适合向网关反查的单：人工补单、链上直付按交易哈希补的单、没有网关交易号。 */
    private function skipReason(array $subject): ?string
    {
        $callbackNo = trim((string) ($subject['callback_no'] ?? ''));
        if ($callbackNo === '') {
            return '没有网关交易号';
        }
        if ($callbackNo === 'manual_operation') {
            return '人工补单';
        }
        if (preg_match('/^(0x)?[0-9a-f]{64}$/i', $callbackNo)) {
            return '链上交易哈希（人工核实补单）';
        }
        return null;
    }

    private static function outTradeNoMatches(?string $gatewayOutTradeNo, string $tradeNo): bool
    {
        if ($gatewayOutTradeNo === null || $gatewayOutTradeNo === '') {
            return true; // 网关没回传就不比，金额与状态已经核过
        }
        // 网关上的商户单号可能是支付会话号：订单号 + '-' + 后缀
        return $gatewayOutTradeNo === $tradeNo || str_starts_with($gatewayOutTradeNo, $tradeNo . '-');
    }

    private function conclude(PaymentVerification $row, string $verdict, ?string $note, int $now): PaymentVerification
    {
        $row->verdict = $verdict;
        $row->last_error = $note;
        $row->checked_at = $now;
        $row->next_check_at = null;
        $row->save();

        $outcome = in_array($verdict, self::ENFORCED_VERDICTS, true)
            ? $this->enforcement->enforce($row, '付款核验异常', static function () {
            })
            : null;

        if (in_array($verdict, PaymentVerification::ANOMALIES, true) && $row->alerted_at === null) {
            // 条件更新抢到告警权，避免并发重复推送
            $claimed = PaymentVerification::where('id', $row->id)->whereNull('alerted_at')->update(['alerted_at' => $now]);
            if ($claimed === 1) {
                $row->alerted_at = $now;
                if ($outcome !== null) {
                    $label = (PaymentVerification::LABELS[$verdict] ?? $verdict) . ($note ? '（' . $note . '）' : '');
                    $this->enforcement->alert($row, $outcome['user'], $outcome['snapshot'], '🚨 <b>付款核验异常，已自动封号</b>',
                        ['核验结论：' . TelegramService::escapeHtml($label) . '。订单是先开通的，现已收回。']);
                } else {
                    AdminAlert::send("⚠️ 付款核验异常\n" . $this->describe($row) . "\n"
                        . ($verdict === PaymentVerification::VERDICT_REFUNDED
                            ? '退款回查会再复核两次，确认是退款就自动封号。'
                            : '该用户此前已处置过（或已不存在），本次未重复处置。'), 'markdown', true);
                }
            }
        }
        return $row;
    }

    /**
     * 网关查到的这笔与本单对不对得上；对得上返回 null。
     *
     * @return array{0: string, 1: string}|null [结论, 说明]
     */
    private static function issueOf(array $result, array $subject): ?array
    {
        if ($result['status'] !== 1) {
            return [PaymentVerification::VERDICT_REFUNDED, '网关状态 ' . ($result['status'] ?? '未知') . '（未付或已退款）'];
        }
        if ($result['money'] !== $subject['expected']) {
            return [PaymentVerification::VERDICT_MISMATCH, '网关金额 ' . self::yuan($result['money']) . '，应付 ' . self::yuan($subject['expected'])];
        }
        if (!self::outTradeNoMatches($result['out_trade_no'], $subject['trade_no'])) {
            return [PaymentVerification::VERDICT_MISMATCH, '网关商户单号与本单不符'];
        }
        return null;
    }

    private function retryLater(PaymentVerification $row, string $error, int $now): PaymentVerification
    {
        $errors = max(1, (int) $row->attempts - (int) $row->miss_count);
        $delay = self::ERROR_BACKOFF[min($errors, count(self::ERROR_BACKOFF)) - 1];
        $row->verdict = PaymentVerification::VERDICT_ERROR;
        $row->last_error = mb_substr($error, 0, 255);
        $row->next_check_at = $now + $delay;
        $row->save();
        return $row;
    }

    private function alertAuthError(Payment $payment, string $msg): void
    {
        if (!Cache::add('payment_verification_auth_alert:' . $payment->id, 1, self::AUTH_ALERT_TTL)) {
            return;
        }
        AdminAlert::send("⚠️ 付款核验：支付网关拒绝了查询\n支付配置：" . self::plain((string) $payment->name) . "（ID {$payment->id}）\n"
            . '网关返回：' . self::plain($msg) . "\n商户号或密钥可能已失效，请检查支付配置。核验会自动重试，1 小时内不再重复提醒。");
    }

    private function describe(PaymentVerification $row): string
    {
        $tradeNo = $row->subject_type === PaymentVerification::SUBJECT_ORDER
            ? DB::table('v2_order')->where('id', $row->subject_id)->value('trade_no')
            : ($this->hasRechargeTable() ? DB::table(self::RECHARGE_TABLE)->where('id', $row->subject_id)->value('trade_no') : null);
        $kind = $row->subject_type === PaymentVerification::SUBJECT_ORDER ? '订单' : '充值单';
        return $kind . ' ' . ($tradeNo ?? ('#' . $row->subject_id))
            . '，用户ID ' . ($row->user_id ?? '-')
            . '，' . self::yuan($row->expected_amount)
            . '，' . (PaymentVerification::LABELS[$row->verdict] ?? $row->verdict)
            . ($row->last_error ? '（' . self::plain($row->last_error) . '）' : '')
            . '，网关交易号 ' . self::plain((string) $row->callback_no);
    }

    private static function yuan(?int $minor): string
    {
        return $minor === null ? '未知' : '¥' . number_format($minor / 100, 2);
    }

    /** 告警走 Telegram markdown，去掉会被当成格式符的字符 */
    private static function plain(string $text): string
    {
        return str_replace(['_', '*', '`', '[', ']'], ' ', $text);
    }
}
