<?php

use Laravel\Octane\Contracts\OperationTerminated;
use Laravel\Octane\Events\RequestHandled;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Events\TickReceived;
use Laravel\Octane\Events\TickTerminated;
use Laravel\Octane\Events\WorkerErrorOccurred;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Events\WorkerStopping;
use Laravel\Octane\Listeners\CollectGarbage;
use Laravel\Octane\Listeners\EnsureUploadedFilesAreValid;
use Laravel\Octane\Listeners\EnsureUploadedFilesCanBeMoved;
use Laravel\Octane\Listeners\FlushTemporaryContainerInstances;
use Laravel\Octane\Listeners\FlushUploadedFiles;
use Laravel\Octane\Listeners\ReportException;
use Laravel\Octane\Listeners\StopWorkerIfNecessary;
use Laravel\Octane\Octane;

return [

    /*
    |--------------------------------------------------------------------------
    | Octane Server
    |--------------------------------------------------------------------------
    |
    | This value determines the default "server" that will be used by Octane
    | when starting, restarting, or stopping your server via the CLI. You
    | are free to change this to the supported server of your choosing.
    |
    | Supported: "roadrunner", "swoole"
    |
    */

    'server' => env('OCTANE_SERVER', 'swoole'),

    /*
    |--------------------------------------------------------------------------
    | Force HTTPS
    |--------------------------------------------------------------------------
    |
    | When this configuration value is set to "true", Octane will inform the
    | framework that all absolute links must be generated using the HTTPS
    | protocol. Otherwise your links may be generated using plain HTTP.
    |
    */

    'https' => env('OCTANE_HTTPS', false),

    /*
    |--------------------------------------------------------------------------
    | Octane Listeners
    |--------------------------------------------------------------------------
    |
    | All of the event listeners for Octane's events are defined below. These
    | listeners are responsible for resetting your application's state for
    | the next request. You may even add your own listeners to the list.
    |
    */

    'listeners' => [
        WorkerStarting::class => [
            EnsureUploadedFilesAreValid::class,
            EnsureUploadedFilesCanBeMoved::class,
        ],

        RequestReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
            ...Octane::prepareApplicationForNextRequest(),
            //
        ],

        RequestHandled::class => [
            //
        ],

        RequestTerminated::class => [
            FlushUploadedFiles::class,
        ],

        TaskReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
            //
        ],

        TaskTerminated::class => [
            //
        ],

        TickReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
            //
        ],

        TickTerminated::class => [
            //
        ],

        OperationTerminated::class => [
            FlushTemporaryContainerInstances::class,
            // 原来是 DisconnectFromDatabases：每个请求结束都断开 MySQL，生产每秒约 50 次新建连接。
            // 现在保留连接复用，只在连接上还挂着未提交事务时回滚并断开（见类注释）。
            \App\Listeners\Octane\RollBackLeakedTransactions::class,
            CollectGarbage::class,
        ],

        WorkerErrorOccurred::class => [
            ReportException::class,
            StopWorkerIfNecessary::class,
        ],

        WorkerStopping::class => [
            //
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Warm / Flush Bindings
    |--------------------------------------------------------------------------
    |
    | The bindings listed below will either be pre-warmed when a worker boots
    | or they will be flushed before every new request. Flushing a binding
    | will force the container to resolve that binding again when asked.
    |
    */

    'warm' => [
        ...Octane::defaultServicesToWarm(),
    ],

    'flush' => [
        // HookManager 把 action/filter 回调存进容器实例 hook.actions / hook.filters
        // （见 HookManager::setActions/getActions 里的 App::instance('hook.actions', ...)）。
        // 之前这里 flush 的是 HookManager::class——一个全静态、从不被解析为实例的绑定，
        // 等于空操作：scoped 的 PluginManager 每请求重新注册钩子，但这两个数组从不清理，
        // 闭包按 spl_object_hash 无限累积（重复触发 hook + worker 内存泄漏直至 OOM）。
        // 改为 flush 真正的存储键：每请求开始清空，随后 InitializePlugins 中间件重新注册，
        // 保证每个 hook 每请求恰好一份。
        'hook.actions',
        'hook.filters',
    ],

    /*
    |--------------------------------------------------------------------------
    | Octane Cache Table
    |--------------------------------------------------------------------------
    |
    | While using Swoole, you may leverage the Octane cache, which is powered
    | by a Swoole table. You may set the maximum number of rows as well as
    | the number of bytes per row using the configuration options below.
    |
    */

    'cache' => [
        'rows' => 5000,
        'bytes' => 20000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Octane Swoole Tables
    |--------------------------------------------------------------------------
    |
    | While using Swoole, you may define additional tables as required by the
    | application. These tables can be used to store data that needs to be
    | quickly accessed by other workers on the particular Swoole server.
    |
    */

    'tables' => [
        'example:1000' => [
            'name' => 'string:1000',
            'votes' => 'int',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watching
    |--------------------------------------------------------------------------
    |
    | The following list of files and directories will be watched when using
    | the --watch option offered by Octane. If any of the directories and
    | files are changed, Octane will automatically reload your workers.
    |
    */

    'watch' => [
        'app',
        'bootstrap',
        'config',
        'database',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        '.env',
    ],

    /*
    |--------------------------------------------------------------------------
    | Garbage Collection Threshold
    |--------------------------------------------------------------------------
    |
    | When executing long-lived PHP scripts such as Octane, memory can build
    | up before being cleared by PHP. You can force Octane to run garbage
    | collection if your application consumes this amount of megabytes.
    |
    */

    'garbage' => 128,

    /*
    |--------------------------------------------------------------------------
    | Maximum Execution Time
    |--------------------------------------------------------------------------
    |
    | The following setting configures the maximum execution time for requests
    | being handled by Octane. You may set this value to 0 to indicate that
    | there isn't a specific time limit on Octane request execution time.
    |
    */

    'max_execution_time' => 60,

    /*
    |--------------------------------------------------------------------------
    | Swoole Server Options
    |--------------------------------------------------------------------------
    |
    | package_max_length 是 Swoole 单个 HTTP 请求体的硬上限（Octane 默认 10MB），
    | 超过的连接会被直接切断、PHP 层根本收不到请求。工单附件单文件硬上限 20MB
    | （AttachmentConfig::HARD_MAX_SIZE_MB），base64 JSON 形态约 27MB，因此放到 32MB，
    | 与 .docker/usr/local/etc/php/conf.d/zz-xboard-uploads.ini 的 post_max_size 对齐。
    |
    */

    'swoole' => [
        'options' => [
            'package_max_length' => 32 * 1024 * 1024,
            // Octane 命令行默认 --max-requests=500：生产约 40 req/s、48 个 worker，每 11.7 秒就有一个 worker
            // 回收重启（2026-10-21 采集：WorkerStarting 的日志一天 7,408 行），每次重新 boot 整个框架。
            // 主机 256 GB、单 worker 几十 MB，10000 次再回收足够防泄漏。这里的值优先于命令行参数，
            // 随镜像生效，不用改主机 compose。
            'max_request' => 10000,
            'task_max_request' => 10000,
            // Swoole 默认 dispatch_mode=2：按连接的 fd 固定分给某个 worker。生产所有流量经中间件
            // xb-secure 进来，它只保持 5 条 keep-alive 长连接（2026-10-21 采集），于是同一时刻真正干活的
            // worker 只有约 5 个：部署后 4 小时里 52 次 max_request 回收全落在 48 个 worker 中的 11 个上，
            // CPU 时间前 10 个 worker 占 64%，另外 37 个从未跑满过一次；慢请求会堵住同一连接后面的请求。
            // 3 = 抢占模式：主进程只把请求投给空闲的 worker，是 Swoole 文档对同步阻塞（Octane 关闭协程）
            // 服务器的推荐值。HTTP 请求本身无状态，Octane 也不使用 onConnect / onClose，换模式没有语义变化。
            'dispatch_mode' => 3,
            // Octane 默认带 send_yield=true，但 Swoole 只在 dispatch_mode 2/4 下接受它，模式 3 启动时会打一条
            // 「'send_yield' option can only be set when using dispatch_mode=2/4」告警并忽略。Octane 关闭了协程，
            // send_yield 本来就不起作用，这里显式关掉以消除告警。
            'send_yield' => false,
        ],
    ],

];
