# 队列、Horizon 与定时任务

后端的异步工作全部走 Redis 队列，由 Horizon（`php artisan horizon`，生产里是独立的 `horizon` 容器）消费；
定时任务由 Octane 的 `tick`（每 30 秒、带 `scheduler-lock` 分布式锁）在 Swoole task worker 里调用
`schedule:run`，每条到期的 artisan 命令各起一个子进程。生产主机 56 核 / 256 GB，配置按「快、准、不积压」取值，
不按省进程取值。

## 队列与 supervisor（`config/horizon.php`）

> 生产机 `.env` 是 `APP_ENV=local`（2026-10-21 只读采集确认）。Horizon 按 `APP_ENV` 取 `environments` 里的块，
> 所以此前 `production` 块从来没有生效过，线上跑的是主机上手改的 `local` 块。现在 `production` 与 `local`
> 两个键指向同一个 `$supervisors` 数组，改一处即可。生产机把 `config/horizon.php` 挂载进容器，部署时要把
> 新文件同步到主机并重启 `horizon` 容器。

| supervisor | 队列 | 固定进程 | 用途 |
|---|---|---|---|
| traffic | traffic_fetch | 8 | 节点上报流量累加到 `v2_user` |
| stat | stat | 8 | 当天按用户 / 按节点的流量统计 |
| business | order_handle、default | 各 4 | 订单取消 / 补开通；Chatwoot 推送等杂活 |
| notification | node_sync、send_email、send_telegram、send_email_mass | 各 2 | 节点名单增量推送、邮件、Telegram、群发 |

全部 `balance=simple`：进程数在 supervisor 的队列间平分、**不伸缩**，每个队列一个独立进程池。生产采集看到旧配置
（`auto` + `balanceCooldown=1`）下 data-pipeline 的 worker 大多活不过 70 秒，自动伸缩在不停地杀 / 起进程；而
traffic_fetch 每 5 分钟约 2,100 个任务（单个 30–40 ms）、stat 约 4,200 个（单个 25 ms），固定池足以让每个队列
始终有空闲 worker 阻塞等在队列上。4 组共 32 个 worker，各占一条 MySQL 连接（`max_connections=400`）。
`memory=256`（worker 超过即重启，旧值 128 与 60–115 MB 的实际占用太近），`memory_limit=512`（master）。

`'defaults' => []` 必须保留：Horizon 会把 `config('horizon.defaults')` 里的每个 supervisor 并进每个环境，不写时包自带的 config 会补一个 `supervisor-1`（`default` 队列、1 个进程、`tries` 1），2026-10-21 生产实测它在 32 个 worker 之外一直多跑一个。`tests/Unit/QueueConfigTest::horizon_defaults_do_not_add_a_stray_supervisor` 把关。

`metrics.trim_snapshots` 288：`horizon:snapshot` 每 5 分钟一条，保留 24 小时（原值 24 只有 2 小时）。

`config/queue.php` 的 redis 连接：

- `block_for = 5`：worker 空闲时在队列上 BLPOP 阻塞等待，新任务落队即被捡起；原先每 `sleep(3)` 轮询一次，
  新任务平均要等 1.5 秒。阻塞只在「一个进程守一个队列」时安全（否则列表里靠后的队列会挨饿），
  `tests/Unit/QueueConfigTest` 保证所有守多队列的 supervisor 都是 auto / simple 分池。
- `retry_after = 150`：必须大于最长的任务超时（`SendTelegramAttachmentJob` 120 s），否则任务还在跑就会被
  当作卡死重新投递、附件发两遍。同一个测试把关。

`waits` 给每个队列一个等待阈值（秒），超过时 Horizon 触发 `LongWaitDetected`、仪表盘（`/monitor`）标红：
order_handle / node_sync 30、traffic_fetch 60、send_email 120、stat 300、send_email_mass 600。

## 流量上报管线

节点每 `server_push_interval`（默认 60 s）POST 一次 `/api/v1/server/UniProxy/push`，`UserService::trafficFetch`
把一次上报拆成：

- 每 1000 个用户一块：`TrafficFetchJob`（一条 `CASE WHEN` UPDATE 累加 `v2_user.u/d/t`，把用户放进
  `traffic:pending_check` 供 `check:traffic-exceeded` 通知节点踢掉超量用户）和 `StatUserJob`
  （当天按用户 × 倍率的统计，批量 UPDATE + upsert）；
- 整次上报一个 `StatServerJob`（当天按节点的统计，只需要总和，派发时已经算好）。

