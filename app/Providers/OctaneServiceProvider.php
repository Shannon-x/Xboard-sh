<?php

namespace App\Providers;

use App\Services\Plugin\HookManager;
use App\Services\UpdateService;
use App\Support\SchedulerWatchdog;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Facades\Octane;

class OctaneServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }
        if ($this->app->bound('octane')) {
            $this->app['events']->listen(WorkerStarting::class, function () {
                app(UpdateService::class)->updateVersionCache();
                HookManager::reset();
            });
        }
        // 调度看门狗：定时任务由单独的 scheduler 进程（schedule:work）跑，这里每 30 秒看一眼心跳，
        // 过期就派子进程代跑并告警（见 SchedulerWatchdog、config/scheduler.php）
        Octane::tick('scheduler', fn () => SchedulerWatchdog::tick())->seconds(30);
    }
}
