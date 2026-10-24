<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentVerification;
use App\Models\User;
use App\Support\AdminAlert;
use App\Support\EpayOrderQuery;
use App\Support\EpayV2;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * 退款回查与处置。
 *
 * 用户可以绕过我们，通过支付宝 / 微信投诉让网关把钱退回去，服务却照常在用。规则（2026-10-24 定）：
 * 查到退款 → 自动封号、收回套餐、重置订阅、踢下线，告警给管理员；管理员确认后删号，新老用户一律删。
 *
 * 发现途径：
 * - 回查：付款核验通过（verdict=ok）的单，付款后前 15 天每天向网关查一次，之后第 20/25/30/38/45 天再查
 *   （V1 / V2 都行）。投诉常在付款后七八天、十几天才来，前两周按天查，退款最晚隔天就能发现；
 * - 投诉：易支付 V2 有投诉时由 EpayGatewayMonitor 调 recheckByCallbackNo() 立即查，投诉未结案期间
 *   每 6 小时再查一次（V1 网关没有投诉接口，只能靠回查）；
 * - 付款核验当时就查到「未付 / 已退款」（verdict=refunded 且尚未处置）的单，同样复查后处置。
 *
 * 判定：V2 状态 2（已退款）或带部分退款金额、V1 状态 0（V1 网关退款后显示未支付）才算退款；
 * 处置前再查一次，两次一致才动手（网关偶发返回异常状态，不能凭一次误封付费用户）。
 * 状态 3（已冻结，多半有投诉在处理）只告警，不处置。
 */
final class RefundWatchService
{
    /**
     * 付款后第几秒回查一次网关：前 15 天每天一次，之后第 20、25、30、38、45 天（网关约保留 60 天）。
     * 按每天约 450 笔已付单算，稳定后每天约 9000 次查询，在 payment:refund-watch 每 10 分钟 150 笔的容量之内。
     */
    public const RECHECK_OFFSETS = [
        86400, 2 * 86400, 3 * 86400, 4 * 86400, 5 * 86400, 6 * 86400, 7 * 86400, 8 * 86400,
        9 * 86400, 10 * 86400, 11 * 86400, 12 * 86400, 13 * 86400, 14 * 86400, 15 * 86400,
        20 * 86400, 25 * 86400, 30 * 86400, 38 * 86400, 45 * 86400,
    ];
    /** 查询失败后多久重试 */
    private const ERROR_BACKOFF = 3600;

    /** @var callable(array, string): array */
    private $query;

    public function __construct(?callable $query = null)
    {
        $this->query = $query ?? [EpayOrderQuery::class, 'query'];
    }

    /**
     * @return array{checked: int, paid: int, refunded: int, frozen: int, errors: int}
     */
    public function run(int $limit = 100, int $sleepMs = 200): array
    {
        $stats = ['checked' => 0, 'paid' => 0, 'refunded' => 0, 'frozen' => 0, 'errors' => 0];
        foreach ($this->due(time(), $limit) as $item) {
            $outcome = $this->recheck($item['row'], $item['paid_at'], '付款后第 ' . self::daysSince($item['paid_at']) . ' 天回查');
            $stats['checked']++;
            $stats[$outcome]++;
            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }
        return $stats;
    }

    /**
     * 按网关交易号立即回查一笔（投诉触发）。找不到本站单据或没有核验记录时返回 null。
     */
    public function recheckByCallbackNo(string $callbackNo, string $source): ?string
    {
        if ($callbackNo === '') {
            return null;
        }
        $subject = $this->findSubjectByCallbackNo($callbackNo);
        if ($subject === null) {
            return null;
        }
        $row = PaymentVerification::where('subject_type', $subject['type'])->where('subject_id', $subject['id'])->first();
        if (!$row || $row->refunded_at !== null) {
            return null;
        }
        return $this->recheck($row, $subject['paid_at'], $source);
    }

