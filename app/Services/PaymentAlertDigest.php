<?php

namespace App\Services;

use App\Jobs\SendEmailJob;
use App\Models\PaymentVerification;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 支付异常每日邮件：管理员不常看 Telegram（2026-10-24），自动处置的结果、待确认删号的名单、
 * 核验统计、新投诉每天发一封邮件，没看到告警也不会漏。payment:alert-digest 每天 9:00 发。
 *
 * 收件人：后台设置 payment_alert_emails（逗号分隔），没设就发给全部管理员。
 */
final class PaymentAlertDigest
{
    private const COMPLAINT_CACHE_KEY = 'payment_alert_digest:complaints';
    private const COMPLAINT_KEEP = 3 * 86400;
    private const LIST_LIMIT = 30;

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
        $lines = [];

        // 1) 这段时间里自动封号的
        $enforced = PaymentVerification::whereNotNull('enforce_snapshot')
            ->whereRaw('COALESCE(refunded_at, alerted_at, checked_at) BETWEEN ? AND ?', [$since, $until])
            ->orderBy('id')
            ->get();
        $lines[] = '【自动封号】' . ($enforced->isEmpty() ? '无' : $enforced->count() . ' 个');
        foreach ($enforced->take(self::LIST_LIMIT) as $row) {
            $lines[] = '- ' . $this->describe($row);
        }

        // 2) 已封号、还没删的（累计，处理掉才会消失）
        $pending = PaymentVerification::query()
            ->whereNotNull('enforce_snapshot')
            ->whereIn('user_id', User::where('banned', 1)->select('id'))
            ->orderBy('id')
            ->get();
        $lines[] = '';
        $lines[] = '【待你确认删号】' . ($pending->isEmpty() ? '无' : $pending->count() . ' 个（已封号，确认后到后台「用户」删除，或回复 Claude「执行删号 用户ID」）');
        foreach ($pending->take(self::LIST_LIMIT) as $row) {
            $lines[] = '- ' . $this->describe($row);
        }

        // 3) 付款核验统计
        $counts = PaymentVerification::whereBetween('checked_at', [$since, $until])
            ->select('verdict', DB::raw('count(*) as n'))->groupBy('verdict')->pluck('n', 'verdict')->all();
        $lines[] = '';
        $lines[] = '【付款核验】' . ($counts === [] ? '这段时间没有核验记录' : implode('，', array_map(
            fn ($verdict, $n) => (PaymentVerification::LABELS[$verdict] ?? $verdict) . ' ' . $n,
            array_keys($counts), $counts
        )));

        // 4) 支付投诉
        $complaints = array_values(array_filter(
            (array) Cache::get(self::COMPLAINT_CACHE_KEY, []),
            fn ($i) => (int) ($i['at'] ?? 0) >= $since && (int) ($i['at'] ?? 0) <= $until
        ));
        $lines[] = '';
        $lines[] = '【支付投诉】' . ($complaints === [] ? '无' : count($complaints) . ' 条（请到易支付商户后台处理，长时间不处理上游可能限制收款）');
        foreach (array_slice($complaints, 0, self::LIST_LIMIT) as $c) {
            $lines[] = sprintf('- 投诉 #%d %s ¥%s %s · 网关单号 %s', $c['id'], $c['pay_type'], $c['money'], $c['problem'], $c['trade_no']);
        }

        $needsAction = $pending->count() + count($complaints);
        $range = date('m-d H:i', $since) . ' ~ ' . date('m-d H:i', $until);
        array_unshift($lines, $needsAction > 0 ? "{$range}，有 {$needsAction} 项需要你处理：" : "{$range}，没有需要你处理的事项。", '');
        $lines[] = '';
        $lines[] = '自动处置说明：用户通过投诉等途径退款、或付款核验确认网关上没有这笔 / 金额不符时，系统已自动封号、收回套餐、重置订阅；'
            . '处置前的状态都留了快照，误判可以恢复。';

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

    private function describe(PaymentVerification $row): string
    {
        $user = $row->user_id ? User::find($row->user_id) : null;
        $at = $row->refunded_at ?? $row->alerted_at ?? $row->checked_at;
        $reason = $row->refunded_at !== null ? '网关已退款' : (PaymentVerification::LABELS[$row->verdict] ?? $row->verdict);
        $snapshot = (array) $row->enforce_snapshot;
        return sprintf(
            '%s %s（#%s）%s %s ¥%s —— %s%s',
            $at ? date('m-d H:i', (int) $at) : '',
            $user ? (string) $user->email : '用户已删除',
            $row->user_id ?? '-',
            PaymentEnforcement::kindOf($row),
            PaymentEnforcement::tradeNoOf($row),
            PaymentEnforcement::yuan((int) $row->expected_amount),
            $reason,
            isset($snapshot['plan_id']) ? '；原套餐 #' . $snapshot['plan_id'] : ''
        );
    }
}
