<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CheckUpgrade extends Command
{
    protected $signature = 'xboard:check-upgrade {--require-legacy-rollback : Fail if older images cannot safely interpret the data or knowledge permissions}';
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
        foreach (['visibility', 'slug', 'summary', 'published_at'] as $column) {
            if (!Schema::hasColumn('v2_knowledge', $column)) {
                $this->error("缺少 v2_knowledge.{$column}，禁止向新知识库切换流量。");
                $failed = true;
            }
        }
        if (Schema::hasColumn('v2_knowledge', 'visibility') && DB::table('v2_knowledge')
            ->where(fn ($query) => $query->where('visibility', '!=', 'members')->orWhereNull('visibility'))->exists()) {
            $this->warn('知识库已有新的可见范围；旧后端只检查 show，会暴露内部或订阅专属文章。禁止混用旧实例或直接降级。');
            $failed = $failed || (bool) $this->option('require-legacy-rollback');
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
