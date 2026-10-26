<?php

namespace App\Console\Commands;

use App\Services\RestockNotifier;
use Illuminate\Console\Command;

/** 到货提醒：有人在等的售罄套餐放出了席位（或同一产品开了新一车）就给登记的人各发一封。 */
class PlanRestockNotify extends Command
{
    protected $signature = 'plan:restock-notify {--dry-run : 只统计不发信}';
    protected $description = '售罄套餐有位置后，给登记了到货提醒的用户发信（每次登记一封）';

    public function handle(RestockNotifier $notifier): int
    {
        $result = $notifier->run((bool) $this->option('dry-run'));
        if ($result['sent'] > 0 || $this->option('dry-run')) {
            $this->info(($this->option('dry-run') ? '演练 ' : '') . "到货套餐 {$result['plans']} 款，发信 {$result['sent']} 封");
        }
        return self::SUCCESS;
    }
}
