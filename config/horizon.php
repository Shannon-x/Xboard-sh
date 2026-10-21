<?php

use Illuminate\Support\Str;
use Linfo\Linfo;

// $lInfo = new Linfo();
// $parser = $lInfo->getParser();

// 生产主机 56 核 / 256 GB，目标是队列快、准、不积压，不是省进程。
//
// 2026-10-21 生产只读采集（旧配置 auto/time、balanceCooldown 1）看到：data-pipeline 的 worker 大多活不过
// 70 秒，自动伸缩在不停地杀 / 起进程，每次重启都要重新 boot 框架、重连 MySQL / Redis；而 traffic_fetch
// 每 5 分钟约 2,100 个任务、单个 30–40 ms，stat 约 4,200 个、单个 25 ms。这个量级用固定进程池即可让
// 每个队列始终有空闲 worker 阻塞等在队列上（block_for），不需要伸缩，也就没有伸缩延迟和进程抖动。
//
// balance=simple：进程数在 supervisor 的队列间平分、不伸缩，每个队列一个独立进程池。
// 每个 worker 占一条 MySQL 连接：4 组共 32 条，远在 max_connections=400 之内。
$supervisors = [
    'traffic' => [
        'connection' => 'redis',
        'queue' => ['traffic_fetch'],
        'balance' => 'simple',
        'maxProcesses' => 8,
        'memory' => 256,
        'tries' => 3,
        'timeout' => 30,
    ],
    'stat' => [
        'connection' => 'redis',
        'queue' => ['stat'],
        'balance' => 'simple',
        'maxProcesses' => 8,
        'memory' => 256,
        'tries' => 3,
        'timeout' => 30,
    ],
    'business' => [
        'connection' => 'redis',
        // order_handle 与 default 各 4 个固定 worker。default 上跑着 Chatwoot 推送（单个 300–700 ms）
        // 这类杂活，分池后它们再慢也占不到订单开通 / 取消的进程。
        'queue' => ['order_handle', 'default'],
        'balance' => 'simple',
        'maxProcesses' => 8,
        'memory' => 256,
        'tries' => 3,
        'timeout' => 60,
    ],
    'notification' => [
        'connection' => 'redis',
        // 4 个队列各 2 个固定 worker；任务自己声明的 timeout（如 120 s 的附件任务）优先于这里的 60。
        'queue' => ['node_sync', 'send_email', 'send_telegram', 'send_email_mass'],
        'balance' => 'simple',
        'maxProcesses' => 8,
        'memory' => 256,
        'tries' => 3,
        'timeout' => 60,
        'backoff' => [3, 10, 30],
    ],
];

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => null,

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => 'monitor',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_') . '_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['admin'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        // 排队等待超过阈值（秒）时 Horizon 触发 LongWaitDetected、仪表盘把该队列标红。
        // 订单开通与节点同步是秒级链路，邮件 / 统计类可以慢一些。
        'redis:default' => 60,
        'redis:order_handle' => 30,
        'redis:node_sync' => 30,
        'redis:traffic_fetch' => 60,
        'redis:send_email' => 120,
        'redis:send_telegram' => 120,
        'redis:stat' => 300,
        'redis:send_email_mass' => 600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        // horizon:snapshot 每 5 分钟一条，288 条 = 24 小时；原值 24 只留 2 小时，排障时拿不到昨天的曲线。
        'trim_snapshots' => [
            'job' => 288,
            'queue' => 288,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon worker
    | may consume before it is terminated and restarted. You should set
    | this value according to the resources available to your server.
    |
    */

    'memory_limit' => 512,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    // Horizon 会把 config('horizon.defaults') 里的每个 supervisor 并进每个环境
    //（ProvisioningPlan::applyDefaultOptions 用 array_replace_recursive）。这里不写 defaults 时，
    // 包自带的 config 会补一个 supervisor-1（redis / default 队列 / 1 个进程 / tries 1），
    // 2026-10-21 生产实测它一直在 32 个 worker 之外多跑一个。显式置空，进程池只来自下面的 $supervisors。
    'defaults' => [],

    'environments' => [
        // 生产机 .env 是 APP_ENV=local（2026-10-21 只读采集确认），Horizon 按 APP_ENV 取块，
        // 原来的 production 块从来没有生效过，线上一直跑的是主机上手改的 local 块。
        // 两个键指向同一份定义，不再有「改了没生效」的可能。
        // 生产机把 config/horizon.php 挂载进容器：改这里要在部署时同步主机上的那份并重启 horizon。
        'production' => $supervisors,
        'local' => $supervisors,
    ],
];
