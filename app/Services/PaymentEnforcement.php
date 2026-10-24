<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentVerification;
use App\Models\User;
use App\Support\AdminAlert;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * 付款异常的账户处置，退款回查（RefundWatchService）与付款事后核验（PaymentVerificationService）共用：
 * 封号、收回套餐、重置订阅、踢下线，处置前的状态存进核验行的 enforce_snapshot，误判时照此恢复。
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
            $user = $locked->user_id ? User::find($locked->user_id) : null;
            $snapshot = $user
                ? $this->suspend($user, sprintf(
                    '[%s %s自动封禁：%s %s，待确认删号]',
                    date('Y-m-d H:i'), $reason, self::kindOf($locked), self::tradeNoOf($locked)
                ))
                : ['user_missing' => true];
            $mark($locked);
            $locked->enforce_snapshot = $snapshot;
            $locked->save();
            $row->setRawAttributes($locked->getAttributes(), true);
            return ['user' => $user, 'snapshot' => $snapshot];
        });
    }

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

        $commission = self::commissionLine($row);
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
                . '若属误判：解封并按上面的原值恢复套餐。';
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
            return Schema::hasTable('plugin_recharge_orders')
                ? (string) DB::table('plugin_recharge_orders')->where('id', $row->subject_id)->value('trade_no')
                : '';
        }
        return (string) Order::where('id', $row->subject_id)->value('trade_no');
    }

    /** 这张单给邀请人发的佣金（订单表 / 充值表都有 commission_balance、invite_user_id），HTML 已转义 */
    public static function commissionLine(PaymentVerification $row): ?string
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

    public static function yuan(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }
}
