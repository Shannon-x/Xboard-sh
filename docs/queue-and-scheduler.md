# 队列、Horizon 与定时任务

后端的异步工作全部走 Redis 队列，由 Horizon（`php artisan horizon`，生产里是独立的 `horizon` 容器）消费；
定时任务由 Octane 的 `tick`（每 30 秒、带 `scheduler-lock` 分布式锁）在 Swoole task worker 里调用
`schedule:run`，每条到期的 artisan 命令各起一个子进程。生产主机 56 核 / 256 GB，配置按「快、准、不积压」取值，
不按省进程取值。

## 队列与 supervisor（`config/horizon.php` 的 `production`）

| supervisor | 队列（按优先级） | 常驻 / 上限 | 用途 |
|---|---|---|---|
| data-pipeline | traffic_fetch、stat、user_alive_sync | 每队列 2 / 共 16，按清空时间自动均衡，每秒最多加减 4 | 节点上报流量落库、每日统计 |
| business | order_handle、default | 每队列 2 / 共 6 | 订单取消 / 补开通、杂项 |
| notification | node_sync、send_email、send_telegram、send_email_mass | 每队列 2 / 共 12 | 节点名单增量推送、邮件、Telegram、群发 |

`balance=auto` 下 **每个队列一个独立进程池**，`minProcesses` 是每个队列的常驻下限，`maxProcesses` 是整个
supervisor 的上限。三组常驻 2×(3+2+4)=18 个 worker，每个占一条 MySQL 连接。

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

## 部署注意

- 生产主机把 `config/horizon.php` 挂载进容器：改了仓库里的这份，部署时要同步主机上的那份，再重启
  `horizon` 容器（`docker compose up -d horizon`，Horizon 收到 SIGTERM 会等在跑的任务结束）。
- `v2_job_receipt` 由迁移 `2026_10_21_000001` 创建：先 `migrate --force` 再换镜像，旧 worker 不认识这张表也不会
  读它（只有新代码写）。
- 部署前后看 Horizon 仪表盘的 Wait Time 和 failed jobs（`/monitor`，或后台「系统 → 队列」），
  `redis-cli llen queues:traffic_fetch` 可以直接看积压。
