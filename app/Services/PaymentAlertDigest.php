<?php

namespace App\Services;

use App\Jobs\SendEmailJob;
use App\Models\PaymentVerification;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 支付异常每日邮件：管理员不常看 Telegram（2026-10-24），自动封号、自动删号的结果、核验统计、
 * 新投诉每天发一封邮件，没看到告警也不会漏。payment:alert-digest 每天 9:00 发。
 *
 * 封号满 180 天由系统自动删号（2026-11-28 起，见 EnforcedUserPurge），邮件里不再列「待你确认删号」，
 * 只汇总封号待删的数量与 7 天内要删的人（误判还来得及解封）；需要处理的只剩投诉、扣不回的佣金、到期却没能自动删的。
 *
 * 收件人：后台设置 payment_alert_emails（逗号分隔），没设就发给全部管理员。
 */
final class PaymentAlertDigest
{
    private const COMPLAINT_CACHE_KEY = 'payment_alert_digest:complaints';
    private const COMPLAINT_KEEP = 3 * 86400;
    private const LIST_LIMIT = 30;
    /** 删号前多少天开始在邮件里点名，误判还来得及解封 */
    private const DELETE_NOTICE_DAYS = 7;

    private readonly EnforcedUserPurge $purge;

    public function __construct(?EnforcedUserPurge $purge = null)
    {
        $this->purge = $purge ?? app(EnforcedUserPurge::class);
    }

    /** 投诉告警时记一笔给日报用（只存摘要，不存手机号与投诉原文），缓存保留 3 天 */
    public static function recordComplaint(array $row): void
    {
        try {
            $items = Cache::get(self::COMPLAINT_CACHE_KEY, []);
            $items = array_values(array_filter(is_array($items) ? $items : [], fn ($i) => (int) ($i['at'] ?? 0) > time() - self::COMPLAINT_KEEP));
            $items[] = [
                'at' => time(),
                'id' => (int) ($row['id'] ?? 0),
                'pay_type' => (string) ($row['pay_type'] ?? ''),
                'money' => mb_substr((string) ($row['money'] ?? ''), 0, 16),
                'problem' => mb_substr((string) ($row['problem_type'] ?? ''), 0, 40),
                'status' => (int) ($row['status'] ?? 0),
                'trade_no' => mb_substr((string) ($row['trade_no'] ?? ''), 0, 64),
            ];
            Cache::put(self::COMPLAINT_CACHE_KEY, array_slice($items, -200), self::COMPLAINT_KEEP);
        } catch (\Throwable) {
            // 日报只是兜底，记不上不影响告警本身
        }
    }

