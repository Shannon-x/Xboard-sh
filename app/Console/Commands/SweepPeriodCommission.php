<?php

namespace App\Console\Commands;

use App\Services\Commission\PeriodCommission;
use Illuminate\Console\Command;

/**
 * 每日巡检周期返佣资格：已开通的用户有效付费被邀请人低于门槛的，取消资格并通知。
 * 发佣和打开邀请页时也会当场检查，这里兜住「一直没人下单、也没打开邀请页」的人。
 */
class SweepPeriodCommission extends Command
{
    protected $signature = 'commission:period-sweep';

    protected $description = '取消有效付费被邀请人已低于门槛的周期返佣资格';

    public function handle(): int
    {
        $revoked = (new PeriodCommission())->sweep();
        $this->info("取消资格 {$revoked} 人");
        return self::SUCCESS;
    }
}
