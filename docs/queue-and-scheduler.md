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
| notification | node_sync、send_telegram | 各 2 | 节点名单增量推送、Telegram |
| mail-instant | mail_instant | 2（`MAIL_INSTANT_WORKERS`） | 验证码、登录链接 |
| mail-transactional | mail_transactional | 2（`MAIL_TRANSACTIONAL_WORKERS`） | 收据、提现、工单回复、续费结果等 |
| mail-bulk | mail_bulk | 3（`MAIL_BULK_WORKERS`） | 账单 / 到期 / 挽回 / 流量提醒、验证提醒、到货、佣金日报、后台群发 |
| mail-legacy | send_email、send_email_mass | 各 1 | 升级前的旧队列，只为排空升级那一刻的存量；两个队列清零后下一版删掉 |

全部 `balance=simple`：进程数在 supervisor 的队列间平分、**不伸缩**，每个队列一个独立进程池。生产采集看到旧配置
（`auto` + `balanceCooldown=1`）下 data-pipeline 的 worker 大多活不过 70 秒，自动伸缩在不停地杀 / 起进程；而
traffic_fetch 每 5 分钟约 2,100 个任务（单个 30–40 ms）、stat 约 4,200 个（单个 25 ms），固定池足以让每个队列
始终有空闲 worker 阻塞等在队列上。8 组默认共 37 个 worker，各占一条 MySQL 连接（`max_connections=400`）。
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
order_handle / node_sync 30、traffic_fetch 60、send_telegram 120、stat 300；邮件 mail_instant 15、
mail_transactional 60、mail_bulk 900，旧队列 send_email 120、send_email_mass 600。

## 邮件通道

2026-11-27 之前所有邮件挤在一条 `send_email` 队列、2 个 worker：11:30 的批量账单 / 到期提醒（每封要渲染 PDF、
走一次 SMTP 往返）排出 1,265 封，后来的验证码要等 19 分钟，用户拿到时早已过期。现在按「用户等不等得起」分三条
（`App\Services\Mail\MailLane`），各自一组 worker，批量再多也挤不到前两条：

- **即时** `mail_instant`：验证码、登录链接。派发时带 `not_after`（有效期截止，300 秒）。排到时已过期就不发，
  `v2_mail_log` 记一条 `category = expired`，不计入用户的退信 / 临时失败（`MAIL_DROP_EXPIRED_INSTANT=0` 关掉）。
- **交易** `mail_transactional`：`SendEmailJob` 不传队列时的默认值，没想清楚的新调用方最多影响收据，碰不到验证码。
- **批量** `mail_bulk`：定时扫描和群发。`MAIL_BULK_PER_MINUTE`（默认 0 = 不限）给所有批量 worker 一个全局
  每分钟封数上限（`App\Jobs\Middleware\MailLaneThrottle`，Redis 限流），用来对齐 SMTP 服务商的速率限额。

批量账单里每封都要算一次「也可以看看」的套餐席位：`PlanCapacity` 在邮件路径上按套餐组合缓存 60 秒
（`MAIL_CACHE_SECONDS`，进程内），前台列表和下单校验照旧实时查；`v2_user (plan_id, expired_at)` 联合索引让这条统计只读索引。

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
- `swoole.options.dispatch_mode` = 3（抢占模式，只投给空闲 worker）。默认 2 按连接的 fd 固定分配；生产约 94%
  的请求（含节点 UniProxy 流量）经中间件 xb-secure 的 5 条 keep-alive 长连接进来，于是只有约 5 个 worker
  在干活（2026-10-21 生产：60 秒采样前 5 个 worker 占 71% CPU，4 小时 52 次回收全在 11 个 worker 上）。
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