    /** @return string[] */
    public function recipients(): array
    {
        $configured = [];
        foreach (preg_split('/[\s,，;]+/u', (string) admin_setting('payment_alert_emails', '')) ?: [] as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $configured[strtolower($email)] = true;
            }
        }
        if ($configured !== []) {
            return array_keys($configured);
        }
        return User::where('is_admin', 1)->whereNotNull('email')->pluck('email')
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()->values()->all();
    }

    /**
     * @return array{subject: string, content: string, needs_action: int}
     */
    public function build(int $since, int $until): array
    {
        $appName = (string) admin_setting('app_name', 'XBoard');
        $days = EnforcedUserPurge::DAYS;
        $lines = [];
        $needsAction = 0;

        // 1) 这段时间里自动封号的；佣金扣不回的要人工追
        $enforced = PaymentVerification::whereNotNull('enforce_snapshot')
            ->whereRaw('COALESCE(refunded_at, alerted_at, checked_at) BETWEEN ? AND ?', [$since, $until])
            ->orderBy('id')
            ->get();
        $lines[] = '【自动封号】' . ($enforced->isEmpty() ? '无' : $enforced->count() . ' 个');
        foreach ($enforced->take(self::LIST_LIMIT) as $row) {
            $lines[] = '- ' . $this->describe($row);
        }
        $needsAction += $enforced->filter(function (PaymentVerification $row) {
            $commission = ((array) $row->enforce_snapshot)['commission'] ?? null;
            return PaymentEnforcement::commissionShortOf(is_array($commission) ? $commission : null) > 0;
        })->count();

        // 2) 这段时间里封号满期、自动删掉的（同一用户多条处置记录只列一次）
        $deleted = PaymentVerification::whereBetween('user_deleted_at', [$since, $until])
            ->orderBy('user_deleted_at')->orderByDesc('id')
            ->get()->unique('user_id')->values();
        $lines[] = '';
        $lines[] = '【自动删号】' . ($deleted->isEmpty() ? '无' : $deleted->count() . " 个（封号满 {$days} 天）");
        foreach ($deleted->take(self::LIST_LIMIT) as $row) {
            $lines[] = '- ' . date('m-d H:i', (int) $row->user_deleted_at) . ' 已删号：' . $this->describe($row);
        }

        // 3) 还在封号、等着满期删的（累计）：只给数量，快到期的点名，到期没删掉的要人工看。
        //    删号任务每天跑一次，到期超过一天还在的才算「没能自动删」，刚到期、等今晚那一轮的仍算快到期
        $pending = $this->purge->pending();
        $overdue = $soon = [];
        foreach ($pending as $userId => $enforcedAt) {
            $deleteAt = EnforcedUserPurge::deleteAt($enforcedAt);
            if ($deleteAt <= $until - 86400) {
                $overdue[$userId] = $deleteAt;
            } elseif ($deleteAt <= $until + self::DELETE_NOTICE_DAYS * 86400) {
                $soon[$userId] = $deleteAt;
            }
        }
        $upcoming = array_diff_key($pending, $overdue);
        $lines[] = '';
        $lines[] = '【封号待删】' . ($pending === [] ? '无' : sprintf(
            '%d 个，封号满 %d 天系统自动删号，不用手动处理%s',
            count($pending), $days,
            $upcoming === [] ? '' : '；最早一个 ' . date('Y-m-d', EnforcedUserPurge::deleteAt((int) reset($upcoming))) . ' 到期'
        ));
        if ($soon !== []) {
            $lines[] = self::DELETE_NOTICE_DAYS . ' 天内到期 ' . count($soon) . ' 个（到期后自动删号；误判的请在此之前到后台解封，解封后不会删）：';
            foreach (array_slice($soon, 0, self::LIST_LIMIT, true) as $userId => $deleteAt) {
                $lines[] = '- ' . date('m-d', $deleteAt) . ' 到期：' . $this->describeUser($userId);
            }
        }
        if ($overdue !== []) {
            $needsAction += count($overdue);
            $lines[] = "封号已满 {$days} 天、但没能自动删的 " . count($overdue) . ' 个（请看原因后处理）：';
            foreach (array_slice($overdue, 0, self::LIST_LIMIT, true) as $userId => $deleteAt) {
                $user = User::find($userId);
                $reason = $user ? ($this->purge->blocker($user, $until) ?? '自动删号任务没跑到，请检查调度与日志') : '用户已不存在';
                $lines[] = '- ' . $this->describeUser($userId) . '（' . $reason . '）';
            }
        }

        // 4) 付款核验统计
        $counts = PaymentVerification::whereBetween('checked_at', [$since, $until])
            ->select('verdict', DB::raw('count(*) as n'))->groupBy('verdict')->pluck('n', 'verdict')->all();
        $lines[] = '';
        $lines[] = '【付款核验】' . ($counts === [] ? '这段时间没有核验记录' : implode('，', array_map(
            fn ($verdict, $n) => (PaymentVerification::LABELS[$verdict] ?? $verdict) . ' ' . $n,
            array_keys($counts), $counts
        )));

        // 5) 支付投诉
        $complaints = array_values(array_filter(
            (array) Cache::get(self::COMPLAINT_CACHE_KEY, []),
            fn ($i) => (int) ($i['at'] ?? 0) >= $since && (int) ($i['at'] ?? 0) <= $until
        ));
        $lines[] = '';
        $lines[] = '【支付投诉】' . ($complaints === [] ? '无' : count($complaints) . ' 条（请到易支付商户后台处理，长时间不处理上游可能限制收款）');
        foreach (array_slice($complaints, 0, self::LIST_LIMIT) as $c) {
            $lines[] = sprintf('- 投诉 #%d %s ¥%s %s · 网关单号 %s', $c['id'], $c['pay_type'], $c['money'], $c['problem'], $c['trade_no']);
        }
        $needsAction += count($complaints);

        $range = date('m-d H:i', $since) . ' ~ ' . date('m-d H:i', $until);
        array_unshift($lines, $needsAction > 0 ? "{$range}，有 {$needsAction} 项需要你处理：" : "{$range}，没有需要你处理的事项。", '');
        $lines[] = '';
        $lines[] = '自动处置说明：用户通过投诉等途径退款、或付款核验确认网关上没有这笔 / 金额不符时，系统已自动封号、收回套餐、重置订阅；'
            . "封号满 {$days} 天后自动删号。处置前的状态都留了快照，误判的在删号前解封即可恢复（解封后不会删）；"
            . '删号前的邮箱、单号也记进了核验记录，删了也能追溯。';

        return [
            'subject' => ($needsAction > 0 ? "支付日报：{$needsAction} 项需处理" : '支付日报：一切正常') . ' - ' . $appName,
            'content' => implode("\n", $lines),
            'needs_action' => $needsAction,
        ];
    }

    /** @return int 发给了几个收件人 */
    public function send(int $since, int $until): int
    {
        $digest = $this->build($since, $until);
        $recipients = $this->recipients();
        foreach ($recipients as $email) {
            SendEmailJob::dispatch([
                'email' => $email,
                'subject' => $digest['subject'],
                'template_name' => 'notify',
                'template_value' => [
                    'name' => admin_setting('app_name', 'XBoard'),
                    'url' => admin_setting('app_url'),
                    'content' => $digest['content'],
                ],
            ]);
        }
        return count($recipients);
    }

    /** 这个用户最近一次自动处置的那条记录 */
    private function describeUser(int $userId): string
    {
        $row = PaymentVerification::whereNotNull('enforce_snapshot')->where('user_id', $userId)
            ->orderByRaw('COALESCE(refunded_at, alerted_at, checked_at) DESC')->orderByDesc('id')->first();
        return $row ? $this->describe($row) : '#' . $userId;
    }

    private function describe(PaymentVerification $row): string
    {
        $user = $row->user_id ? User::find($row->user_id) : null;
        $at = $row->refunded_at ?? $row->alerted_at ?? $row->checked_at;
        $reason = $row->refunded_at !== null ? '网关已退款' : (PaymentVerification::LABELS[$row->verdict] ?? $row->verdict);
        $snapshot = (array) $row->enforce_snapshot;
        // 自动删号后用户没了，邮箱在删前记进了快照
        $deletedEmail = isset($snapshot['deleted']['email']) ? (string) $snapshot['deleted']['email'] : null;
        return sprintf(
            '%s %s（#%s）%s %s ¥%s —— %s%s%s',
            $at ? date('m-d H:i', (int) $at) : '',
            $user ? (string) $user->email : ($deletedEmail ?? '用户已删除'),
            $row->user_id ?? '-',
            PaymentEnforcement::kindOf($row),
            PaymentEnforcement::tradeNoOf($row),
            PaymentEnforcement::yuan((int) $row->expected_amount),
            $reason,
            isset($snapshot['plan_id']) ? '；原套餐 #' . $snapshot['plan_id'] : '',
            PaymentEnforcement::commissionSummary(isset($snapshot['commission']) ? (array) $snapshot['commission'] : null)
        );
    }
}
