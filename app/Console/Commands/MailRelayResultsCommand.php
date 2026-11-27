<?php

namespace App\Console\Commands;

use App\Services\Mail\MailRelay;
use App\Services\Mail\MailRelayResults;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * 读 Rust 投递服务写回的结果流（mail:relay:results），逐条交给 MailRelayResults 记账。
 * 调度每分钟起一次、跑满约 55 秒（withoutOverlapping），所以结果最多晚几秒落到 v2_mail_log。
 * 没开投递服务（MAIL_RELAY_LANES 为空）且结果流不存在时直接退出，什么也不做。
 */
class MailRelayResultsCommand extends Command
{
    protected $signature = 'mail:relay-results {--seconds=55 : 最多跑多久}';

    protected $description = '把邮件投递服务的结果记进 v2_mail_log，并回写收据 / 账单的发送状态';

    public const GROUP = 'php';

    /** spool 里的大邮件留多久（投递服务最多重试几分钟；这只兜底清掉结果丢了的孤儿文件） */
    public const SPOOL_KEEP_SECONDS = 8 * 86400;

    public function handle(MailRelayResults $results): int
    {
        $key = MailRelay::key('results');
        if (MailRelay::lanes() === [] && !Redis::exists($key)) {
            return 0;
        }
        try {
            Redis::xgroup('CREATE', $key, self::GROUP, '0', true);
        } catch (\Throwable $e) {
            if (!str_contains($e->getMessage(), 'BUSYGROUP')) {
                throw $e;
            }
        }
        $this->pruneSpool();
        $consumer = 'php-' . (gethostname() ?: 'panel');
        $deadline = microtime(true) + max(1, (int) $this->option('seconds'));
        $cursor = '0';   // 先把上次没确认完的读掉，再读新的
        $done = 0;
        while (microtime(true) < $deadline) {
            $reply = Redis::xreadgroup(self::GROUP, $consumer, [$key => $cursor], 100, 2000);
            $entries = is_array($reply) ? (array) (reset($reply) ?: []) : [];
            if ($entries === []) {
                if ($cursor !== '>') {
                    $cursor = '>';
                }
                continue;
            }
            foreach ($entries as $entryId => $fields) {
                if ($cursor !== '>') {
                    $cursor = (string) $entryId;
                }
                if (!is_array($fields) || $fields === []) {
                    Redis::xack($key, self::GROUP, [$entryId]);   // 已被 MAXLEN 裁掉的旧条目
                    continue;
                }
                try {
                    $results->handle($fields);
                    $this->dropSpool((string) ($fields['id'] ?? ''));
                    Redis::xack($key, self::GROUP, [$entryId]);
                    $done++;
                } catch (\Throwable $e) {
                    // 不确认：下一轮从待确认里再读（多半是数据库一时连不上）
                    Log::error('[mail] 处理投递结果失败，稍后重试', ['entry' => $entryId, 'id' => $fields['id'] ?? null, 'error' => $e->getMessage()]);
                }
            }
        }
        if ($done > 0) {
            $this->info("处理了 {$done} 条投递结果");
        }
        return 0;
    }

    private function dropSpool(string $id): void
    {
        if ($id === '' || !preg_match('/^[A-Za-z0-9-]+$/', $id)) {
            return;
        }
        $file = MailRelay::spoolDir() . '/' . $id . '.eml';
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private function pruneSpool(): void
    {
        $dir = MailRelay::spoolDir();
        if (!is_dir($dir)) {
            return;
        }
        $cutoff = time() - self::SPOOL_KEEP_SECONDS;
        foreach (glob($dir . '/*.eml*') ?: [] as $file) {
            if (@filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }
}
