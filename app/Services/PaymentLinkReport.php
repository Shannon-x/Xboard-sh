<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\RechargeOrder;
use App\Support\AdminAlert;
use App\Support\PaymentLinkStats;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * 支付通道拉取日报与白天告警，数据见 PaymentLinkStats。
 *
 * - dailyLines()：给 23:30 的付款核验日报追加「各通道拉取成功 / 失败、失败原因、付款数、转化率」。
 * - checkHealth()：白天每 15 分钟看一次，失败率高或「拉到了链接却一直没人付」时推管理员，
 *   同一通道同一类告警 3 小时最多一次。
 */
final class PaymentLinkReport
{
    /** 失败率告警：最近 60 分钟至少这么多次拉取，且失败占比不低于下面的比例 */
    public const FAIL_WINDOW = 3600;
    public const FAIL_MIN_ATTEMPTS = 5;
    public const FAIL_RATE = 0.5;
    /** 没人付款告警：最近 2 小时（刚拉的 10 分钟不算）拉成功至少这么多次，一笔都没付，而平时转化率不低于下面的值 */
    public const UNPAID_WINDOW = 7200;
    public const UNPAID_SKIP = 600;
    public const UNPAID_MIN_LINKS = 6;
    public const UNPAID_BASELINE_RATE = 0.2;
    public const UNPAID_BASELINE_MIN_LINKS = 20;
    public const ALERT_COOLDOWN = 3 * 3600;

    /** @return list<string> */
    public function dailyLines(Carbon $day): array
    {
        $stats = PaymentLinkStats::day($day->format('Ymd'));
        if ($stats === []) {
            return ['支付通道拉取：今天没有记录（要么没人下单，要么统计还没上线）'];
        }
        $names = Payment::whereIn('id', array_keys($stats))->get(['id', 'name', 'enable'])->keyBy('id');
        [$from, $to] = [$day->copy()->startOfDay()->timestamp, $day->copy()->endOfDay()->timestamp];

        $lines = ['支付通道拉取（成功 / 失败 / 已付）'];
        foreach ($stats as $paymentId => $row) {
            $payment = $names->get($paymentId);
            $name = $payment ? self::plain((string) $payment->name) : '已删除';
            $attempts = $row['ok'] + $row['fail'];
            $paid = $this->paidCount($paymentId, $from, $to);
            $line = sprintf(
                '#%d %s%s：成功 %d、失败 %d（失败率 %s），已付 %d（转化 %s）',
                $paymentId,
                $name,
                $payment && !$payment->enable ? '（已停用）' : '',
                $row['ok'],
                $row['fail'],
                self::percent($row['fail'], $attempts),
                $paid,
                self::percent($paid, $row['ok'])
            );
            $baseline = $this->baseline($paymentId, $day);
            if ($baseline !== null) {
                $line .= '，近 7 天转化 ' . self::percent($baseline['paid'], $baseline['ok']);
            }
            $lines[] = $line;
            $reasons = $row['reasons'];
            uasort($reasons, fn ($a, $b) => ($b['count'] ?? 0) <=> ($a['count'] ?? 0));
            foreach (array_slice($reasons, 0, 4, true) as $category => $info) {
                $lines[] = sprintf(
                    '　· %s %d 次（例：%s）',
                    self::plain((string) $category),
                    (int) ($info['count'] ?? 0),
                    self::plain(mb_substr((string) ($info['sample'] ?? ''), 0, 60))
                );
            }
        }
        $lines[] = '注：跳转式通道的「未适配到通道」「限国内IP」在用户打开付款页时才出现，面板记不到，看转化率是否明显偏低。';
        return $lines;
    }

