# 邮件投递服务（Rust，`mail-relay/`）

## 为什么

三条邮件通道（[queue-and-scheduler.md](queue-and-scheduler.md#邮件通道)）解决了「批量堵住验证码」，但每封信
仍由一个 PHP worker 从头做到尾：渲染、连 SMTP、等服务商回 250。一封 SMTP 往返 0.3–1 秒，worker 在这段时间里
什么也干不了；要发得快只能加进程，而每个 PHP 进程常驻 60–120 MB。

投递服务把「发」从 PHP 里拿出来：PHP 只渲染成完整的 MIME 原文、写进 Redis Stream 就返回（几毫秒），
一个 Rust 进程用 SMTP 连接池并发发出去，结果写回 Redis，由面板的 `mail:relay-results` 记账。

## 默认关闭，随时退回

- `.env` 的 `MAIL_RELAY_LANES` 为空（默认）：与没有这个服务时完全一样，全部由 PHP worker 直发。
- 列出的通道（`bulk`、`transactional`、`instant`，逗号分隔）只在投递服务**心跳正常且已拿到 SMTP 配置**时入队；
  投递服务停了（心跳 30 秒过期）、Redis 写失败、spool 写失败，都自动改回直发，不丢信。
- 关掉：把 `MAIL_RELAY_LANES` 清空并重启 horizon。已经入队的信投递服务会继续发完；停掉投递服务前先看
  `/metrics` 里 `mail_relay_queue_depth` 和 `mail_relay_delayed` 都是 0。

建议上线顺序：先只开 `bulk`、限速调低，看几天 `v2_mail_log`，再开 `transactional`，最后 `instant`。

## 数据流

```
PHP worker ── 组 MIME ──► XADD mail:relay:stream:<lane>（大邮件写 storage/mail-spool/<id>.eml，通道里只放文件名）
                                   │
                    Rust：每条通道一个读取任务（XREADGROUP，消费者组 relay）
                                   │  有界内存队列
                    N 个投递 worker：按 即时 > 交易 > 批量 取信，其中 RELAY_RESERVED 个永不碰批量
                                   │  限速（全局令牌桶 + 批量份额）→ SMTP 连接池（lettre，STARTTLS）
                                   ▼
               XADD mail:relay:results ──► mail:relay-results（scheduler 每分钟）
                                              ├─ v2_mail_log + 用户退信标记（DeliveryMonitor，口径同直发）
                                              ├─ 收据 / 账单：发成功 markSent(email)；退信转 Telegram
                                              └─ 删 spool 文件
```

- **不丢**：读出来的信在 Redis 里一直挂在投递服务名下，发完（成功、放弃或排进重试）才确认删除。进程被杀，
  重启时先把自己名下没确认的领回来；改了 `RELAY_CONSUMER` 或两个实例交接，闲置 5 分钟的会被 XAUTOCLAIM 领走。
- **不重**：发成功先写 `mail:relay:done:<id>`（7 天），再确认。崩在两步之间，重启后看到 done 标记就跳过。
- **重试**：4xx / 超时 / 连接断开按 `RELAY_BACKOFF`（默认 3、10、30、120 秒）退避，最多 `RELAY_MAX_ATTEMPTS`（4）次；
  5xx 不重试；即时邮件过了 `not_after` 不再排重试，记 `expired`。
- **服务商喊慢**（OCI 超限回 455；421 / 451 / 452 带 rate / limit / too many）：这封信按 `RELAY_THROTTLE_BACKOFF`
  （默认 1、2、5、10、15 分钟）重排，最多 `RELAY_THROTTLE_MAX_ATTEMPTS`（10）次，不和普通临时失败共用次数；
  批量暂停 30 秒、速率减半，连着被限流暂停时间翻倍（最长 10 分钟），之后每分钟回升 10%。即时和交易不受影响。
- **24 小时额度**（OCI 的 max-emails-day：滚动 24 小时、按收件人计）：按分钟记在 `mail:relay:daily`，重启不归零。
  批量用到 `RELAY_DAILY_LIMIT - RELAY_DAILY_BULK_RESERVE` 就停，剩下的留给验证码和收据；额度满的信原样延后，
  不算投递次数。面板自己直发的（没开投递服务的通道）也占 OCI 的额度，所以默认只用 45000，不是 50000。
- **单封大小**（OCI 默认 2 MB，含头和 Base64 附件）：超过 `RELAY_MAX_MESSAGE_BYTES` 的不发，记 `config`
  （不算用户退信），收据 / 账单转 Telegram。现在一封带 PDF 的收据约 80 KB。
- **配置问题**（认证失败、TLS、连不上）：照样重试，最后记 `category = config`，不算在用户头上，`Log::error`。
  与直发不同的是不会进 `failed_jobs`：修好 SMTP 后要补发的话看日报里的 config 失败。
- **SMTP 账号**：`mail:relay-results` 每分钟、发信的进程最多每 60 秒把后台邮件设置推到 `mail:relay:config`（带内容摘要作版本号，没变不写），
  投递服务每 10 秒看一次，变了就换新连接池。没有这份配置时投递服务不发心跳，面板就继续直发。

## 已知限制

- lettre 不支持 SMTP PIPELINING：每封仍是一次完整的 MAIL / RCPT / DATA 往返，提速靠并发连接。
- 退信转 Telegram 时 PDF 从存档重新生成（通道里不带 PDF）；到期 / 挽回 / 流量提醒没有存档，只发文字，同直发。
- 收据交给投递服务那一刻就记 `receipt_sent_at`（防止重复派发再排一封）；真退信了会转 Telegram，
  `v2_billing_document.sent_at / channel` 以结果为准。
- 结果流最多留 5 万条：`mail:relay-results` 停了太久（几万封）最老的结果会被裁掉，那几封不会记进 `v2_mail_log`。
- 镜像目前只构建 linux/amd64。

## 部署

镜像：`ghcr.io/shannon-x/xboard-mail-relay:sha-<提交>`，由 `.github/workflows/mail-relay.yml` 在 master
上 `mail-relay/` 有改动时构建。compose 片段见 `mail-relay/compose.example.yml`，要点：

| 变量 | 默认 | 说明 |
|---|---|---|
| `RELAY_REDIS_URL` | `redis://127.0.0.1:6379/0` | 现网用 `redis+unix:///data/redis.sock`，挂同一个 data 目录 |
| `RELAY_KEY_PREFIX` | 空 | **必须**等于面板的 Redis key 前缀（现网 `xboard_database_`），否则两边看不到对方 |
| `RELAY_CONSUMER` | `relay-1` | 只跑一个实例 |
| `RELAY_CONNECTIONS` | 8 | 并发 SMTP 连接 = 投递 worker 数 |
| `RELAY_RESERVED` | 2 | 其中几个永远留给即时 / 交易 |
| `RELAY_RATE_PER_MINUTE` | 600 | 全局速率上限（封 / 分钟），0 = 不限。OCI 付费默认 18000 |
| `RELAY_BULK_SHARE` | 0.8 | 批量最多占全局速率的比例 |
| `RELAY_MAX_ATTEMPTS` / `RELAY_BACKOFF` | 4 / `3,10,30,120` | 重试 |
| `RELAY_THROTTLE_MAX_ATTEMPTS` / `RELAY_THROTTLE_BACKOFF` | 10 / `60,120,300,600,900` | 被限流时的重试 |
| `RELAY_DAILY_LIMIT` | 45000 | 滚动 24 小时最多发多少（按收件人），0 = 不限。OCI 付费默认 50000，以控制台为准 |
| `RELAY_DAILY_BULK_RESERVE` | 5000 | 24 小时额度里批量不能用的部分（留给验证码和收据） |
| `RELAY_MAX_MESSAGE_BYTES` | 2000000 | 单封原文上限，0 = 不拦。OCI 默认 2 MB |
| `RELAY_SPOOL_DIR` | `/www/storage/mail-spool` | 与面板 `storage/mail-spool` 是同一个目录 |
| `RELAY_SMTP_TIMEOUT` | 30 | 单次 SMTP 操作超时（秒） |
| `RELAY_HTTP_ADDR` | `0.0.0.0:9810` | `/healthz`、`/metrics`（Prometheus 文本） |
| `RELAY_LOG` / `RELAY_LOG_FORMAT` | `info` / 文本 | `json` 方便 docker logs 检索 |

开了 `bulk` 之后面板的 `MAIL_BULK_PER_MINUTE` 设回 0：那个限速卡的是 PHP 组信入队的速度，真正的发信速率改由 `RELAY_RATE_PER_MINUTE` 管。

面板侧：`MAIL_RELAY_LANES`、`MAIL_RELAY_INLINE_MAX_BYTES`（默认 64 KB，超过写 spool）、`MAIL_RELAY_SPOOL_DIR`。

## 本地联调

```
redis-server --daemonize yes --save "" --appendonly no
python3 某个 SMTP 测试桩，监听 127.0.0.1:2525
cd mail-relay && RELAY_KEY_PREFIX=e2e_ RELAY_SPOOL_DIR=$PWD/../storage/mail-spool cargo run
MAIL_RELAY_E2E=1 REDIS_PREFIX=e2e_ php vendor/bin/phpunit tests/Feature/MailRelayRedisTest.php
```

Rust 单元测试：`cd mail-relay && cargo test`。


## 后台仪表盘

仪表盘的「邮件投递」卡片读 `GET /api/v2/{secure_path}/system/getMailRelayStats`（`App\Services\Mail\MailRelayStatus`）。
数字全部来自投递服务每 5 秒写一次的心跳 `mail:relay:heartbeat`，面板不连投递服务的 HTTP 端口：

- 各通道由谁发：`relay`（投递服务）、`fallback`（开了但心跳超过 30 秒没更新，面板直发）、`direct`（没开）
- 各通道待发（含正在发的）、等着重试的条数
- 每分钟发送量（最近 60 分钟，每分钟一格）和每分钟上限
- 滚动 24 小时额度：已用、上限、批量停止线，批量是否已暂停
- 启动以来的限流（455）、重试、因额度延后、验证码过期次数（重启清零）
- 近 24 小时成功 / 失败按 `v2_mail_log` 算，面板直发和投递服务发的都算在里面
