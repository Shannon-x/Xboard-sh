<?php

use Illuminate\Support\Str;
use Linfo\Linfo;

// $lInfo = new Linfo();
// $parser = $lInfo->getParser();

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
        'redis:user_alive_sync' => 60,
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
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
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

    'memory_limit' => 256,

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

    'environments' => [
        'production' => [
            // 生产主机 56 核 / 256 GB，目标是队列快、准、不积压，不是省进程。
            // balance=auto 下每个队列一个进程池，minProcesses 是「每个队列」的常驻下限，
            // maxProcesses 是整个 supervisor 的上限；三组常驻 2×(3+2+4)=18 个 worker，
            // 每个占一条 MySQL 连接，远在 max_connections 之内。
            // 注意：生产机把 config/horizon.php 挂载进容器，改这里要在部署时同步主机上的那份。
            'data-pipeline' => [
                'connection' => 'redis',
                'queue' => ['traffic_fetch', 'stat', 'user_alive_sync'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'minProcesses' => 2,
                'maxProcesses' => 16,
                // 每轮最多加减 4 个进程、1 秒一轮：节点集中上报时几秒内就扩到位
                'balanceMaxShift' => 4,
                'balanceCooldown' => 1,
                'tries' => 3,
                'timeout' => 30,
            ],
            'business' => [
                'connection' => 'redis',
                // order_handle 在前：开通 / 取消订单比 default 队列的杂活优先
                'queue' => ['order_handle', 'default'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'size',
                'minProcesses' => 2,
                'maxProcesses' => 6,
                'balanceCooldown' => 1,
                'tries' => 3,
                'timeout' => 30,
            ],
            'notification' => [
                'connection' => 'redis',
                'queue' => ['node_sync', 'send_email', 'send_telegram', 'send_email_mass'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'size',
                'minProcesses' => 2,
                // 4 个队列 × minProcesses 2 = 8 个常驻；群发 / 日报堆上来时最多扩到 12
                //（原来的上限 3 连 4 个队列各 1 个进程都装不下，AutoScaler 实际一直跑 4 个）
                'maxProcesses' => 12,
                'balanceMaxShift' => 2,
                'balanceCooldown' => 1,
                'tries' => 3,
                'timeout' => 60,
                'backoff' => [3, 10, 30],
            ],
        ],
        'local' => [
            'Xboard' => [
                'connection' => 'redis',
                'queue' => [
                    'default',
                    'order_handle',
                    'traffic_fetch',
                    'stat',
                    'send_email',
                    'send_email_mass',
                    'send_telegram',
                    'user_alive_sync',
                    'node_sync'
                ],
                'balance' => 'auto',
                'minProcesses' => 1,
                'maxProcesses' => 5,
                'tries' => 1,
                'timeout' => 60,
                'balanceCooldown' => 3,
            ],
        ],
    ],
];