派给 worker 的节点信息只有 `id` 与 `rate`（在 `traffic.process.before` hook 之后取，分时倍率插件改写的
倍率生效）。

### 恰好一次（`App\Support\JobReceipt`）

三类任务都把数据写入和一行以任务 uuid 为主键的回执（`v2_job_receipt`）放进同一个事务：

- 写入抛异常（死锁、锁等待超时）→ 整个事务回滚，回执也没了 → 重试照常再做；
- 上次其实已提交、worker 只是在确认前被杀或超时（Horizon 按 `retry_after` 重新投递同一份 payload）→
  回执已在 → 重试跳过，不会把同一批流量记两遍。

因此 `TrafficFetchJob` 从 `tries = 1`（任何一次临时错误都整块丢流量）改为 3 次、退避 1 / 5 / 10 秒。
回执保留 8 天（覆盖 Horizon 失败任务的 7 天保留期，在仪表盘里重试旧任务也安全），`reset:log` 每天清理。
`dispatchSync` 与测试里直接 `handle()` 没有队列 uuid，直接执行、不记回执。

## 定时任务（`app/Console/Kernel.php`）

| 命令 | 节奏 | 说明 |
|---|---|---|
| check:order | 每分钟 | 只派发「待支付且超过 2 小时」（要取消）和「开通中」（要补开通）的订单。阈值 `OrderHandleJob::PENDING_CANCEL_AFTER`。原先把所有待支付单都派一遍，2 小时内的新单到 worker 只是加锁后原样返回 |
| check:traffic-exceeded | 每分钟 | 取 `traffic:pending_check`，超量用户通过 WebSocket 通知节点 |
| reset:traffic | 每分钟 | 到了 `next_reset_at` 的用户重置流量 |
| device:reconcile-online-counts | 每分钟 | Redis 设备状态回写 `online_count` |
| check:commission | 每 5 分钟 | 订单完成 3 天后确认佣金、结算（原每分钟；不需要分钟级精度） |
| check:ticket | 每 5 分钟 | 24 小时无回复的工单自动关闭（原每分钟） |
| commission:refresh-usdt-rate | 每 10 分钟 | |
| horizon:snapshot | 每 5 分钟 | Horizon 指标快照 |
| renew:auto | 每小时 | 余额自动续费 |
| check:auto-increment | 每小时 | 自增主键耗尽告警 |
| xboard:statistics / reset:log / ticket:clean-attachments / billing:prune-documents / commission:period-sweep | 每天凌晨 | |
| mail:delivery-digest 09:00、send:remindMail 11:30、email-verify:remind 11:35、commission:notify-digest 12:00 | 每天 | 邮件类 |

每条命令是一次 artisan 子进程启动（本地实测约 0.25 s CPU）。支付回调里的开通走 `OrderHandleJob::dispatchSync`，
不经过队列，Horizon 没起来也不卡单。

## Octane / Swoole（`config/octane.php`）

- `swoole.options.max_request` / `task_max_request` = 10000：命令行默认 500 会让 worker 每十几秒就重新 boot 一次框架。
- `swoole.options.dispatch_mode` = 3（抢占模式，只投给空闲 worker）。默认 2 按 fd 取模固定分配；节点流量经
  openresty → docker-proxy 走短连接，内核总复用最小 fd，结果 48 个 worker 里只有连号的十来个在干活
  （2026-10-21 生产：4 小时 52 次回收全在 11 个 worker 上）。
- `OperationTerminated` 用 `App\Listeners\Octane\RollBackLeakedTransactions` 代替 `DisconnectFromDatabases`：
  MySQL 连接跨请求复用（原来每个请求重连一次，约 48 次/秒），只在连接上还挂着未提交事务时回滚并断开、记 warning。
  日志里出现「transaction left open at end of operation」说明某个手写 `beginTransaction()` 的异常路径漏了
  `rollBack()`，应当修那段代码。

## 部署注意

- 生产主机把 `config/horizon.php` 挂载进容器：改了仓库里的这份，部署时要同步主机上的那份，再重启
  `horizon` 容器（`docker compose up -d horizon`，Horizon 收到 SIGTERM 会等在跑的任务结束）。
- `v2_job_receipt` 由迁移 `2026_10_21_000001` 创建：先 `migrate --force` 再换镜像，旧 worker 不认识这张表也不会
  读它（只有新代码写）。
- 部署前后看 Horizon 仪表盘的 Wait Time 和 failed jobs（`/monitor`，或后台「系统 → 队列」），
  `redis-cli llen queues:traffic_fetch` 可以直接看积压。
