<?php

namespace App\Console\Commands;

use App\Services\Database\AutoIncrementMonitor;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CheckAutoIncrement extends Command
{
    protected $signature = 'check:auto-increment
        {--threshold=0.5 : 用量达到上限的这个比例就告警}
        {--all : 列出全部自增列，而不只是超过阈值的}';

    protected $description = '检查自增主键/序列是否接近类型上限（耗尽后 upsert 会静默写坏数据）';

    public function handle(AutoIncrementMonitor $monitor): int
    {
        $threshold = (float) $this->option('threshold');
        $rows = $monitor->scan();
        $hot = array_values(array_filter($rows, fn ($r) => $r['ratio'] >= $threshold));

        $shown = $this->option('all') ? $rows : $hot;
        if ($shown) {
            $this->table(
                ['table', 'column', 'type', 'next', 'max', 'used'],
                array_map(fn ($r) => [
                    $r['table'], $r['column'], $r['type'],
                    number_format($r['next'], 0, '.', ''), number_format($r['max'], 0, '.', ''),
                    sprintf('%.2f%%', $r['ratio'] * 100),
                ], $shown)
            );
        }

        if (!$hot) {
            $this->info('No auto-increment column above ' . ($threshold * 100) . '%.');
            return self::SUCCESS;
        }

        $lines = array_map(
            fn ($r) => sprintf('%s.%s (%s) %.2f%%', $r['table'], $r['column'], $r['type'], $r['ratio'] * 100),
            $hot
        );
        Log::critical('Auto-increment columns close to their type limit', ['columns' => $lines]);

        // 同一组告警每天最多推一次 Telegram，避免每小时刷屏
        $key = 'auto_increment_alert:' . md5(implode('|', array_map(fn ($r) => $r['table'], $hot))) . ':' . date('Ymd');
        if (Cache::add($key, 1, 86400)) {
            try {
                app(TelegramService::class)->sendMessageWithAdmin(
                    "⚠️ 自增主键即将耗尽（耗尽后新数据会被静默写坏）\n" . implode("\n", $lines)
                    . "\n处理：把主键改为 BIGINT，并检查是否有 upsert 在空烧 id。",
                    false,
                    'html'
                );
            } catch (\Throwable $e) {
                Log::warning('check:auto-increment telegram notify failed: ' . $e->getMessage());
            }
        }

        $this->warn(count($hot) . ' column(s) above threshold.');
        return self::FAILURE;
    }
}
