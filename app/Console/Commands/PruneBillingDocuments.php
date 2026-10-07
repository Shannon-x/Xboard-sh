<?php

namespace App\Console\Commands;

use App\Models\BillingDocument;
use App\Services\Billing\BillingArchive;
use App\Services\Billing\BillingStorageConfig;
use Illuminate\Console\Command;

/**
 * 归档的收据 / 账单不能无限膨胀：每天按后台配置的保留期清理。
 *
 * - 收据：超过 billing_receipt_retention_days 天的只删 PDF 文件，记录保留并把 size 置 0；
 *   用户再下载时按订单重新渲染（订单字段不会变），所以面板里的收据列表永远完整。
 * - 账单：对应周期结束超过 billing_invoice_retention_days 天、且已结清 / 已失效的，连记录一起删
 *   （续费的那一单自有收据，账单本身只是「待付款」的提醒）；还可能付款的（open）不动。
 * - 0 = 永久保留。文件删不掉的保留记录，下一轮再试。
 */
class PruneBillingDocuments extends Command
{
    protected $signature = 'billing:prune-documents {--dry-run : 只统计不删除}';

    protected $description = '按保留期清理归档的收据 PDF（记录保留、可重建）与已结清 / 失效的旧账单';

    public function handle(BillingArchive $archive): int
    {
        $config = BillingStorageConfig::fromSettings();
        $dryRun = (bool) $this->option('dry-run');
        $now = time();
        $stats = ['receipts' => 0, 'invoices' => 0, 'kept' => 0, 'failed' => 0];

        if ($config->receiptRetentionDays > 0) {
            BillingDocument::where('kind', BillingDocument::KIND_RECEIPT)
                ->where('size', '>', 0)   // size = 0 的已经清过，不必每天再碰一遍
                ->where('created_at', '<', $now - $config->receiptRetentionDays * 86400)
                ->lazyById(200)
                ->each(function (BillingDocument $doc) use (&$stats, $archive, $dryRun) {
                    if ($dryRun) {
                        $stats['receipts']++;
                        return;
                    }
                    if ($archive->deleteFile($doc)) {
                        $doc->size = 0;
                        $doc->save();
                        $stats['receipts']++;
                    } else {
                        $stats['failed']++;
                    }
                });
        }

        if ($config->invoiceRetentionDays > 0) {
            BillingDocument::with('user:id,expired_at')
                ->where('kind', BillingDocument::KIND_INVOICE)
                ->where('expired_at', '<', $now - $config->invoiceRetentionDays * 86400)
                ->lazyById(200)
                ->each(function (BillingDocument $doc) use (&$stats, $archive, $dryRun) {
                    if ($doc->statusFor($doc->user) === 'open') {
                        $stats['kept']++;   // 保留期设得比 30 天短时才会遇到：到期不久，用户还可能照这张账单付款
                        return;
                    }
                    if ($dryRun) {
                        $stats['invoices']++;
                        return;
                    }
                    if ($archive->deleteFile($doc)) {
                        $doc->delete();
                        $stats['invoices']++;
                    } else {
                        $stats['failed']++;
                    }
                });
        }

        $this->info(sprintf(
            '%s收据文件 %d（保留 %s），旧账单 %d（周期结束后保留 %s，待付款跳过 %d），失败 %d',
            $dryRun ? '[dry-run] ' : '',
            $stats['receipts'],
            $config->receiptRetentionDays > 0 ? "{$config->receiptRetentionDays} 天" : '永久',
            $stats['invoices'],
            $config->invoiceRetentionDays > 0 ? "{$config->invoiceRetentionDays} 天" : '永久',
            $stats['kept'],
            $stats['failed']
        ));

        return self::SUCCESS;
    }
}
