<?php

namespace App\Services;

use App\Models\BalanceLog;
use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\PaymentVerification;
use App\Models\RechargeOrder;
use App\Models\User;
use App\Support\AdminAlert;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * 付款异常的账户处置，退款回查（RefundWatchService）与付款事后核验（PaymentVerificationService）共用：
 * 封号、收回套餐、重置订阅、踢下线，处置前的状态存进核验行的 enforce_snapshot，误判时照此恢复。
 * 订单带来的邀请佣金一并收回：还没发的作废，已经发了的从邀请人那里扣回（见 revokeCommission）。
 *
 * 管理员不常看 Telegram（2026-10-24）：所以确认异常就直接处置，不等人看；告警同时推给客服，
 * 并进每日邮件汇总（PaymentAlertDigest）。删号不在这里做，由管理员看过后在后台确认。
 */
final class PaymentEnforcement
{
    /**
     * 处置一条核验记录对应的用户。同一条记录只处置一次（锁住核验行判断，退款回查与投诉触发并发也安全）。
     *
     * @param string $reason 写进用户备注的原因，如「网关退款」「付款核验异常」
     * @param callable(PaymentVerification): void $mark 在同一事务里更新核验行（结论、时间等）
     * @return array{user: ?User, snapshot: array}|null 已处置过返回 null；用户已不存在时 snapshot=['user_missing' => true]
     */
    public function enforce(PaymentVerification $row, string $reason, callable $mark): ?array
    {
        return DB::transaction(function () use ($row, $reason, $mark) {
            $locked = PaymentVerification::lockForUpdate()->find($row->id);
            if (!$locked || $locked->refunded_at !== null || $locked->enforce_snapshot !== null) {
                return null;
            }
            // 先处理佣金再封号：锁顺序与佣金结算（订单 → 邀请人）一致
            $commission = $this->revokeCommission($locked);
            $user = $locked->user_id ? User::find($locked->user_id) : null;
            $snapshot = $user
                ? $this->suspend($user, sprintf(
                    '[%s %s自动封禁：%s %s，待确认删号]',
                    date('Y-m-d H:i'), $reason, self::kindOf($locked), self::tradeNoOf($locked)
                ))
                : ['user_missing' => true];
            if ($commission !== null) {
                $snapshot['commission'] = $commission;
            }
            $mark($locked);
            $locked->enforce_snapshot = $snapshot;
            $locked->save();
            $row->setRawAttributes($locked->getAttributes(), true);
            return ['user' => $user, 'snapshot' => $snapshot];
        });
    }

    /**
     * 收回这张订单带来的邀请佣金。须在事务里调用。
     *
     * - 还没发（待确认 / 发放中）：佣金状态置为无效，结算任务不会再发；
     * - 已经发了：按佣金记录逐个邀请人扣回，先扣佣金余额，不够再扣账户余额（佣金可能已划转到余额），
     *   都不够的部分（多半已提现）记为未扣回，交给管理员处理；佣金状态同样置为无效。
     *
     * 充值单不返佣，不处理。返回写进 enforce_snapshot 的记录，误判时照此退回；没有可处理的佣金返回 null。
     *
     * @return array{trade_no: string, status_before: int, amount: int, clawback: list<array{invite_user_id: int, amount: int, from_commission: int, from_balance: int, short: int}>}|null
     */
    public function revokeCommission(PaymentVerification $row): ?array
    {
        if ($row->subject_type !== PaymentVerification::SUBJECT_ORDER) {
            return null;
        }
        $order = Order::lockForUpdate()->find($row->subject_id);
        if (!$order || (int) $order->commission_status === self::COMMISSION_INVALID) {
            return null;
        }
        $statusBefore = (int) $order->commission_status;
        $clawback = [];
        if ($statusBefore === self::COMMISSION_PAID) {
            $logs = CommissionLog::where('trade_no', $order->trade_no)->orderBy('id')->get();
            foreach ($logs as $log) {
                $clawback[] = $this->clawBack($log, $order);
            }
        }
        if ($clawback === [] && (!$order->invite_user_id || (int) ($order->commission_balance ?? 0) <= 0)) {
            return null;
        }
        $order->commission_status = self::COMMISSION_INVALID;
        $order->save();

        return [
            'trade_no' => (string) $order->trade_no,
            'status_before' => $statusBefore,
            'amount' => $statusBefore === self::COMMISSION_PAID
                ? (int) array_sum(array_column($clawback, 'amount'))
                : (int) ($order->commission_balance ?? 0),
            'clawback' => $clawback,
        ];
    }

