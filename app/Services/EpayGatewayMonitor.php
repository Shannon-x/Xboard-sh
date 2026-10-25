<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\RechargeOrder;
use App\Models\User;
use App\Support\AdminAlert;
use App\Support\EpayV2;
use App\Support\PaymentGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 易支付 V2 商户侧巡检：
 * - complaints()：拉支付宝 / 微信投诉，新投诉或状态变化推给管理员。投诉长时间不处理，
 *   上游会限制甚至关停收款通道，所以要第一时间看到。投诉也是用户退款的前兆：有变化立即、
 *   未结案期间每 6 小时让 RefundWatchService 回查一次这笔，退了款就自动封号。
 * - merchantSummary()：给付款核验日报追加网关侧的余额、今日收入，并与我方当天入账对账。
 *
 * 只处理 api_version=v2 的 EPay 配置；同一商户（网关 + 商户号）的多条配置只查一次。
 */
final class EpayGatewayMonitor
{
    /** 已告警状态记多久：投诉列表只拉近 30 天，记 90 天足够 */
    private const SEEN_TTL = 90 * 86400;
    /** 单次最多推多少条，其余留到下一轮（首轮可能有一批积压的待处理投诉） */
    private const MAX_ALERTS_PER_RUN = 10;
    private const LOOKBACK_DAYS = 30;
    private const STATUS_TEXT = [0 => '待处理', 1 => '处理中', 2 => '处理完成'];
    private const PAY_TYPE_TEXT = ['alipay' => '支付宝', 'wxpay' => '微信支付', 'qqpay' => 'QQ钱包'];
    /** 未结案投诉的退款回查间隔 */
    private const OPEN_COMPLAINT_RECHECK = 6 * 3600;

    private RefundWatchService $refundWatch;

    public function __construct(?RefundWatchService $refundWatch = null)
    {
        $this->refundWatch = $refundWatch ?? new RefundWatchService();
    }

