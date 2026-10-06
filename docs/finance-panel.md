# 财务面板补全：归档、投递闭环、余额流水、到期之后

在 [收据与续费账单邮件](billing-receipts.md) 的基础上补齐四块：收据 / 账单归档（用户能重新下载、后台能重发）、邮件投递闭环（退信分类、暂停投递、Telegram 兜底、日报）、余额流水、到期后的「服务已暂停」与挽回邮件。前端对应 `sufe-my-theme` 的 `/billing` 页与仪表盘退信提示；中间件需登记 3 个接口和 1 个资源前缀。

## 1. 收据 / 账单归档

每份收据、账单 PDF 在发邮件**之前**先落盘并记一行 `v2_billing_document`：

| 列 | 说明 |
|---|---|
| `kind` / `doc_no` / `stage` | `receipt` / `invoice`；编号同邮件；账单档位 `first` / `final` |
| `order_id` / `expired_at` | 收据对应订单（唯一）；账单对应到期时间戳（同一到期日只留一份，24 小时档覆盖 7 天档的文件，编号与链接不变） |
| `amount` | 收据 = 实付金额，账单 = 应付金额（分） |
| `access_key` / `path` / `size` | 128 位随机凭据；`storage/app/billing/documents/<user_id>/<doc_no>.pdf` |
| `sent_at` / `send_count` / `channel` | 最近一次成功投递的时间、累计次数、渠道（`email` / `telegram`）；`sent_at` 为空 = 从未送达，后台统计里的「待送达文件」 |

- 下载：`GET /api/v1/guest/billing/document/{id}/{access_key}`，免登录、凭 key 校验（`hash_equals`），返回 `application/pdf` + `Content-Disposition: attachment`，每 IP 每分钟 120 次。文件丢了（换机器、volume 没挂）时收据按订单重渲染并补回磁盘，账单只在它仍是当前到期日且未到期时重渲染，否则 404。
- 用户端列表：`GET /api/v1/user/billing/documents`，最近 200 份，带状态：收据恒 `paid`；账单 `open`（待付）/ `settled`（此后续费了）/ `void`（到期超过 30 天仍未续费）。
- 后台：`/admin/billing/document/fetch`（按用户 id / 邮箱 / 类型 / 订单筛选，分页）与 `/admin/billing/document/resend`（收据按订单重渲染后再发；账单只在仍是当前到期日时允许重发）。重发走同一条投递链路，用户被标记暂停投递时照样转 Telegram / 留面板。
- 邮件正文底部多一行「也可以随时在面板「账单与收据」重新下载」，指向 `app_url/billing`。

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

## 实现

- 归档：`app/Services/Billing/BillingArchive.php`、`app/Models/BillingDocument.php`、`app/Http/Controllers/V1/Guest/BillingDocumentController.php`。
- 投递：`app/Services/Mail/DeliveryMonitor.php`、`app/Jobs/SendBillingMailJob.php`（`notify()` / `telegram()`）、`app/Console/Commands/MailDeliveryDigest.php`、`app/Http/Controllers/V2/Admin/MailController.php`。
- 流水：`app/Services/BalanceLedger.php`、`app/Models/BalanceLog.php`；写入点在 `UserService` / `OrderService` / `GiftCardService` / 用户端 `OrderController`、`UserController::transfer` / 后台 `UserController::update`。
- 到期后：`MailService::lifecycleStageDue()` / `markLifecycle()`，`BillingDocumentService::expired()` / `winback()` / `winbackCoupon()`，模板 `resources/views/billing/mail/{expired,winback,test}.blade.php`，文案在三份 `billing.php` 的 `expired` / `winback` / `mail_test` / `archive` 段。
- 用户端接口：`app/Http/Controllers/V1/User/BillingController.php`；后台：`app/Http/Controllers/V2/Admin/BillingController.php`。
- 测试：`tests/Feature/FinancePanelTest.php`。

## 升级

1. 跑一次 `php artisan xboard:update`：迁移 `2026_10_06_000003_add_finance_ledger_and_mail_delivery` 建 `v2_billing_document` / `v2_balance_log`，给 `v2_mail_log` 加 `user_id` / `status` / `category`，给 `v2_user` 加 `mail_*` 与 `lifecycle_*` 列；可重复执行。
2. `storage/app/billing/documents` 要在持久卷上（Docker 部署一般已把 `storage` 挂出来）；文件丢了收据能重建，账单只能在当期重建。
3. 中间件 `sufe-middleware-rs` 需包含 `/api/v1/user/billing/documents`、`/api/v1/user/balance/log`、`/api/v1/user/mail/test` 三条路径与 `/api/v1/guest/billing/document` 资源前缀（老版本可先用 `EXTRA_OBFUSCATED_PATHS` / `EXTRA_ASSET_PREFIXES` 热修）。
4. 前端主题 `sufe-my-theme` 对应版本带 `/billing` 页；老主题不受影响（新字段都是可选的）。
5. 存量数据：历史订单没有归档收据（只有升级后开通的订单才有）；历史余额变动没有流水；历史退信不会回溯标记，从升级后的第一封邮件开始计。
6. XBoard-admin 还没有这些接口的界面：退信情况靠日报；要查日志 / 重发 / 调余额先直接调上面的后台接口。