    /**
     * @return 'paid'|'refunded'|'frozen'|'errors'
     */
    private function recheck(PaymentVerification $row, int $paidAt, string $source): string
    {
        $payment = Payment::find($row->payment_id);
        if (!$payment || (string) $payment->payment !== 'EPay' || (string) $row->callback_no === '') {
            $this->finish($row);
            return 'paid';
        }
        $config = (array) $payment->config;
        $v2 = EpayV2::enabledFor($config);

        $result = ($this->query)($config, (string) $row->callback_no);
        $kind = $this->classify($result, $v2);
        if ($kind === 'refunded') {
            // 两次一致才动手
            $second = ($this->query)($config, (string) $row->callback_no);
            $kind = $this->classify($second, $v2) === 'refunded' ? 'refunded' : 'errors';
            $result = $second;
        }

        switch ($kind) {
            case 'refunded':
                $this->enforce($row, $result, $source);
                return 'refunded';
            case 'frozen':
                if ($row->gateway_status !== 3) {
                    $row->gateway_status = 3;
                    $row->save();
                    $this->alertFrozen($row, $source);
                }
                $this->advance($row, $paidAt);
                return 'frozen';
            case 'paid':
                $row->gateway_status = 1;
                if ($row->verdict === PaymentVerification::VERDICT_REFUNDED) {
                    // 付款核验当时网关说未付，现在两次查都是已付：以网关现状为准，改回核验通过
                    $row->verdict = PaymentVerification::VERDICT_OK;
                    $row->last_error = mb_substr($source . '：网关现为已支付', 0, 255);
                }
                $this->advance($row, $paidAt);
                return 'paid';
            default:
                $row->last_error = mb_substr('退款回查失败：' . ($result['msg'] ?? ''), 0, 255);
                $row->recheck_at = time() + self::ERROR_BACKOFF;
                $row->save();
                return 'errors';
        }
    }

    /** @return 'paid'|'refunded'|'frozen'|'errors' */
    private function classify(array $result, bool $v2): string
    {
        if (($result['outcome'] ?? null) !== EpayOrderQuery::FOUND) {
            return 'errors';
        }
        $status = $result['status'];
        if ($v2) {
            if ($status === 2 || ($status === 1 && (int) ($result['refund_money'] ?? 0) > 0)) {
                return 'refunded';
            }
            if ($status === 3) {
                return 'frozen';
            }
            // V2 的 0 是「未支付」：已付的单变回未支付属于异常，不按退款处置，下一轮再看
            return $status === 1 ? 'paid' : 'errors';
        }
        // V1 网关退款后查单显示 status=0
        if ($status === 0) {
            return 'refunded';
        }
        return $status === 1 ? 'paid' : 'errors';
    }

    /** 本轮确认仍是已付：排到下一个回查点；全部回查点都过了就结束 */
    private function advance(PaymentVerification $row, int $paidAt): void
    {
        $now = time();
        $next = null;
        foreach (self::RECHECK_OFFSETS as $stage => $offset) {
            if ($paidAt + $offset > $now) {
                $next = $stage;
                break;
            }
        }
        if ($next === null) {
            $this->finish($row);
            return;
        }
        $row->recheck_stage = $next;
        $row->recheck_at = $paidAt + self::RECHECK_OFFSETS[$next];
        $row->save();
    }

    private function finish(PaymentVerification $row): void
    {
        $row->recheck_stage = count(self::RECHECK_OFFSETS);
        $row->recheck_at = null;
        $row->save();
    }

    /**
     * 封号、收回套餐、重置订阅、踢下线，并告警。删号留给管理员确认（后台「用户」里删除）。
     */
    private function enforce(PaymentVerification $row, array $result, string $source): void
    {
        $user = $row->user_id ? User::find($row->user_id) : null;
        $snapshot = null;
        $done = DB::transaction(function () use ($user, $row, $result, $source, &$snapshot) {
            // 投诉触发与定时回查可能同时处置同一笔：锁住核验行，已处置过的不再动（否则快照会被「已封禁」覆盖）
            $locked = PaymentVerification::lockForUpdate()->find($row->id);
            if (!$locked || $locked->refunded_at !== null) {
                return false;
            }
            if ($user) {
                $snapshot = $this->suspend($user, $row);
            }
            $locked->verdict = PaymentVerification::VERDICT_REFUNDED;
            $locked->gateway_status = $result['status'] ?? $locked->gateway_status;
            $locked->refunded_at = time();
            $locked->recheck_at = null;
            $locked->recheck_stage = count(self::RECHECK_OFFSETS);
            $locked->enforce_snapshot = $snapshot;
            $locked->last_error = mb_substr($source . '：网关已退款', 0, 255);
            $locked->save();
            $row->setRawAttributes($locked->getAttributes(), true);
            return true;
        });
        if (!$done) {
            return;
        }

        Log::warning('[refund-watch] 网关退款，已自动封号', ['subject' => $row->subject_type . ':' . $row->subject_id, 'user_id' => $row->user_id]);
        $this->alertRefunded($row, $user, $snapshot, $result, $source);
    }

