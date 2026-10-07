# 收据与续费账单邮件

付款开通后给用户发带 PDF 的收据；到期前发带续费账单 PDF 的提醒，附续费入口和其他可购套餐；佣金提现打款 / 驳回后发结果邮件。对标 WHMCS 的 Invoice / Receipt 邮件，抬头带品牌 logo。

## 什么时候发什么

| 时机 | 邮件 | 附件 | 触发点 |
|---|---|---|---|
| 订单开通（`OrderService::open()` 完成，含自动续费单） | 收据：付款成功、服务已开通 | `RC-<付款日期>-<8 位码>.pdf` | `SendBillingMailJob::dispatchReceipt()`，事务提交后派发 |
| 到期前 `billing_invoice_days` 天（默认 7） | 首张续费账单：按上次配置的续费金额、付款截止、其他套餐 | `INV-<到期日期>-<8 位码>.pdf` | 每日 `send:remindMail` 扫描 |
| 到期前 24 小时 | 最后提醒：同一张账单，标题换成「24 小时内到期」 | 同上 | 原到期提醒的位置，替换旧 `remindExpire` 模板 |
| 管理员标记提现已打款 / 驳回 | 提现结果：金额、实付 USDT、链、地址、交易哈希（打款）或原因与退回金额（驳回） | 无 | `CommissionWithdrawalService::notifyUser()` → `SendBillingMailJob::dispatchWithdrawal()` |

细节：

- 实付 0 元的订单不发收据（余额全抵、纯折抵都算实付 0）。
- 一笔订单只发一次收据（`v2_order.receipt_sent_at`）；同一个到期日的首张账单 / 最后提醒各只发一次（`v2_user.invoice_notified_at` / `invoice_final_notified_at` 记的是到期时间戳，续费后自然失效）。
- 任务执行时如果用户已经续费（`expired_at` 变了），账单作废不发。
- 开了自动续费且余额足够：首张账单照发（标「自动续费」、不催付款）；24 小时档不发，因为一小时内自动续费就会扣款并发收据。原来「已自动续费」的纯文字邮件不再发，只保留 Telegram。
- 自动续费没扣成（余额不够或套餐不能续）：同一到期日发一次纯文字通知（邮件 + Telegram），语言跟 `billing_locale`。余额不够时不说「去充值」（站点没有充值入口）：先给 `/plans?mode=renew` 在线续费链接，再说可以兑换礼品卡或转入佣金，到期前补足一小时内自动续上；宽限期（`auto_renew_grace_hours`，默认 72）内补足也会续，但新周期从续上那一刻算起，中间停用一段。套餐不能续（下架、没有可买周期、当前配置买不了）时说明原因，链接给 `/plans` 让用户换一个套餐。
- 套餐停售 / 不允许续费：邮件只引导换套餐、列出其他套餐，不附 PDF。
- 「也可以看看这些套餐」默认取在售套餐里月均价最接近当前套餐的 3 个；后台 `billing_recommend_plan_ids` 可以指定（逗号分隔套餐 id，按给定顺序）。
- 尊重用户的「到期提醒」开关（`remind_expire`）与站点 `remind_mail_enable`。
- 提现结果邮件有交易哈希时主按钮指向区块浏览器，没有就指向 `/invite` 的佣金记录；用户自己取消的申请不发邮件（和原来一致）。

## 品牌 logo

邮件抬头和 PDF 抬头都放 logo + 站点名。来源按顺序：后台 `billing_logo` → 站点 `logo`（用户端已经在用的那张）→ 都没有就只显示站点名。`billing_logo` 可以是完整 URL，也可以是相对用户端 `app_url` 的站内路径（如 `/brand/logo.png`）。

