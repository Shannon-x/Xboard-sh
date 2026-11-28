# 用户访问记录（访问日志 + 流量日志）

Rust 节点（sufe-node-rust）按连接记下「哪个用户、什么时候、从哪个 IP、访问了哪个网站和端口、走了哪个出站、上下行各多少字节」，按批上传到面板；面板把明细原样写进 S3 兼容存储，库里只存文件索引和「用户 × 天 × 节点」汇总。后台可按用户查看每天的连接数和流量，按需查明细、看最常访问的网站、导出 CSV。

只有 Rust 节点会上报。V2bX（Go）节点不受影响：它们的流量上报、在线上报、握手和配置拉取的接口和行为都没有变。

## 配置项（后台 → 系统设置 → 用户访问记录）

| 键 | 默认 | 说明 |
|---|---|---|
| `access_log_enable` | `0` | 总开关。只有打开且存储已配好，握手才会让节点开始记录 |
| `access_log_interval` | `300` | 节点封批上传的间隔（秒），60 到 3600。越短越及时，S3 对象越多 |
| `access_log_retention_days` | `30` | 明细、索引和汇总保留天数，1 到 365 |
| `access_log_s3_endpoint` | 空 | R2 填 `https://<account>.r2.cloudflarestorage.com`；空 = `https://s3.{region}.amazonaws.com` |
| `access_log_s3_region` | `auto` | R2 用 `auto` |
| `access_log_s3_bucket` / `_access_key` / `_secret_key` | 空 | 凭证。建议单独建桶，密钥只授权这个桶的读写删 |
| `access_log_s3_path_style` | `1` | 路径式 `{endpoint}/{bucket}/{key}` |
| `access_log_s3_prefix` | `access-logs` | 对象 key 前缀 |

「测试存储连接」用表单里的当前值写入、分段读回、删除一个探针对象。

## 数据流

1. 节点每条连接结束时（长连接每 10 分钟中途一次）产生一条记录，进有界队列，由单独线程按用户 ID 分 32 组各自 gzip，凑满间隔、跨整点或攒到 8 MiB 时封成一批，先落盘到 `<state_dir>/access-spool/`，再 `POST /api/v2/server/access-log`（`application/octet-stream`，节点凭据在 query，与其它 `api/v2/server` 接口相同）。
2. 面板校验批次头（版本、32 组覆盖整个数据段、各用户条数之和等于总条数、时间在近 8 天内），把整批原样写到
   `{prefix}/v1/dt=YYYY-MM-DD/node={type}-{id}/hour=HH/{ledger}-{seq}.tsv.gz`（日期和小时按面板时区），
   再在 `v2_access_log_file` 记下每组的字节区间，在 Redis 里累加用户小计。同一批重传只会存一次（S3 存在检查 + Redis 标记 + 唯一索引）。
3. `access-log:flush`（每 5 分钟）把 Redis 小计并入 `v2_access_log_user_day`。
4. 查明细时只按区间读取该用户所在组（约整批的 1/32），解压后挑出该用户的行。查询在 `default` 队列里跑，结果 CSV 写回 S3，后台用 10 分钟有效的预签名链接下载。

节点在面板不可达或 S3 出错（503）时保留批次、退避重试（30 秒起，最多 10 分钟）；面板判定批次本身有问题（400/413/415）时节点删除该批。

## 明细格式

一批 = 一行 JSON 头 + `\n` + 32 个 gzip 成员。每行明细是制表符分隔：

```
user_id  started_at  seconds  tcp|udp  host  port  outbound|-  source_ip  up  down  finished(1|0)
```

`finished=0` 是长连接的中途上报，`up`/`down` 是距上次上报的增量，所以同一连接的多行可以直接相加。域名统一小写；不是合法域名的目标名写成 `[redacted]`，不会把任意字节写进日志。

## 表

- `v2_access_log_file`：每批一行，记节点、小时、对象 key、32 组区间、条数和流量。
- `v2_access_log_user_day`：主键 `(user_id, dt, node_type, node_id)`，连接数、上下行、出现过的小时位图。
- `v2_access_log_query`：每次明细查询一行（谁查的、查谁、范围、筛选、状态、结果），同时是审计记录，保留 365 天。

## 后台接口（`admin` 中间件，全部 POST）

`accessLog/overview`、`accessLog/summary`、`accessLog/query`、`accessLog/queries`、`accessLog/result`、`accessLog/testStorage`。都用 POST，所以只读令牌调不到，且每次调用都进请求日志。明细查询一次最多 7 天、30 万行。

## 清理

`php artisan access-log:prune [--dry-run]`，每天 03:25：删除超过保留期的 S3 对象、索引和汇总，7 天前的导出文件，365 天前的查询记录。

建议在桶上另设生命周期规则兜底：`access-logs/v1/` 保留天数 + 2 天，`access-logs/exports/` 8 天。这样即使面板的计划任务停了，数据也不会超期留存。

## 上线前

1. 建桶和只限该桶的密钥，在后台填好并点「测试存储连接」。
2. 设好上面的生命周期规则。
3. 在服务条款或隐私说明里告知会记录访问的网站、时间、流量和客户端 IP，以及保留多久。
4. 确认面板在跑 Redis、队列（`default`）和计划任务。请求体上限沿用工单附件已经放到的 32 MB（Swoole `package_max_length`、`post_max_size`），节点单批不超过 24 MiB。前置 nginx / Caddy 的 `client_max_body_size` 也要不小于 24 MiB。
5. 打开开关。节点最迟在下一次握手（约 5 分钟）后开始记录。关掉开关后，节点在下一次握手时停止记录，面板对此后收到的批次只回成功、不再保存。

## 节点侧上限

全进程排队 16,384 条、缓冲 16 MiB、单批 8 MiB、落盘目录 1 GiB（满了删最旧的批）。队列满时丢弃新记录并在批次头里计数（`dropped`），不会阻塞转发。