    /** 封号、收回套餐、重置订阅、踢下线；返回处置前的状态快照。须在事务里调用 */
    private function suspend(User $user, PaymentVerification $row): array
    {
        $locked = User::lockForUpdate()->find($user->id);
        $snapshot = [
            'banned' => (int) $locked->banned, 'plan_id' => $locked->plan_id, 'expired_at' => $locked->expired_at,
            'transfer_enable' => $locked->transfer_enable, 'balance' => $locked->balance,
        ];
        $locked->banned = 1;
        $locked->plan_id = null;
        $locked->expired_at = time();
        $locked->transfer_enable = 0;
        // 旧订阅链接与节点凭据立即作废
        $locked->token = Helper::guid();
        $locked->uuid = Helper::guid(true);
        $locked->remarks = trim(((string) $locked->remarks) . "\n" . sprintf(
            '[%s 网关退款自动封禁：%s %s，待确认删号]',
            date('Y-m-d H:i'), $row->subject_type === PaymentVerification::SUBJECT_RECHARGE ? '充值单' : '订单', $this->tradeNoOf($row)
        ));
        $locked->save();
        PersonalAccessToken::where('tokenable_type', User::class)->where('tokenable_id', $locked->id)->delete();
        return $snapshot;
    }

    private function alertRefunded(PaymentVerification $row, ?User $user, ?array $snapshot, array $result, string $source): void
    {
        $esc = [TelegramService::class, 'escapeHtml'];
        $isRecharge = $row->subject_type === PaymentVerification::SUBJECT_RECHARGE;
        $lines = ['🚨 <b>用户通过网关退款，已自动封号</b>'];

        if ($user) {
            $paid = Order::where('user_id', $user->id)->whereIn('status', [Order::STATUS_COMPLETED, Order::STATUS_DISCOUNTED]);
            $lines[] = sprintf(
                '用户：%s（#%d）· 注册 %s · 累计已付 %d 单 ¥%s',
                $esc((string) $user->email), $user->id, date('Y-m-d', (int) $user->created_at),
                (clone $paid)->count(), self::yuan((int) (clone $paid)->sum('total_amount'))
            );
        } else {
            $lines[] = '用户：已不存在';
        }

        $lines[] = ($isRecharge ? '退款的充值单：' : '退款的订单：') . '<code>' . $esc($this->tradeNoOf($row)) . '</code> ¥'
            . self::yuan((int) $row->expected_amount)
            . ((int) ($result['refund_money'] ?? 0) > 0 ? '（部分退款 ¥' . self::yuan((int) $result['refund_money']) . '）' : '');
        $lines[] = '发现途径：' . $esc($source) . ' · 网关状态 ' . ($result['status'] ?? '?');

        $commission = $this->commissionOf($row);
        if ($commission !== null) {
            $lines[] = $commission;
        }
        if ($snapshot !== null) {
            $lines[] = sprintf(
                '已处理：封号、收回套餐（原套餐 #%s，到期 %s）、重置订阅、踢下线%s',
                $snapshot['plan_id'] ?? '无',
                $snapshot['expired_at'] ? date('Y-m-d', (int) $snapshot['expired_at']) : '长期',
                $isRecharge ? '；充值进去的余额 ¥' . self::yuan((int) $snapshot['balance']) . ' 未扣回' : ''
            );
            $lines[] = '确认删号：到后台「用户」里删除 #' . $user->id . '，或回复「执行删号 ' . $user->id . '」。'
                . '若属误判：解封并按上面的原值恢复套餐。';
        }
        AdminAlert::send(implode("\n", $lines), 'html');
    }

    private function alertFrozen(PaymentVerification $row, string $source): void
    {
        $esc = [TelegramService::class, 'escapeHtml'];
        $user = $row->user_id ? User::find($row->user_id) : null;
        AdminAlert::send(implode("\n", [
            '🧊 <b>网关冻结了一笔已付款</b>（多半有投诉在处理），暂不处置',
            ($row->subject_type === PaymentVerification::SUBJECT_RECHARGE ? '充值单：' : '订单：') . '<code>' . $esc($this->tradeNoOf($row)) . '</code> ¥' . self::yuan((int) $row->expected_amount),
            '用户：' . ($user ? $esc((string) $user->email) . '（#' . $user->id . '）' : '—'),
            '发现途径：' . $esc($source) . '。转成退款后会自动封号。',
        ]), 'html');
    }

