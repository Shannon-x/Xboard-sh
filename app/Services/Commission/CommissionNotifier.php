<?php

namespace App\Services\Commission;

use App\Jobs\SendEmailJob;
use App\Jobs\SendTelegramJob;
use App\Models\User;
use App\Services\Notification\NotificationPreference;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

/**
 * 佣金入账汇总通知（C1）：每个邀请人一封邮件（已绑定 Telegram 的再推一条纯文本），三级分销时上级各收各的。
 *
 * 多久一封看 commission_notify_frequency（默认 weekly）：
 *   instant  CheckCommission 每分钟一轮，一轮里的入账攒起来、整轮结束每人一封（实际上接近一笔一封）
 *   daily    每天 12:00 由 commission:notify-digest 汇总上次以来的入账（CommissionDigest）
 *   weekly   同上，只在周一发
 *
 * instant 攒的时候分两步：payHandle 在结算事务里只往「草稿」追加（collect），事务提交成功才并入待发列表（commit），
 * 回滚的（CommissionLog 唯一索引冲突、锁超时）丢弃（discard），不会发出「已入账」的假通知。
 * daily / weekly 直接读已落库的 CommissionLog，天然只含提交成功的。
 *
 * 发不发：commission_notify_enable（默认开）→ 用户偏好类别 commission（推广与佣金）→ 邮箱未退信。
 * 通知失败只记日志，绝不影响已经落账的佣金。
 */
final class CommissionNotifier
{
    public const TEMPLATE = 'commissionCredited';

    public const FREQUENCY_INSTANT = 'instant';
    public const FREQUENCY_DAILY = 'daily';
    public const FREQUENCY_WEEKLY = 'weekly';
    public const FREQUENCIES = [self::FREQUENCY_INSTANT, self::FREQUENCY_DAILY, self::FREQUENCY_WEEKLY];

    /** 一封里最多列多少笔，再多的只给个数（每周汇总时活跃的推广者可能有几十笔） */
    public const MAX_ROWS = 30;

    /** @var array<int, list<array{amount:int, user_id:int, trade_no:string, level:int, time:int}>> 已提交、待发送：inviter id → 入账明细 */
    private array $pending = [];
    /** @var array<int, list<array{amount:int, user_id:int, trade_no:string, level:int, time:int}>> 当前事务内的草稿 */
    private array $draft = [];

    public static function enabled(): bool
    {
        return (bool) (int) admin_setting('commission_notify_enable', 1);
    }

    public static function frequency(): string
    {
        $frequency = (string) admin_setting('commission_notify_frequency', self::FREQUENCY_WEEKLY);
        return in_array($frequency, self::FREQUENCIES, true) ? $frequency : self::FREQUENCY_WEEKLY;
    }

    /** payHandle 每发一笔调一次（事务内，只记内存） */
    public function collect(int $inviterId, int $amount, int $buyerId, string $tradeNo, int $level): void
    {
        $this->draft[$inviterId][] = [
            'amount' => $amount,
            'user_id' => $buyerId,
            'trade_no' => $tradeNo,
            'level' => $level,
            'time' => time(),
        ];
    }

    /** 该订单的结算事务已提交：草稿并入待发 */
    public function commit(): void
    {
        foreach ($this->draft as $inviterId => $items) {
            foreach ($items as $item) {
                $this->pending[$inviterId][] = $item;
            }
        }
        $this->draft = [];
    }

    /** 该订单的结算事务回滚：草稿丢弃 */
    public function discard(): void
    {
        $this->draft = [];
    }

    /** 一轮结束：instant 时每个邀请人一封；daily / weekly 交给 commission:notify-digest */
    public function flush(): void
    {
        $pending = $this->pending;
        $this->pending = [];
        $this->draft = [];
        if ($pending === [] || !self::enabled() || self::frequency() !== self::FREQUENCY_INSTANT) {
            return;
        }
        $this->deliver($pending, self::FREQUENCY_INSTANT);
    }

    /**
     * 按邀请人逐个发送。明细里的被邀请人邮箱在这里查出来再打码，调用方只给 user_id。
     *
     * @param array<int, list<array{amount:int, user_id:int, trade_no:string, level:int, time:int}>> $byInviter
     * @return int 实际调用了发送的邀请人数（偏好关掉 / 用户不存在的不算）
     */
    public function deliver(array $byInviter, string $frequency): int
    {
        $buyerIds = [];
        foreach ($byInviter as $items) {
            foreach ($items as $item) {
                $buyerIds[$item['user_id']] = true;
            }
        }
        $emails = [];
        foreach (array_chunk(array_keys($buyerIds), 1000) as $ids) {
            $emails += User::whereIn('id', $ids)->pluck('email', 'id')->all();
        }
        $sent = 0;
        foreach ($byInviter as $inviterId => $items) {
            try {
                foreach ($items as &$item) {
                    $item['email'] = EmailMask::mask($emails[$item['user_id']] ?? null);
                }
                unset($item);
                if ($this->notify((int) $inviterId, $items, $frequency)) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                Log::warning('[commission] 入账通知发送失败', ['invite_user_id' => $inviterId, 'msg' => $e->getMessage()]);
            }
        }
        return $sent;
    }

