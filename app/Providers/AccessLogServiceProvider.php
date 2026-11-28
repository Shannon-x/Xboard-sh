<?php

namespace App\Providers;

use App\Services\AccessLog\AccessLogConfig;
use App\Services\AccessLog\AccessLogStorage;
use App\Services\AccessLog\RedisSubtotalBuffer;
use App\Services\AccessLog\SubtotalBuffer;
use Illuminate\Support\ServiceProvider;

class AccessLogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 不做单例：Octane 常驻进程里后台改了存储配置，下一次解析就用新的
        $this->app->bind(AccessLogStorage::class, static fn() => AccessLogStorage::fromConfig(AccessLogConfig::fromSettings()));
        $this->app->bind(SubtotalBuffer::class, RedisSubtotalBuffer::class);
    }
}
