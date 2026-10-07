<?php

namespace App\Console\Commands;

use App\Models\BillingDocument;
use App\Services\Billing\BillingDocumentService;
use Illuminate\Console\Command;

/**
 * 可选的账单记录清理（默认不清）。收据 / 账单只存约 2 KB 的内容快照、不存 PDF 文件，
 * 默认全部永久保留，用户能看到完整的账单历史。
 *
 * 后台把 billing_invoice_retention_days 设成 N 后：到期日早于 N 天前、且已续费 / 已失效的账单记录删除
 * （续费的那一单自有收据）；还可能付款的（open）不动。收据始终保留，它是付款凭证。
 */
class PruneBillingDocuments extends Command
{
    protected $signature = 'billing:prune-documents {--dry-run : 只统计不删除}';

    protected $description = '按后台设置的保留天数清理已续费 / 已失效的旧账单记录（默认永久保留，不清理）';

    public function handle(): int
    {
        $days = BillingDocumentService::invoiceRetentionDays();
        if ($days <= 0) {
            $this->info('账单记录永久保留，无需清理');
            return self::SUCCESS;
        }
        $dryRun = (bool) $this->option('dry-run');
        $deleted = 0;
        $kept = 0;

        BillingDocument::with('user:id,expired_at')
            ->where('kind', BillingDocument::KIND_INVOICE)
            ->where('expired_at', '<', time() - $days * 86400)
            ->lazyById(500)
            ->each(function (BillingDocument $doc) use (&$deleted, &$kept, $dryRun) {
                if ($doc->statusFor($doc->user) === 'open') {
                    $kept++;   // 保留天数设得比 30 天短时才会遇到：到期不久，用户还可能照这张账单付款
                    return;
                }
                if (!$dryRun) {
                    $doc->delete();
                }
                $deleted++;
            });

        $this->info(sprintf(
            '%s旧账单 %d（到期后保留 %d 天，待付款跳过 %d）',
            $dryRun ? '[dry-run] ' : '',
            $deleted,
            $days,
            $kept
        ));
        return self::SUCCESS;
    }
}
