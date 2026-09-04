<?php

namespace App\Console\Commands;

use App\Services\Commission\UsdtRateService;
use App\Services\Commission\WithdrawalConfig;
use Illuminate\Console\Command;

/**
 * 定时把 USDT 汇率缓存焐热，让用户端的提现页面永远命中缓存、不用等行情接口。
 * 手动跑 `php artisan commission:refresh-usdt-rate --force` 可以立刻重取一次。
 */
class RefreshUsdtRate extends Command
{
    protected $signature = 'commission:refresh-usdt-rate {--force : 忽略缓存与失败冷却，强制重新获取}';

    protected $description = '刷新佣金提现用的 USDT 实时汇率缓存';

    public function handle(): int
    {
        $config = WithdrawalConfig::fromSettings();
        if ($config->rateSource !== WithdrawalConfig::RATE_SOURCE_AUTO && !$this->option('force')) {
            $this->line('汇率来源为手动，跳过。');
            return self::SUCCESS;
        }

        $snapshot = (new UsdtRateService())->snapshot($config->currency, (bool) $this->option('force'));
        if ($snapshot['rate'] === null) {
            $this->error("未能获取 {$config->currency} 汇率，将回退到后台兜底值。");
            return self::FAILURE;
        }

        $this->info(sprintf(
            '1 USDT = %s %s（来源 %s%s）',
            $snapshot['rate'],
            $config->currency,
            $snapshot['source_label'],
            $snapshot['is_stale'] ? '，为过期缓存' : ''
        ));
        return self::SUCCESS;
    }
}