    private function notify(int $inviterId, array $items, string $frequency): bool
    {
        $user = User::find($inviterId);
        if (!$user) {
            return false;
        }
        if (!NotificationPreference::allows($user, NotificationPreference::COMMISSION)) {
            return false;
        }

        $total = (int) array_sum(array_column($items, 'amount'));
        $count = count($items);
        $appName = admin_setting('app_name', 'XBoard');
        $appUrl = rtrim((string) admin_setting('app_url', ''), '/');
        $inviteUrl = $appUrl . '/invite';
        $totalText = self::money($total);
        $vars = ['count' => $count, 'amount' => $totalText];
        switch ($frequency) {
            case self::FREQUENCY_WEEKLY:
                $heading = __('Weekly commission summary');
                $title = __('Weekly commission summary :amount', $vars);
                $summary = __(':count commission payment(s) totalling :amount were credited to your commission balance in the past week', $vars);
                $subtitle = __(':count payment(s) in the past week, credited to your commission balance', $vars);
                break;
            case self::FREQUENCY_DAILY:
                $heading = __('Daily commission summary');
                $title = __('Daily commission summary :amount', $vars);
                $summary = __(':count commission payment(s) totalling :amount were credited to your commission balance in the past day', $vars);
                $subtitle = __(':count payment(s) in the past day, credited to your commission balance', $vars);
                break;
            default:
                $heading = __('Commission credited');
                $title = __('Commission credited :amount', $vars);
                $summary = __(':count commission payment(s) totalling :amount have been credited to your commission balance', $vars);
                $subtitle = __(':count payment(s) this round, credited to your commission balance', $vars);
        }

        // 只列最近的 MAX_ROWS 笔，多出来的给个数
        $more = max(0, $count - self::MAX_ROWS);
        if ($more > 0) {
            $items = array_slice($items, -self::MAX_ROWS);
        }

        $rows = [];
        $lines = [];
        foreach ($items as $item) {
            $row = [
                'email' => $item['email'],
                'amount' => self::money((int) $item['amount']),
                'time' => date('Y-m-d H:i', (int) $item['time']),
                'level' => (int) $item['level'],
                'trade_no' => $item['trade_no'],
            ];
            $rows[] = $row;
            $line = $row['email'] . '  ' . $row['amount'] . '  ' . $row['time'];
            if ($row['level'] > 1) {
                $line .= '  ' . __('Level :level', ['level' => $row['level']]);
            }
            $lines[] = $line;
        }
        $moreText = $more > 0 ? __(':count more not listed, see them all on the invite page', ['count' => $more]) : null;
        if ($moreText !== null) {
            $lines[] = $moreText;
        }
        $content = $summary . "\n\n" . implode("\n", $lines) . "\n\n" . __('View commissions') . '：' . $inviteUrl;
        // withdraw_close_enable 时佣金直接进余额，就不展示「当前可用佣金」这一行
        $balance = (int) admin_setting('withdraw_close_enable', 0) ? null : self::money((int) ($user->commission_balance ?? 0));

        if ($user->email && $user->mail_suppressed_at === null) {
            $theme = admin_setting('email_template', 'default');
            // 线上 resources/views/mail 是宿主机挂载的，模板没同步过去时退回通用 notify 模板（纯文本正文）
            $template = View::exists("mail.{$theme}." . self::TEMPLATE) ? self::TEMPLATE : 'notify';
            SendEmailJob::dispatch([
                'email' => $user->email,
                'subject' => $title . ' - ' . $appName,
                'template_name' => $template,
                'template_value' => [
                    'name' => $appName,
                    'url' => $appUrl,
                    'content' => $content,
                    // 专用模板用的结构化字段
                    'heading' => $heading,
                    'summary' => $summary,
                    'subtitle' => $subtitle,
                    'total' => $totalText,
                    'count' => $count,
                    'items' => $rows,
                    'more' => $moreText,
                    'invite_url' => $inviteUrl,
                    'balance' => $balance,
                ],
                'user_id' => (int) $user->id,
                'category' => NotificationPreference::COMMISSION,
            ]);
        }
        if ($user->telegram_id) {
            SendTelegramJob::dispatch((int) $user->telegram_id, $title . "\n" . $content, '');
        }
        return true;
    }

    public static function money(int $cents): string
    {
        return (string) admin_setting('currency_symbol', '¥') . number_format($cents / 100, 2, '.', '');
    }
}