    /** 这张单给邀请人发的佣金（订单表 / 充值表都有 commission_balance、invite_user_id） */
    private function commissionOf(PaymentVerification $row): ?string
    {
        $subject = $row->subject_type === PaymentVerification::SUBJECT_RECHARGE
            ? (Schema::hasTable('plugin_recharge_orders') ? DB::table('plugin_recharge_orders')->where('id', $row->subject_id)->first() : null)
            : Order::find($row->subject_id);
        if (!$subject || (int) ($subject->commission_balance ?? 0) <= 0 || !$subject->invite_user_id) {
            return null;
        }
        $inviter = User::find($subject->invite_user_id);
        $esc = [TelegramService::class, 'escapeHtml'];
        return '该单佣金：¥' . self::yuan((int) $subject->commission_balance) . ' → 邀请人 '
            . ($inviter ? $esc((string) $inviter->email) . '（#' . $inviter->id . '）' : '#' . $subject->invite_user_id)
            . '，是否扣回请自行决定';
    }

    private function tradeNoOf(PaymentVerification $row): string
    {
        if ($row->subject_type === PaymentVerification::SUBJECT_RECHARGE) {
            return Schema::hasTable('plugin_recharge_orders')
                ? (string) DB::table('plugin_recharge_orders')->where('id', $row->subject_id)->value('trade_no')
                : '';
        }
        return (string) Order::where('id', $row->subject_id)->value('trade_no');
    }

    /** @return array{type: string, id: int, paid_at: int}|null */
    private function findSubjectByCallbackNo(string $callbackNo): ?array
    {
        $order = Order::where('callback_no', $callbackNo)->first(['id', 'paid_at']);
        if ($order) {
            return ['type' => PaymentVerification::SUBJECT_ORDER, 'id' => (int) $order->id, 'paid_at' => (int) $order->paid_at];
        }
        if (Schema::hasTable('plugin_recharge_orders')) {
            $recharge = DB::table('plugin_recharge_orders')->where('callback_no', $callbackNo)->first(['id', 'paid_at']);
            if ($recharge) {
                return ['type' => PaymentVerification::SUBJECT_RECHARGE, 'id' => (int) $recharge->id, 'paid_at' => (int) $recharge->paid_at];
            }
        }
        return null;
    }

    /**
     * 到期该回查的单：核验通过的单按回查点；付款核验当时就判了「未付 / 已退款」但还没处置的单立即复查。
     *
     * @return list<array{row: PaymentVerification, paid_at: int}>
     */
    private function due(int $now, int $limit): array
    {
        $oldest = $now - max(self::RECHECK_OFFSETS) - 2 * 86400;
        $items = [];
        foreach ([[PaymentVerification::SUBJECT_ORDER, 'v2_order'], [PaymentVerification::SUBJECT_RECHARGE, 'plugin_recharge_orders']] as [$type, $table]) {
            if ($table !== 'v2_order' && !Schema::hasTable($table)) {
                continue;
            }
            $rows = DB::table('v2_payment_verification as v')
                ->join($table . ' as s', 's.id', '=', 'v.subject_id')
                ->where('v.subject_type', $type)
                ->whereNull('v.refunded_at')
                ->where('s.paid_at', '>=', $oldest)
                ->where(function ($q) use ($now) {
                    $q->where(function ($ok) use ($now) {
                        $ok->where('v.verdict', PaymentVerification::VERDICT_OK)
                            ->where(function ($when) use ($now) {
                                $when->where(function ($first) use ($now) {
                                    $first->whereNull('v.recheck_at')->where('v.recheck_stage', 0)
                                        ->where('s.paid_at', '<=', $now - self::RECHECK_OFFSETS[0]);
                                })->orWhere('v.recheck_at', '<=', $now);
                            });
                    })->orWhere(function ($refunded) use ($now) {
                        $refunded->where('v.verdict', PaymentVerification::VERDICT_REFUNDED)
                            ->where(fn ($w) => $w->whereNull('v.recheck_at')->orWhere('v.recheck_at', '<=', $now));
                    });
                })
                ->orderByRaw('COALESCE(v.recheck_at, s.paid_at)')
                ->limit($limit)
                ->get(['v.id', 's.paid_at']);
            foreach ($rows as $r) {
                $items[] = ['id' => (int) $r->id, 'paid_at' => (int) $r->paid_at];
            }
        }

        usort($items, fn ($a, $b) => $a['paid_at'] <=> $b['paid_at']);
        $items = array_slice($items, 0, $limit);
        $models = PaymentVerification::whereIn('id', array_column($items, 'id'))->get()->keyBy('id');
        return array_values(array_filter(array_map(
            fn ($item) => isset($models[$item['id']]) ? ['row' => $models[$item['id']], 'paid_at' => $item['paid_at']] : null,
            $items
        )));
    }

    private static function daysSince(int $paidAt): int
    {
        return max(1, (int) floor((time() - $paidAt) / 86400));
    }

    private static function yuan(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }
}