    /** 从一位邀请人那里扣回一条佣金记录的金额，余额不为负 */
    private function clawBack(CommissionLog $log, Order $order): array
    {
        $amount = max(0, (int) $log->get_amount);
        $inviter = $log->invite_user_id ? User::lockForUpdate()->find($log->invite_user_id) : null;
        if (!$inviter || $amount === 0) {
            return [
                'invite_user_id' => (int) $log->invite_user_id, 'amount' => $amount,
                'from_commission' => 0, 'from_balance' => 0, 'short' => $amount,
            ];
        }
        $fromCommission = min($amount, max(0, (int) $inviter->commission_balance));
        $fromBalance = min($amount - $fromCommission, max(0, (int) $inviter->balance));
        $inviter->commission_balance = (int) $inviter->commission_balance - $fromCommission;
        $inviter->balance = (int) $inviter->balance - $fromBalance;
        $inviter->save();
        if ($fromBalance > 0) {
            BalanceLedger::record($inviter, BalanceLog::TYPE_ADMIN_ADJUST, -$fromBalance,
                BalanceLedger::orderCtx($order, '邀请订单退款，扣回已划转到余额的佣金'));
        }
        return [
            'invite_user_id' => (int) $inviter->id, 'amount' => $amount,
            'from_commission' => $fromCommission, 'from_balance' => $fromBalance,
            'short' => $amount - $fromCommission - $fromBalance,
        ];
    }

    /** 订单 commission_status：2 已发放，3 无效（与后台订单管理一致） */
    private const COMMISSION_PAID = 2;
    private const COMMISSION_INVALID = 3;

