# 财务面板补全：归档、投递闭环、余额流水、到期之后

在 [收据与续费账单邮件](billing-receipts.md) 的基础上补齐四块：收据 / 账单归档（用户能重新下载、后台能重发）、邮件投递闭环（退信分类、暂停投递、Telegram 兜底、日报）、余额流水、到期后的「服务已暂停」与挽回邮件。前端对应 `sufe-my-theme` 的 `/billing` 页与仪表盘退信提示；中间件需登记 3 个接口和 1 个资源前缀。

## 1. 收据 / 账单归档

每份收据、账单 PDF 在发邮件**之前**先落盘并记一行 `v2_billing_document`：

| 列 | 说明 |
|---|---|
| `kind` / `doc_no` / `stage` | `receipt` / `invoice`；编号同邮件；账单档位 `first` / `final` |
| `order_id` / `expired_at` | 收据对应订单（唯一）；账单对应到期时间戳（同一到期日只留一份，24 小时档覆盖 7 天档的文件，编号与链接不变） |
| `amount` | 收据 = 实付金额，账单 = 应付金额（分） |
| `access_key` / `path` / `disk` / `size` | 128 位随机凭据；文件路径 / 对象 key 与它在哪（`local` = `storage/app/billing/documents/<user_id>/<doc_no>.pdf`，`s3` = `<前缀>/<user_id>/<doc_no>.pdf`）；`size = 0` 表示文件已按保留期清理，下载时重建 |
| `sent_at` / `send_count` / `channel` | 最近一次成功投递的时间、累计次数、渠道（`email` / `telegram`）；`sent_at` 为空 = 从未送达，后台统计里的「待送达文件」 |

- 下载：`GET /api/v1/guest/billing/document/{id}/{access_key}`，免登录、凭 key 校验（`hash_equals`），返回 `application/pdf` + `Content-Disposition: attachment`，每 IP 每分钟 120 次。文件丢了（换机器、volume 没挂）时收据按订单重渲染并补回磁盘，账单只在它仍是当前到期日且未到期时重渲染，否则 404。
- 用户端列表：`GET /api/v1/user/billing/documents`，最近 200 份，带状态：收据恒 `paid`；账单 `open`（待付）/ `settled`（此后续费了）/ `void`（到期超过 30 天仍未续费）。
- 后台：`/admin/billing/document/fetch`（按用户 id / 邮箱 / 类型 / 订单筛选，分页）与 `/admin/billing/document/resend`（收据按订单重渲染后再发；账单只在仍是当前到期日时允许重发）。重发走同一条投递链路，用户被标记暂停投递时照样转 Telegram / 留面板。
- 邮件正文底部多一行「也可以随时在面板「账单与收据」重新下载」，指向 `app_url/billing`。

### 1.1 存储位置：本地或 S3 兼容对象存储

归档文件默认落在本地 `storage/app/billing/documents/`；后台「收据与账单 → 存储位置」可切到 S3 兼容对象存储（Cloudflare R2、MinIO、Backblaze B2、阿里云 OSS / 腾讯云 COS 的 S3 网关等），对象 key 为 `<前缀>/<user_id>/<doc_no>.pdf`。请求走仓库自带的 `App\Services\ObjectStorage\S3ObjectClient`（Guzzle + SigV4，与工单附件共用，不引入 aws-sdk）。

- 桶**不需要**公开读，也不用预签名直链：下载始终由后端凭 `access_key` 校验后流式输出，用户只看到站点域名，经中间件一样可达，中间件不用改。
- 每份文档记住自己在哪（`disk` 列）：切换存储位置后新文件落新位置，旧文件仍按原位置读，不搬也能用；想集中搬过去跑 `php artisan billing:migrate-storage`（`--dry-run` 只统计）—— 逐份读出 → 写到新位置 → 删旧文件 → 更新 `path` / `disk`，原文件缺失的记录不动（下载时重建）。
- 对象存储写失败（网络、凭据失效）时本次退回本地盘并记 warning，收据 / 账单邮件照发；恢复后用上面的搬迁命令补搬。
- 后台「测试存储连接」（`POST /admin/config/testBillingStorage`）用表单里尚未保存的值写入 → HEAD → 删除一个探针对象，失败把 S3 的 `<Code>` / `<Message>` 原话带回。

### 1.2 保留期：归档不会无限膨胀

