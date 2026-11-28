<?php

namespace App\Console\Commands;

use App\Services\AccessLog\AccessLogIngest;
use App\Services\AccessLog\SubtotalBuffer;
use Illuminate\Console\Command;

class AccessLogFlush extends Command
{
    protected $signature = 'access-log:flush';
    protected $description = '把访问记录批次的用户小计从 Redis 并入日汇总表';

    public function handle(SubtotalBuffer $buffer): int
    {
        $rows = $buffer->drain(static fn(array $rows) => AccessLogIngest::writeSummary($rows));
        if ($rows > 0) {
            $this->info("merged {$rows} rows");
        }
        return self::SUCCESS;
    }
}