    /**
     * @return array{merchants: int, complaints: int, alerted: int, rechecked: int, errors: list<string>}
     */
    public function complaints(): array
    {
        $stats = ['merchants' => 0, 'complaints' => 0, 'alerted' => 0, 'rechecked' => 0, 'errors' => []];
        foreach ($this->merchants() as $merchantKey => $payment) {
            $stats['merchants']++;
            try {
                $client = EpayV2::fromConfig((array) $payment->config);
            } catch (\InvalidArgumentException $e) {
                $stats['errors'][] = "支付配置 #{$payment->id}：" . $e->getMessage();
                continue;
            }

            $response = $client->complaints([
                'page_num' => 1,
                'page_size' => 50,
                'begin_date' => date('Y-m-d', time() - self::LOOKBACK_DAYS * 86400),
            ]);
            if (!$response['ok']) {
                $stats['errors'][] = "支付配置 #{$payment->id}：" . ($response['error'] ?? $response['msg']);
                continue;
            }

            $rows = $response['data']['data']['rows'] ?? [];
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (!is_array($row) || !isset($row['id'])) {
                    continue;
                }
                $stats['complaints']++;
                $cacheKey = 'epay_complaint:' . $merchantKey . ':' . (int) $row['id'];
                $state = ($row['status'] ?? '') . '|' . ($row['edittime'] ?? '');
                $previous = Cache::get($cacheKey);
                $changed = $previous !== $state;

                if ($changed) {
                    if ($previous === null && (int) ($row['status'] ?? 0) === 2) {
                        // 第一次见到就已处理完的（历史投诉）只记下，不刷屏
                        Cache::put($cacheKey, $state, self::SEEN_TTL);
                    } elseif ($stats['alerted'] < self::MAX_ALERTS_PER_RUN) {
                        AdminAlert::send($this->formatComplaint($row, $previous !== null), 'html', true);
                        PaymentAlertDigest::recordComplaint($row);
                        Cache::put($cacheKey, $state, self::SEEN_TTL);
                        $stats['alerted']++;
                    } else {
                        // 超出单轮告警上限的留到下一轮，回查也一起留到那时
                        continue;
                    }
                }
                if ($this->recheckComplained($row, $changed)) {
                    $stats['rechecked']++;
                }
            }
        }
        return $stats;
    }

    /**
     * 投诉单对应的付款回查：投诉状态有变化（新投诉、结案）立即查；未结案的每 6 小时查一次。
     */
    private function recheckComplained(array $row, bool $changed): bool
    {
        $gatewayTradeNo = trim((string) ($row['trade_no'] ?? ''));
        if ($gatewayTradeNo === '') {
            return false;
        }
        $throttleKey = 'epay_complaint_recheck:' . $gatewayTradeNo;
        $open = in_array((int) ($row['status'] ?? 0), [0, 1], true);
        if ($changed) {
            Cache::put($throttleKey, 1, self::OPEN_COMPLAINT_RECHECK);
        } elseif (!$open || !Cache::add($throttleKey, 1, self::OPEN_COMPLAINT_RECHECK)) {
            return false;
        }
        return $this->refundWatch->recheckByCallbackNo($gatewayTradeNo, '投诉 #' . (int) $row['id']) !== null;
    }

    /**
     * 日报用：每个 V2 商户一段，网关余额、今日订单数与收入，并与我方当天入账（该商户各配置下的
     * 订单 + 充值单）对账。网关接口不通时写明原因，不影响日报其余部分。
     *
     * @return list<string>
     */
    public function merchantSummary(?Carbon $day = null): array
    {
        $day ??= Carbon::today();
        $lines = [];
        foreach ($this->merchants() as $payment) {
            $config = (array) $payment->config;
            $label = '易支付 V2 商户 ' . ($config['pid'] ?? '?');
            try {
                $response = EpayV2::fromConfig($config)->merchantInfo();
            } catch (\InvalidArgumentException $e) {
                $lines[] = "{$label}：配置无效（{$e->getMessage()}）";
                continue;
            }
            if (!$response['ok']) {
                $lines[] = "{$label}：查询失败（" . ($response['error'] ?? $response['msg']) . '）';
                continue;
            }

            $data = $response['data'];
            $gatewayToday = PaymentGuard::decimalToMinor((string) ($data['order_money_today'] ?? '')) ?? 0;
            $ours = $this->ourPaidMinor($this->samePaymentIds($payment), $day);
            $line = sprintf(
                '%s：余额 ¥%s；网关今日 %d 单 ¥%s，我方入账 ¥%s',
                $label,
                (string) ($data['money'] ?? '?'),
                (int) ($data['order_num_today'] ?? 0),
                self::yuan($gatewayToday),
                self::yuan($ours)
            );
            if ($day->isToday() && $gatewayToday !== $ours) {
                $line .= '（差额 ¥' . self::yuan(abs($gatewayToday - $ours)) . '，可能有掉单、网关侧退款或跨日入账，请核对）';
            }
            $lines[] = $line;
        }
        return $lines;
    }

    /**
     * 全部 api_version=v2 的 EPay 配置，按「网关地址 + 商户号」去重（停用的也算：停用后投诉照样会来）。
     *
     * @return array<string, Payment>
     */
    private function merchants(): array
    {
        $merchants = [];
        foreach (Payment::where('payment', 'EPay')->orderBy('id')->get() as $payment) {
            $config = (array) $payment->config;
            if (!EpayV2::enabledFor($config)) {
                continue;
            }
            $key = substr(hash('sha256', rtrim((string) ($config['url'] ?? ''), '/') . "\0" . ($config['pid'] ?? '')), 0, 16);
            $merchants[$key] ??= $payment;
        }
        return $merchants;
    }

    /** @return int[] 与 $payment 同一商户的全部配置 ID（支付宝 / 微信两个入口常是同一商户） */
    private function samePaymentIds(Payment $payment): array
    {
        $config = (array) $payment->config;
        $ids = [];
        foreach (Payment::where('payment', 'EPay')->get() as $candidate) {
            $c = (array) $candidate->config;
            if (rtrim((string) ($c['url'] ?? ''), '/') === rtrim((string) ($config['url'] ?? ''), '/')
                && (string) ($c['pid'] ?? '') === (string) ($config['pid'] ?? '')) {
                $ids[] = (int) $candidate->id;
            }
        }
        return $ids;
    }

    private function ourPaidMinor(array $paymentIds, Carbon $day): int
    {
        if ($paymentIds === []) {
            return 0;
        }
        [$from, $to] = [$day->copy()->startOfDay()->timestamp, $day->copy()->endOfDay()->timestamp];
        $orders = (int) Order::whereIn('payment_id', $paymentIds)
            ->whereIn('status', [Order::STATUS_COMPLETED, Order::STATUS_DISCOUNTED])
            ->whereBetween('paid_at', [$from, $to])
            ->sum(DB::raw('total_amount + COALESCE(handling_amount, 0)'));
        // 充值单的 pay_amount 已含手续费，不能再加 handling_amount
        $recharges = (int) RechargeOrder::whereIn('payment_id', $paymentIds)
            ->where('status', RechargeOrder::STATUS_COMPLETED)
            ->whereBetween('paid_at', [$from, $to])
            ->sum('pay_amount');
        return $orders + $recharges;
    }

    private function formatComplaint(array $row, bool $changed): string
    {
        $esc = [TelegramService::class, 'escapeHtml'];
        $str = fn (string $key, int $limit = 300) => $esc(mb_substr(trim((string) ($row[$key] ?? '')), 0, $limit));
        $payType = (string) ($row['pay_type'] ?? '');
        $status = self::STATUS_TEXT[(int) ($row['status'] ?? -1)] ?? (string) ($row['status'] ?? '?');

        $lines = [
            ($changed ? '📣 <b>支付投诉状态更新</b>' : '📣 <b>新的支付投诉</b>')
                . '（' . $esc(self::PAY_TYPE_TEXT[$payType] ?? $payType) . '）· ' . $esc($status),
            '投诉 ID：' . (int) $row['id'] . ' · 金额 ¥' . $str('money', 16) . ' · 问题：' . $str('problem_type', 40),
        ];
        if (trim((string) ($row['problem_description'] ?? '')) !== '') {
            $lines[] = '描述：' . $str('problem_description');
        }
        if (trim((string) ($row['complaint_content'] ?? '')) !== '') {
            $lines[] = '内容：' . $str('complaint_content');
        }
        $lines[] = $this->describeOrder((string) ($row['trade_no'] ?? ''), (string) ($row['out_trade_no'] ?? ''));
        $lines[] = '用户投诉次数：' . (int) ($row['user_complaint_times'] ?? 1) . ' · 投诉时间：' . $str('addtime', 32);
        $lines[] = '请到易支付商户后台「投诉」处理；长时间不处理，上游可能限制收款通道。';
        return implode("\n", $lines);
    }

    /** 用网关单号 / 商户单号找回我方的单据与用户（商户单号可能是 PaymentAttempt 的「订单号-8位会话后缀」） */
    private function describeOrder(string $gatewayTradeNo, string $outTradeNo): string
    {
        $esc = [TelegramService::class, 'escapeHtml'];
        $baseTradeNo = preg_replace('/-[a-z0-9]{8}$/', '', $outTradeNo) ?? $outTradeNo;

        $order = ($gatewayTradeNo !== '' ? Order::where('callback_no', $gatewayTradeNo)->first() : null)
            ?? ($baseTradeNo !== '' ? Order::where('trade_no', $baseTradeNo)->first() : null);
        $tradeNo = $order?->trade_no;
        $userId = $order?->user_id;
        $kind = '订单';

        if (!$order) {
            $recharge = ($gatewayTradeNo !== '' ? RechargeOrder::where('callback_no', $gatewayTradeNo)->first() : null)
                ?? ($baseTradeNo !== '' ? RechargeOrder::where('trade_no', $baseTradeNo)->first() : null);
            if ($recharge) {
                [$tradeNo, $userId, $kind] = [$recharge->trade_no, $recharge->user_id, '充值单'];
            }
        }

        if ($tradeNo === null) {
            return '网关单号：<code>' . $esc($gatewayTradeNo) . '</code>（本站没找到对应单据）';
        }
        $user = $userId ? User::find($userId) : null;
        return $kind . '：<code>' . $esc((string) $tradeNo) . '</code> · 用户：'
            . ($user ? $esc((string) $user->email) . '（#' . $user->id . '）' : '—');
    }

    private static function yuan(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }
}