每份 PDF 55–90 KB，不清理的话一年几万份就是 GB 级，`v2_mail_log` 更是每天的到期 / 流量提醒都记一行。`billing:prune-documents` 每天 03:40 按后台配置的保留期清理（`--dry-run` 只统计）：

| 对象 | 默认保留 | 清什么 | 之后还能拿到吗 |
|---|---|---|---|
| 收据 PDF | 365 天（`billing_receipt_retention_days`） | 只删文件，记录保留并把 `size` 置 0 | 能：面板列表照常列出，下载 / 重发时按订单重新渲染（订单字段不变，内容一致）并补回存储 |
| 续费账单 | 周期结束后 90 天（`billing_invoice_retention_days`） | 文件和记录一起删，只删已结清（`settled`）/ 已失效（`void`）的；仍在 30 天付款窗口里的（`open`）不动 | 不能，也不需要：续费那一单自有收据，账单只是「待付款」的提醒 |
| 邮件投递日志 `v2_mail_log` | 180 天（`mail_log_retention_days`） | 由每日的 `reset:log` 分批（5000 行一批）删 | 日报 / 退信分类只看最近的记录 |
| 工单附件 | 365 天（`ticket_attachment_retention_days`，已有） | `ticket:clean-attachments` | — |

- 三个保留期都可在后台改，0 = 永久保留。文件删不掉（对象存储报错）的保留记录，下一轮再试。
- 稳态体量 ≈ 一年的收据数 × 80 KB + 一个季度的账单数 × 80 KB；后台「账单与收据」页顶部显示当前份数、占用与已清理数。

## 2. 邮件投递闭环

`MailService::deliver()` 的每一次发送都经 `App\Services\Mail\DeliveryMonitor::record()`：写 `v2_mail_log`（新增 `user_id` / `status` / `category`），并维护用户的暂停投递标记。

| 分类 | 判定 | 对用户标记的影响 |
|---|---|---|
| `ok` | 交给 SMTP 成功 | 清零失败计数、解除标记 |
| `suppressed` | 错误里含 `suppress`（OCI Email Delivery 的 `254 4.7.1 … suppressed`、SES 抑制名单） | 一次即标记 |
| `bounce` | `5.1.x` / `5.2.x` / `5.4.x` / `5.7.x`、`550/551/553/554`、`user unknown`、`mailbox unavailable` 等永久拒收 | 一次即标记 |
| `temporary` | 其余（4xx、超时、空响应） | 连续 3 次才标记 |
| `config` | SMTP 认证失败、连不上、TLS / 证书 | 不算在用户头上，不标记 |

标记（`v2_user.mail_suppressed_at` / `mail_suppressed_reason` / `mail_failed_count` / `mail_failed_at`）只拦**系统主动发**的邮件：收据、账单、到期 / 流量提醒、到期后通知。用户自己点的验证码 / 登录链接照发，发成功就自动解除标记。

投递顺序（`SendBillingMailJob::notify()`）：

1. 没被标记 → 发邮件。成功记 `sent_at`；`temporary` / `config` 失败交给队列重试（`SendEmailJob` 同样只在这两类失败时重试，退信不再重试）；`suppressed` / `bounce` → 用户被标记，转第 2 步。
2. 被标记 → 开了 Telegram Bot 且用户绑了 Telegram：有 PDF 就 `sendDocument`（caption 带主题、正文首段、入口链接），没有就 `sendMessage`；记 `channel=telegram`。
3. 都走不通 → 文件只留在面板（`sent_at` 为空），日志 `[billing] 邮件未能送达且无 Telegram`。

用户侧：`/api/v1/user/info` 多返回 `mail_suppressed_at` / `mail_suppressed_reason`，前端仪表盘据此显示提示；`POST /api/v1/user/mail/test` 发一封最短的测试邮件（10 分钟一次），成功即自动解除标记，失败返回分类与人话。

后台：`/admin/mail/log/fetch`（按邮箱 / 用户 / 状态 / 分类 / 模板 / 时间筛选）、`/admin/mail/stats`（24 小时 / 7 天的发送与失败数、失败分类、暂停投递用户数与最近几位、待送达文件数）、`/admin/mail/suppressed`（分页）、`/admin/mail/unsuppress`（手动解除）。