    /**
     * 白天巡检，返回本轮发出的告警条数。
     */
    public function checkHealth(?int $now = null): int
    {
        $now ??= time();
        $sent = 0;
        $ids = array_unique(array_merge(
            PaymentLinkStats::paymentIds(date('Ymd', $now)),
            PaymentLinkStats::paymentIds(date('Ymd', $now - self::UNPAID_WINDOW))
        ));
        foreach ($ids as $paymentId) {
            $payment = Payment::find($paymentId);
            if (!$payment || !$payment->enable) {
                continue;
            }
            $name = TelegramService::escapeHtml((string) $payment->name);

            $w = PaymentLinkStats::window($paymentId, self::FAIL_WINDOW, 0, $now);
            $attempts = $w['ok'] + $w['fail'];
            if ($attempts >= self::FAIL_MIN_ATTEMPTS && $w['fail'] / $attempts >= self::FAIL_RATE
                && Cache::add("payment_link_alert:fail:{$paymentId}", 1, self::ALERT_COOLDOWN)) {
                $reasons = PaymentLinkStats::day(date('Ymd', $now))[$paymentId]['reasons'] ?? [];
                uasort($reasons, fn ($a, $b) => ($b['count'] ?? 0) <=> ($a['count'] ?? 0));
                $top = array_key_first($reasons);
                AdminAlert::send(sprintf(
                    "⚠️ <b>支付通道拉取失败偏多</b>\n#%d %s 最近 1 小时拉取 %d 次，失败 %d 次（%s）%s\n客户在这个通道付不了款，考虑临时停用或换通道。",
                    $paymentId,
                    $name,
                    $attempts,
                    $w['fail'],
                    self::percent($w['fail'], $attempts),
                    $top !== null ? '，主要是「' . TelegramService::escapeHtml((string) $top) . '」' : ''
                ), 'html');
                $sent++;
            }

            $links = PaymentLinkStats::window($paymentId, self::UNPAID_WINDOW, self::UNPAID_SKIP, $now)['ok'];
            if ($links < self::UNPAID_MIN_LINKS) {
                continue;
            }
            $baseline = $this->baseline($paymentId, Carbon::createFromTimestamp($now)->startOfDay());
            if ($baseline === null || $baseline['ok'] < self::UNPAID_BASELINE_MIN_LINKS
                || $baseline['paid'] / $baseline['ok'] < self::UNPAID_BASELINE_RATE) {
                continue;
            }
            if ($this->paidCount($paymentId, $now - self::UNPAID_WINDOW, $now) === 0
                && Cache::add("payment_link_alert:unpaid:{$paymentId}", 1, self::ALERT_COOLDOWN)) {
                AdminAlert::send(sprintf(
                    "⚠️ <b>支付通道拉到链接却没人付款</b>\n#%d %s 最近 2 小时拉成功 %d 次，一笔都没付（近 7 天转化 %s）。\n可能付款页打开就报错（未适配通道、限国内IP），建议用手机实际打开看看。",
                    $paymentId,
                    $name,
                    $links,
                    self::percent($baseline['paid'], $baseline['ok'])
                ), 'html');
                $sent++;
            }
        }
        return $sent;
    }

    /** 当天这个通道付了几笔（套餐单 + 充值单） */
    private function paidCount(int $paymentId, int $from, int $to): int
    {
        return Order::where('payment_id', $paymentId)
            ->whereIn('status', [Order::STATUS_COMPLETED, Order::STATUS_DISCOUNTED])
            ->whereBetween('paid_at', [$from, $to])
            ->count()
            + RechargeOrder::where('payment_id', $paymentId)
                ->where('status', RechargeOrder::STATUS_COMPLETED)
                ->whereBetween('paid_at', [$from, $to])
                ->count();
    }

    /**
     * 前 7 天（不含 $day）的拉取成功数与付款数；一次记录都没有时返回 null。
     *
     * @return array{ok: int, paid: int}|null
     */
    private function baseline(int $paymentId, Carbon $day): ?array
    {
        $ok = 0;
        for ($i = 1; $i <= 7; $i++) {
            $ok += PaymentLinkStats::day($day->copy()->subDays($i)->format('Ymd'))[$paymentId]['ok'] ?? 0;
        }
        if ($ok === 0) {
            return null;
        }
        $from = $day->copy()->subDays(7)->startOfDay()->timestamp;
        $to = $day->copy()->startOfDay()->timestamp - 1;
        return ['ok' => $ok, 'paid' => $this->paidCount($paymentId, $from, $to)];
    }

    /** 日报整体按 Telegram markdown 发：去掉会被当成格式符的字符（网关原话里常有 wxpay_h5 这类下划线） */
    private static function plain(string $text): string
    {
        return str_replace(['_', '*', '`', '[', ']'], ' ', $text);
    }

    private static function percent(int $part, int $whole): string
    {
        return $whole > 0 ? round($part * 100 / $whole) . '%' : '—';
    }
}
