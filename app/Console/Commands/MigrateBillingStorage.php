<?php

namespace App\Console\Commands;

use App\Models\BillingDocument;
use App\Services\Billing\BillingArchive;
use Illuminate\Console\Command;

/**
 * 把归档文件搬到后台当前配置的存储（本地 ↔ S3）。
 *
 * 切换存储位置后新文件自动落到新位置，旧文件仍按 disk 列从原位置读，不搬也能用；
 * 这条命令用来一次性集中：逐份读出 → 写到新位置 → 删旧文件 → 更新 path / disk。
 * 已清理的收据（size = 0）没有文件可搬，下载时会直接在新位置重建。
 */
class MigrateBillingStorage extends Command
{
    protected $signature = 'billing:migrate-storage {--dry-run : 只统计不搬}';

    protected $description = '把归档的收据 / 账单 PDF 搬到当前配置的存储位置（本地 ↔ S3 兼容对象存储）';

    public function handle(BillingArchive $archive): int
    {
        $target = $archive->store();
        $dryRun = (bool) $this->option('dry-run');
        $stats = ['moved' => 0, 'missing' => 0, 'failed' => 0];

        BillingDocument::where('disk', '!=', $target->driver())
            ->where('size', '>', 0)
            ->lazyById(100)
            ->each(function (BillingDocument $doc) use (&$stats, $archive, $target, $dryRun) {
                if ($dryRun) {
                    $stats['moved']++;
                    return;
                }
                $pdf = $archive->read($doc);
                if ($pdf === null) {
                    $stats['missing']++;   // 原位置没有：留给下载时重建，记录不动
                    return;
                }
                $key = $target->keyFor((int) $doc->user_id, (string) $doc->doc_no);
                try {
                    $target->put($key, $pdf);
                } catch (\Throwable $e) {
                    $this->warn("{$doc->doc_no}: {$e->getMessage()}");
                    $stats['failed']++;
                    return;
                }
                $old = clone $doc;
                $doc->path = $key;
                $doc->disk = $target->driver();
                $doc->save();
                $archive->deleteFile($old);   // 删不掉只记日志，不影响新位置已经可用
                $stats['moved']++;
            });

        $this->info(sprintf(
            '%s目标 %s：搬迁 %d，原文件缺失 %d，失败 %d',
            $dryRun ? '[dry-run] ' : '',
            $target->driver(),
            $stats['moved'],
            $stats['missing'],
            $stats['failed']
        ));

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