- 邮件里直接 `<img src>` 引用 URL，高度固定 40px、宽按比例，由邮件客户端加载。
- PDF 必须嵌图：`BrandLogo` 下载一次，用 gd 等比缩成最长边 240px 的 PNG（webp / jpg / gif 都转；svg 原样嵌入），缓存在 `storage/app/billing/logo/`，7 天后重新拉。每份 PDF 因此增加约 15–20 KB。拉不到时 PDF 退回纯文字抬头，邮件不受影响。
- 换 logo：改后台 URL 即可，缓存按 URL 哈希存，新 URL 立即生效；同一 URL 换图最多 7 天后生效，或者删掉缓存目录。

## 后台设置（`/api/v2/admin/config` 的 `email` 段）

| 键 | 默认 | 说明 |
|---|---|---|
| `billing_receipt_enable` | 1 | 财务邮件模板族总开关：开通后发收据；提现结果走 `billing/` 模板。关掉则不发收据，提现结果回落到各邮件主题自带的 `withdrawCompleted` / `withdrawRejected` |
| `billing_invoice_enable` | 1 | 到期前发带 PDF 的账单；关掉回落旧的 `remindExpire` 模板 |
| `billing_invoice_days` | 7 | 首张账单提前天数，0 或 1 = 只发 24 小时档 |
| `billing_locale` | `zh-CN` | 文案语言：`zh-CN` / `zh-TW` / `en-US`（用户表没有语言字段，全站统一） |
| `billing_issuer` | 空 | PDF 抬头下的开具方一行，如公司名 / 联系邮箱 |
| `billing_logo` | 空 | 抬头 logo 的 URL 或站内路径；留空用站点 `logo` |
| `billing_recommend_plan_ids` | 空 | 推荐套餐 id 列表 |

XBoard-admin 目前没有这些开关的界面，用默认值即可；要改可以直接 POST 到 config save 接口。

## 实现

- 数据与渲染：`app/Services/Billing/BillingDocumentService.php`。金额单位是分；收据按订单的 `total_amount / balance_amount / discount_amount / surplus_amount / handling_amount` 还原「小计 → 折扣 → 折抵 → 手续费 → 合计 → 已付 → 应付余额」；账单金额来自 `RenewService::resolveSpec()`（与仪表盘快捷续费完全一致）。
- 发送：`app/Jobs/SendBillingMailJob.php`，走 `send_email` 队列，payload 只带 id，到 worker 里才渲染 PDF；通过 `MailService::deliver()` 复用后台 SMTP 配置与 `v2_mail_log`。
- 模板：`resources/views/billing/mail/*`（邮件：`receipt` / `invoice` / `withdrawal`，共用 `layout`）与 `resources/views/billing/pdf/document.blade.php`（PDF）。放在 `billing/` 而不是 `mail/<主题>/` 下：生产的 `resources/views/mail` 被宿主机目录挂载覆盖，而且这些模板不该跟着 `email_template` 切换。各主题目录里的 `withdrawCompleted` / `withdrawRejected` 只在 `billing_receipt_enable=0` 时还会用到。
- logo：`app/Services/Billing/BrandLogo.php`。
- 文案：`resources/lang/{zh-CN,zh-TW,en-US}/billing.php`。
- PDF：mPDF，字体是仓库内子集化的 Noto Sans SC / Noto Serif SC（`resources/fonts/billing/`，GB2312 + Big5 常用字），只嵌入用到的字形，单份 40–70 KB，整封邮件远低于 OCI Email Delivery 默认 2 MB 的上限。首次渲染会在 `storage/framework/cache/mpdf` 建字体度量缓存，之后每份约 0.3 s、峰值内存约 40 MB。
- 链接一律用后台设置的 `app_url`：`/dashboard`、`/plans?mode=renew`、`/plans?plan=<id>`、`/settings`，都是 sufe-my-theme 已有路由。

## 升级

1. 镜像需要重新构建：Dockerfile 新增 `gd` 扩展（mPDF 的硬依赖），composer 新增 `mpdf/mpdf`。
2. 升级时跑一次 `php artisan xboard:update`：迁移 `2026_10_06_000002_add_billing_documents` 只加三列，可重复执行。
3. 不需要改中间件或前端。

归档与重新下载、退信处理、余额流水、到期后的通知见 [财务面板补全](finance-panel.md)。