    /** 封号、收回套餐、重置订阅、踢下线；返回处置前的状态快照。须在事务里调用 */
    public function suspend(User $user, string $remark): array
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
        $locked->remarks = trim(((string) $locked->remarks) . "\n" . $remark);
        $locked->save();
        PersonalAccessToken::where('tokenable_type', User::class)->where('tokenable_id', $locked->id)->delete();
        return $snapshot;
    }

    /**
     * 处置完成的告警（HTML），同时推给客服。
     *
     * @param list<string> $details 已转义的说明行（发现途径、核验结论等）
     */
    public function alert(PaymentVerification $row, ?User $user, array $snapshot, string $headline, array $details): void
    {
        $esc = [TelegramService::class, 'escapeHtml'];
        $isRecharge = $row->subject_type === PaymentVerification::SUBJECT_RECHARGE;
        $lines = [$headline];

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
        $lines[] = self::kindOf($row) . '：<code>' . $esc(self::tradeNoOf($row)) . '</code> ¥' . self::yuan((int) $row->expected_amount);
        array_push($lines, ...$details);

        $commission = self::commissionLine($row, $snapshot['commission'] ?? null);
        if ($commission !== null) {
            $lines[] = $commission;
        }
        if ($user && empty($snapshot['user_missing'])) {
            $lines[] = sprintf(
                '已处理：封号、收回套餐（原套餐 #%s，到期 %s）、重置订阅、踢下线%s',
                $snapshot['plan_id'] ?? '无',
                $snapshot['expired_at'] ? date('Y-m-d', (int) $snapshot['expired_at']) : '长期',
                $isRecharge ? '；充值进去的余额 ¥' . self::yuan((int) $snapshot['balance']) . ' 未扣回' : ''
            );
            $lines[] = '确认删号：到后台「用户」里删除 #' . $user->id . '，或回复「执行删号 ' . $user->id . '」。'
                . '若属误判：解封并按上面的原值恢复套餐' . (isset($snapshot['commission']) ? '，佣金按核验记录里的快照退回' : '') . '。';
        }
        AdminAlert::send(implode("\n", $lines), 'html', true);
    }

    public static function kindOf(PaymentVerification $row): string
    {
        return $row->subject_type === PaymentVerification::SUBJECT_RECHARGE ? '充值单' : '订单';
    }

    public static function tradeNoOf(PaymentVerification $row): string
    {
        if ($row->subject_type === PaymentVerification::SUBJECT_RECHARGE) {
            return (string) RechargeOrder::where('id', $row->subject_id)->value('trade_no');
        }
        return (string) Order::where('id', $row->subject_id)->value('trade_no');
    }

    /**
     * 这张单的邀请佣金怎么处理的（HTML 已转义）。$revoked 是 revokeCommission 的结果；
     * 没给（老数据 / 没走处置）时只说明佣金额度与邀请人。
     */
    public static function commissionLine(PaymentVerification $row, ?array $revoked = null): ?string
    {
        $esc = [TelegramService::class, 'escapeHtml'];
        $who = static function (int $id) use ($esc): string {
            $inviter = User::find($id);
            return $inviter ? $esc((string) $inviter->email) . '（#' . $inviter->id . '）' : '#' . $id;
        };
        if ($revoked !== null) {
            if ((int) $revoked['status_before'] !== self::COMMISSION_PAID) {
                return '该单佣金：¥' . self::yuan((int) $revoked['amount']) . ' 还没发放，已作废';
            }
            $parts = [];
            foreach ($revoked['clawback'] as $c) {
                $part = $who((int) $c['invite_user_id']) . ' ¥' . self::yuan((int) $c['amount']);
                $taken = (int) $c['from_commission'] + (int) $c['from_balance'];
                if ((int) $c['short'] > 0) {
                    $part .= '，扣回 ¥' . self::yuan($taken) . '，还差 ¥' . self::yuan((int) $c['short']) . ' 扣不回（多半已提现），请人工处理';
                } elseif ((int) $c['from_balance'] > 0) {
                    $part .= '，已扣回（其中 ¥' . self::yuan((int) $c['from_balance']) . ' 从余额扣）';
                } else {
                    $part .= '，已从佣金余额扣回';
                }
                $parts[] = $part;
            }
            return '该单佣金已发放，已作废并扣回：' . ($parts === [] ? '无佣金记录' : implode('；', $parts));
        }
        $subject = $row->subject_type === PaymentVerification::SUBJECT_RECHARGE
            ? RechargeOrder::find($row->subject_id)
            : Order::find($row->subject_id);
        if (!$subject || (int) ($subject->commission_balance ?? 0) <= 0 || !$subject->invite_user_id) {
            return null;
        }
        return '该单佣金：¥' . self::yuan((int) $subject->commission_balance) . ' → 邀请人 ' . $who((int) $subject->invite_user_id);
    }

    /** 日报里的一句话：佣金作废 / 扣回了多少，扣不回的金额 */
    public static function commissionSummary(?array $revoked): string
    {
        if ($revoked === null) {
            return '';
        }
        if ((int) $revoked['status_before'] !== self::COMMISSION_PAID) {
            return '；佣金 ¥' . self::yuan((int) $revoked['amount']) . ' 已作废';
        }
        $short = (int) array_sum(array_column($revoked['clawback'], 'short'));
        return '；佣金 ¥' . self::yuan((int) $revoked['amount']) . ' 已扣回'
            . ($short > 0 ? '，¥' . self::yuan($short) . ' 扣不回需人工处理' : '');
    }

    public static function yuan(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }
}
