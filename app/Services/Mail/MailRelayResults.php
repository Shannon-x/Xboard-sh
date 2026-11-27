<?php

namespace App\Services\Mail;

use App\Jobs\SendBillingMailJob;
use App\Models\BillingDocument;
use App\Models\User;
use App\Services\Billing\BillingArchive;
use App\Services\Billing\BillingDocumentService;
use Illuminate\Support\Facades\Log;

/**
 * 投递服务写回的一条结果 → 面板这边的账：v2_mail_log、用户退信标记（DeliveryMonitor）、
 * 收据 / 账单的「已发送」状态，以及退信时的 Telegram 兜底（与直发时 SendBillingMailJob::notify 的口径一样）。
 *
 * 结果字段（mail-relay/src/store.rs push_result）：id、lane、status（sent / failed / expired）、
 * class（ok / permanent / transient / config / throttled / internal / expired）、code、error、attempts、meta、at
 */
final class MailRelayResults
{
    public function __construct(private BillingArchive $archive, private BillingDocumentService $docs)
    {
    }

    /** @return string 记下的分类（DeliveryMonitor::* ），坏结果返回 '' */
    public function handle(array $r): string
    {
        $meta = json_decode((string) ($r['meta'] ?? ''), true);
        if (!is_array($meta) || !isset($meta['email'])) {
            Log::warning('[mail] 投递服务结果缺少 meta，跳过', ['id' => $r['id'] ?? null, 'status' => $r['status'] ?? null, 'class' => $r['class'] ?? null]);
            return '';
        }
        $email = (string) $meta['email'];
        $subject = (string) ($meta['subject'] ?? '');
        $view = (string) ($meta['view'] ?? '');
        $userId = isset($meta['user_id']) ? (int) $meta['user_id'] : null;
        $status = (string) ($r['status'] ?? '');
        $class = (string) ($r['class'] ?? '');

        if ($status === 'expired') {
            DeliveryMonitor::recordExpired($email, $subject, $view, max(0, (int) ($r['at'] ?? time()) - (int) ($meta['not_after'] ?? 0)), $userId);
            return DeliveryMonitor::EXPIRED;
        }
        if ($status === 'sent') {
            $category = DeliveryMonitor::record($email, $subject, $view, null, $userId);
            $this->billingSent($meta);
            return $category;
        }
        $error = trim((string) ($r['error'] ?? '')) ?: 'relay: ' . ($class ?: 'failed');
        // 我们自己的问题（认证、TLS、组信 / spool 出错）不算在用户头上：按 config 记，不碰退信计数
        $force = in_array($class, ['config', 'internal'], true) ? DeliveryMonitor::CONFIG : null;
        $category = DeliveryMonitor::record($email, $subject, $view, $error, $userId, $force);
        if ($category === DeliveryMonitor::CONFIG) {
            Log::error('[mail] 投递服务因配置问题放弃了一封信', ['email' => $email, 'view' => $view, 'class' => $class, 'error' => $error]);
        } else {
            // 退信，或投递服务重试用完仍是临时失败：和直发时一样转 Telegram
            $this->billingFallback($meta);
        }
        return $category;
    }

    private function billingSent(array $meta): void
    {
        $doc = $this->doc($meta);
        if ($doc) {
            $this->archive->markSent($doc, BillingDocument::CHANNEL_EMAIL);
        }
    }

    private function billingFallback(array $meta): void
    {
        $b = $meta['billing'] ?? null;
        if (!is_array($b) || empty($b['user_id'])) {
            return;
        }
        $user = User::find((int) $b['user_id']);
        if (!$user) {
            return;
        }
        $doc = $this->doc($meta);
        $data = is_array($b['tg'] ?? null) ? $b['tg'] : [];
        $pdf = null;
        if ($doc && !empty($b['pdf'])) {
            try {
                $snapshot = $this->archive->data($doc, $this->docs);
                if ($snapshot !== null) {
                    $data = $snapshot + $data;
                }
                $pdf = $this->archive->contents($doc, $this->docs);
            } catch (\Throwable $e) {
                Log::warning('[billing] 转 Telegram 时重新生成 PDF 失败，只发文字', ['document_id' => $doc->id, 'error' => $e->getMessage()]);
            }
        }
        if (SendBillingMailJob::telegramFallback($user, $data, $pdf, $this->docs, (string) ($b['kind'] ?? ''))) {
            if ($doc) {
                $this->archive->markSent($doc, BillingDocument::CHANNEL_TELEGRAM);
            }
            return;
        }
        Log::info('[billing] 邮件未能送达且无 Telegram，文件仅留在面板', ['user_id' => $user->id, 'kind' => $b['kind'] ?? null, 'document_id' => $doc?->id]);
    }

    private function doc(array $meta): ?BillingDocument
    {
        $id = $meta['billing']['doc_id'] ?? null;
        return $id ? BillingDocument::find((int) $id) : null;
    }
}