日报：`mail:delivery-digest` 每天 09:00（`onOneServer`），统计最近 24 小时；没有失败且没有新标记的用户就不发。发给 Telegram 管理员（`telegram_bot_enable`）和所有未被标记的管理员邮箱（`notify` 模板）。`--hours=` 改统计窗口，`--force` 没失败也发，`--dry-run` 只打印。

## 3. 余额流水

`v2_balance_log`：每次 `v2_user.balance` 变动一行，带变动前后快照（分，正入账、负出账）。

| `type` | 来源 | 关联 |
|---|---|---|
| `order_pay` | 余额支付 / 抵扣订单（用户下单、`OrderService::paid()` 重新开通时重扣） | `order_id` + `ref_id` = 订单号 |
| `order_cancel` | 取消待付订单退回抵扣 | 同上 |
| `order_refund` | 套餐变更折抵超出本单金额的部分退回 | 同上 |
| `gift_card` | 礼品卡余额奖励、邀请人的礼品卡奖励 | `ref_id` = 兑换码 |
| `commission_transfer` | 佣金划转到余额 | — |
| `admin_adjust` | 后台改用户余额（`/admin/user/update` 的差额）或 `/admin/billing/balance/adjust` | `operator_id` = 管理员 |
| `recharge` | 预留给充值插件 | — |

写入点统一经 `App\Services\BalanceLedger::record()`（`UserService::addBalance()` 默认记 `admin_adjust`，各调用方传自己的类型）。余额不足导致的失败不记。

- 用户端：`GET /api/v1/user/balance/log?page=&pageSize=`，返回 `{data, total, balance}`，每行 `type / amount / balance_after / remark / order_trade_no / created_at`（不下发操作人）。
- 后台：`/admin/billing/balance/log`（`user_id` 必填，含 `balance_before` / `operator_id`）；`/admin/billing/balance/adjust`（`user_id`、`amount` 单位元、可负、不能扣成负数，`remark` 可选）。

## 4. 到期之后

`send:remindMail` 的扫描在原来「到期前」两档之外，对**已到期**的用户按 `lifecycle_stage` / `lifecycle_expiry` 推进序列（都记到期时间戳，续费后 `expired_at` 变了自然重头开始）：

| 档 | 时机 | 邮件 |
|---|---|---|
| 1 | 到期后 2 天内 | 「服务已暂停」：原套餐、到期时间、按原配置续费的金额与入口（`/plans?mode=renew`）、其他套餐；有归档账单时链到面板。套餐不可续时只引导换套餐 |
| 2… | 到期后第 `billing_winback_days` 天（默认 `7,30`，每档 2 天窗口，最多 3 档） | 挽回：已离开 N 天、其他套餐；后台配了 `billing_winback_coupon` 且券仍有效时附优惠码，入口 `/plans?coupon=<code>`（主题会自动校验并应用） |

- 开了自动续费、宽限期内且余额足够的用户不发第 1 档（每小时的 `renew:auto` 马上会续上并发收据）。
- 尊重 `remind_expire`、封禁、无套餐；任务执行时已续费（`expired_at` 变了或又在未来）就作废。
- 窗口有界：首次部署不会把历史过期用户全部补发一遍（只有当前落在窗口内的才会发）。
- 都不附 PDF，走同一条投递链路（退信转 Telegram）。

## 后台设置（`/api/v2/admin/config` 的 `email` 段）

| 键 | 默认 | 说明 |
|---|---|---|
| `billing_expired_enable` | 1 | 到期后 2 天内发「服务已暂停」 |
| `billing_winback_enable` | 1 | 发挽回邮件 |
| `billing_winback_days` | `7,30` | 挽回天数，逗号分隔，1–365，升序去重后最多 3 档 |
| `billing_winback_coupon` | 空 | 挽回邮件附带的优惠码（后台已建、同名；过期 / 用完自动不带） |
| `mail_digest_enable` | 1 | 邮件投递日报 |
| `billing_storage_driver` | `local` | 归档存储位置：`local` / `s3` |
| `billing_s3_endpoint` / `billing_s3_region` / `billing_s3_bucket` / `billing_s3_access_key` / `billing_s3_secret_key` | 空 / `auto` / 空 / 空 / 空 | S3 兼容存储连接信息；Endpoint 留空按 Region 拼 AWS 官方地址 |
| `billing_s3_path_style` | 1 | 路径式访问（`端点/桶/key`）；关闭为虚拟主机式（`桶.端点/key`） |
| `billing_s3_prefix` | `billing/documents` | 对象 key 前缀 |
| `billing_receipt_retention_days` | 365 | 收据 PDF 保留天数，到期只删文件（可重建），0 = 永久 |
| `billing_invoice_retention_days` | 90 | 账单在周期结束后保留天数，到期连记录删（待付款的不删），0 = 永久 |
| `mail_log_retention_days` | 180 | 邮件投递日志保留天数，0 = 永久 |

