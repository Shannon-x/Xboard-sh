<?php

namespace App\Console\Commands;

use App\Services\ThemeService;
use App\Services\UpdateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use App\Services\Plugin\PluginManager;
use App\Models\Plugin;
use Illuminate\Support\Str;
use App\Console\Commands\XboardInstall;

class XboardUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'xboard:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'xboard 更新';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->info('正在导入数据库请稍等...');
        $migrationStatus = Artisan::call("migrate", ['--force' => true]);
        $this->info(Artisan::output());
        if ($migrationStatus !== 0) {
            $this->error('数据库迁移失败，停止更新；不要切换到新镜像。修复后可重试迁移。');
            return self::FAILURE;
        }
        $checkStatus = Artisan::call('xboard:check-upgrade');
        $this->info(Artisan::output());
        if ($checkStatus !== 0) {
            $this->error('升级结构检查失败，停止更新。');
            return self::FAILURE;
        }
        $this->info('正在检查内置插件文件...');
        XboardInstall::restoreProtectedPlugins($this);
        $this->info('正在检查并安装默认插件...');
        PluginManager::installDefaultPlugins();
        $this->info('默认插件检查完成');
        // Artisan::call('reset:traffic', ['--fix-null' => true]);
        $this->info('正在重新计算所有用户的重置时间...');
        Artisan::call('reset:traffic', ['--force' => true]);
        $updateService = new UpdateService();
        $updateService->updateVersionCache();
        $themeService = app(ThemeService::class);
        $themeService->refreshCurrentTheme();
        Artisan::call('horizon:terminate');
        $this->info('更新完毕，队列服务已重启，你无需进行任何操作。');
    }
}
