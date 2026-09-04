<?php

namespace App\Console\Commands;

use App\Models\TicketAttachment;
use App\Services\TicketAttachment\AttachmentConfig;
use App\Services\TicketAttachmentService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class CleanTicketAttachments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ticket:clean-attachments {--dry-run : 只统计不删除}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '清理工单附件：超过保留期的、上传后未随消息发出的、所属工单已被删除的';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $config = AttachmentConfig::fromSettings();
        $service = new TicketAttachmentService($config);
        $dryRun = (bool) $this->option('dry-run');
        $now = time();
        $stats = ['pending' => 0, 'dangling' => 0, 'expired' => 0, 'failed' => 0];

        $purge = function (Builder $query, string $bucket) use (&$stats, $service, $dryRun) {
            $query->lazyById(200)->each(function (TicketAttachment $attachment) use (&$stats, $service, $dryRun, $bucket) {
                if ($dryRun) {
                    $stats[$bucket]++;
                    return;
                }
                // 文件删不掉就保留记录，下一轮再试；否则库里干净了、对象却永远留在存储上
                if ($service->deleteFile($attachment)) {
                    $attachment->delete();
                    $stats[$bucket]++;
                } else {
                    $stats['failed']++;
                }
            });
        };

        // 上传后一直没随消息发出的
        $purge(
            TicketAttachment::whereNull('ticket_message_id')
                ->where('created_at', '<', $now - AttachmentConfig::PENDING_TTL),
            'pending'
        );

        // 所属工单已被删除（后台删用户会连带删工单）
        $purge(
            TicketAttachment::whereNotNull('ticket_message_id')->whereDoesntHave('ticket'),
            'dangling'
        );

        // 超过保留期（0 = 永久保留）
        if ($config->retentionDays > 0) {
            $purge(
                TicketAttachment::where('created_at', '<', $now - $config->retentionDays * 86400),
                'expired'
            );
        }

        $this->info(sprintf(
            '%s未绑定 %d，悬挂 %d，过期 %d（保留 %s），失败 %d',
            $dryRun ? '[dry-run] ' : '',
            $stats['pending'],
            $stats['dangling'],
            $stats['expired'],
            $config->retentionDays > 0 ? "{$config->retentionDays} 天" : '永久',
            $stats['failed']
        ));

        return self::SUCCESS;
    }
}