## 实现

- 归档：`app/Services/Billing/BillingArchive.php`、`app/Models/BillingDocument.php`、`app/Http/Controllers/V1/Guest/BillingDocumentController.php`。
- 存储与保留：`app/Services/Billing/BillingStorageConfig.php`（设置与默认值）、`app/Services/Billing/Storage/`（`DocumentStore` 接口、`LocalDocumentStore`、`S3DocumentStore`、工厂）、`app/Services/ObjectStorage/`（`S3ObjectClient`、`S3SignatureV4`，工单附件驱动也用它）、`app/Console/Commands/PruneBillingDocuments.php`、`MigrateBillingStorage.php`、`ResetLog.php`（邮件日志）。
- 投递：`app/Services/Mail/DeliveryMonitor.php`、`app/Jobs/SendBillingMailJob.php`（`notify()` / `telegram()`）、`app/Console/Commands/MailDeliveryDigest.php`、`app/Http/Controllers/V2/Admin/MailController.php`。
- 流水：`app/Services/BalanceLedger.php`、`app/Models/BalanceLog.php`；写入点在 `UserService` / `OrderService` / `GiftCardService` / 用户端 `OrderController`、`UserController::transfer` / 后台 `UserController::update`。
- 到期后：`MailService::lifecycleStageDue()` / `markLifecycle()`，`BillingDocumentService::expired()` / `winback()` / `winbackCoupon()`，模板 `resources/views/billing/mail/{expired,winback,test}.blade.php`，文案在三份 `billing.php` 的 `expired` / `winback` / `mail_test` / `archive` 段。
- 用户端接口：`app/Http/Controllers/V1/User/BillingController.php`；后台：`app/Http/Controllers/V2/Admin/BillingController.php`。
- 测试：`tests/Feature/FinancePanelTest.php`、`tests/Feature/BillingStorageRetentionTest.php`、`tests/Unit/S3ObjectClientTest.php`。

## 升级

1. 跑一次 `php artisan xboard:update`：迁移 `2026_10_06_000003_add_finance_ledger_and_mail_delivery` 建 `v2_billing_document` / `v2_balance_log`，给 `v2_mail_log` 加 `user_id` / `status` / `category`，给 `v2_user` 加 `mail_*` 与 `lifecycle_*` 列；`2026_10_07_000001_add_billing_document_disk` 给已建过表的实例补 `disk` 列；都可重复执行。
2. 用本地存储时 `storage/app/billing/documents` 要在持久卷上（Docker 部署一般已把 `storage` 挂出来）；换成对象存储就没有这个要求。文件丢了收据能重建，账单只能在当期重建。切换存储位置后可跑一次 `php artisan billing:migrate-storage` 把旧文件搬过去。
3. 中间件 `sufe-middleware-rs` 需包含 `/api/v1/user/billing/documents`、`/api/v1/user/balance/log`、`/api/v1/user/mail/test` 三条路径与 `/api/v1/guest/billing/document` 资源前缀（老版本可先用 `EXTRA_OBFUSCATED_PATHS` / `EXTRA_ASSET_PREFIXES` 热修）。
4. 前端主题 `sufe-my-theme` 对应版本带 `/billing` 页；老主题不受影响（新字段都是可选的）。
5. 存量数据：历史订单没有归档收据（只有升级后开通的订单才有）；历史余额变动没有流水；历史退信不会回溯标记，从升级后的第一封邮件开始计。
6. XBoard-admin 对应版本带「财务管理 → 账单与收据 / 邮件投递」页、用户列表的「余额流水 / 调账」以及「收据与账单」设置页（含存储位置、测试连接与三个保留期）；老版本后台只能直接调上面的接口。
7. 保留期清理从升级后第一次 03:40 的计划任务开始生效；想先看会清多少，`php artisan billing:prune-documents --dry-run`。
