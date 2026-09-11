<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CheckUpgrade extends Command
{
    protected $signature = 'xboard:check-upgrade {--require-legacy-rollback : Fail if pre-customization images cannot safely interpret the data}';
    protected $description = '只读检查升级所需字段及旧镜像回退条件，不执行迁移或修改配置';

    public function handle(): int
    {
        $failed = false;
        foreach (['v2_plan' => 'customization', 'v2_order' => 'plan_snapshot', 'v2_user' => 'plan_options'] as $table => $column) {
            if (!Schema::hasColumn($table, $column)) {
                $this->error("缺少 {$table}.{$column}，备份后使用目标镜像执行 php artisan migrate --force，再切换流量。");
                $failed = true;
                continue;
            }
            if (DB::table($table)->whereNotNull($column)->exists()) {
                $this->warn("{$table}.{$column} 已有自选套餐数据；旧镜像可能错误定价或丢失权益，不能仅换镜像回退。");
                $failed = $failed || (bool) $this->option('require-legacy-rollback');
            }
        }
        $migrator = app('migrator');
        if (!$migrator->repositoryExists()) {
            $this->error('数据库尚未初始化。');
            return self::FAILURE;
        }
        $pending = array_diff(array_keys($migrator->getMigrationFiles(database_path('migrations'))), $migrator->getRepository()->getRan());
        if ($pending) {
            $this->error('存在待执行迁移：' . implode(', ', $pending));
            $failed = true;
        }
        if (!$failed) {
            $this->info('结构检查通过。仍需验证旧前端、中间件、队列与实际支付链路；此检查不代表生产验收。');
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
