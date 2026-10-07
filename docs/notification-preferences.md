# 用户通知偏好

用户可以逐类关闭非必要的通知邮件，交易类邮件照发。对标 WHMCS / Stripe 客户门户的「邮件偏好」：一列类别、每行一个开关，没有「全部退订」按钮；退订入口是邮件页脚一行小字「管理通知偏好」，点开是免登录的偏好页。

## 类别

| key | 含义 | 覆盖的邮件 | 老字段 |
|---|---|---|---|
| `billing` | 账单与到期提醒 | 续费账单（到期前 N 天 / 24 小时）、老的到期提醒、到期当天的「服务已暂停」、自动续费结果 | `remind_expire` |
| `usage` | 流量与用量 | 流量用到 80% 的提醒 | `remind_traffic` |
| `support` | 工单回复 | 工单有新回复 | — |
| `announcement` | 服务公告 | 后台群发的默认类别 | — |
| `marketing` | 活动与优惠 | 到期后的召回邮件、后台群发里标为「活动」的邮件 | — |

交易类邮件（收据、提现结果、验证码、登录链接、邮箱自测）不属于任何类别，永远发送；面板里只以「始终发送」列出，不给关。

`billing` / `usage` 同时镜像到 `v2_user.remind_expire` / `remind_traffic`：扫描任务 `send:remindMail` 还是按这两列筛用户，老前端、后台、Telegram 机器人里的开关也还读写它们。读取时这两类以老列为准，两边永远一致。老接口 `/user/update` 改这两列时会以 `legacy` 来源写进偏好表。

> 两个老开关都关掉的用户不会进入续费扫描，所以也收不到到期后的召回邮件（`marketing`），这是老逻辑本来的行为。

## 存储

- `v2_user_notification_pref`：每用户每类别一行，`enabled` / `source` / `ip` / `updated_at`。没有行 = 开启。`source` ∈ `panel` / `email_link` / `list_unsubscribe` / `admin` / `legacy`，后台靠它看退订是从哪条路来的。
- `v2_user.notify_key`：32 位十六进制随机串，免登录偏好页与一键退订的凭据，首次发信时生成（`NotificationPreference::key()`），不含用户 id。后台可以换掉（`rotate`），旧邮件里的链接随即失效。

## 发信侧网关

`App\Services\Notification\NotificationPreference::allows($user, $category)` 是唯一的判断入口：

- `MailService::sendEmail()`：`SendEmailJob` 的 params 带 `user_id` + `category` 时先过网关，不允许就返回 `['skipped' => true]`，不进投递日志。续费服务、工单回复、后台群发都这样投。
- `SendBillingMailJob`：每种 kind 固定一个类别（`CATEGORY` 映射），不允许时记 `skipped`，Telegram 也不发。
- `MailService::deliver()` 收到类别后：
  - 模板变量 `manage_url`（`app_url/notify/<key>`，退订来源 `email_link`）、`manage_label`（后台 `notify_footer_label`，留空用字典 `billing.footer.manage`）、`manage_category`；`settings_url` 也改指偏好页。
  - `announcement` / `marketing` 两类加 `List-Unsubscribe: <https://…/api/v1/guest/notify/unsubscribe/<key>/<category>>` 与 `List-Unsubscribe-Post: List-Unsubscribe=One-Click`（RFC 8058），Gmail / Apple Mail 顶部会出现「退订」按钮，点下去只关这一类。后台开关 `notify_list_unsubscribe_enable` 可以整体关掉。

类别为空的邮件（验证码、收据等）不过网关也不带退订头，页脚链接退回 `settings_url`。

## 接口

| 路径 | 方法 | 鉴权 | 用途 | 限流 |
|---|---|---|---|---|
| `/api/v1/user/notify/prefs` | GET | 登录 | 当前用户的偏好：`{categories: [{key, enabled, locked, source, updated_at}], always: [...]}` | — |
| `/api/v1/user/notify/prefs/save` | POST `{prefs: {cat: bool}}` | 登录 | 改一类或多类，来源 `panel` | — |
| `/api/v1/guest/notify/fetch` | POST `{token}` | 凭据 | 免登录偏好页：脱敏邮箱 + 偏好 | 30 / 分钟 |
| `/api/v1/guest/notify/update` | POST `{token, prefs}` | 凭据 | 免登录改偏好，来源 `email_link` | 10 / 分钟 |
| `/api/v1/guest/notify/unsubscribe/{key}/{category}` | POST | 凭据 | 邮件客户端一键退订，200 `OK`，来源 `list_unsubscribe` | 10 / 分钟 |
| 同上 | GET | 凭据 | 人点开链接：302 到偏好页并带 `?from=<category>` | 10 / 分钟 |
| `/api/v2/<secure_path>/notify/stats` | GET | 管理员 | 每类关闭人数与来源分布、近 30 天退订次数、关过任一类的用户数 | — |
| `/api/v2/<secure_path>/notify/log` | GET | 管理员 | 退订记录分页，`category` / `source` 筛选 | — |
| `/api/v2/<secure_path>/notify/fetch` | GET `user_id` 或 `email` | 管理员 | 单用户偏好 | — |
| `/api/v2/<secure_path>/notify/save` | POST `{user_id, prefs}` | 管理员 | 代用户改，来源 `admin` | — |
| `/api/v2/<secure_path>/notify/rotate` | POST `{user_id}` | 管理员 | 换免登录凭据 | — |

凭据不对一律 404。前四条登记在 sufe-middleware-rs 的 `XB_EXTRA_PATHS` 走加密通道；一键退订由邮件客户端直接 POST，没有加密能力，中间件内置明文直通规则 `/api/v1/guest/notify/unsubscribe/*`（`BUILTIN_PASSTHROUGH_RULES`），不需要在配置里声明。

## 提醒频率

扫描任务 `send:remindMail` 每天 11:30 跑一次，每封提醒都有自己的去重标记，不会因为天天扫描而天天重发：

| 邮件 | 去重粒度 | 标记 |
|---|---|---|
| 续费账单（到期前 N 天）/ 最后提醒（24 小时内） | 同一个到期日各一封 | `v2_user.invoice_notified_at` / `invoice_final_notified_at`（存到期时间戳，续费后自然重新开始） |
| 到期当天「服务已暂停」/ 第 N 天召回 | 同一个到期日每档一封 | `v2_user.lifecycle_stage` + `lifecycle_expiry` |
| 流量预警（默认 80%）/ 流量已用完 | 同一个流量周期各一封 | `v2_user.traffic_notified_level`（1 = 预警已发，2 = 用完已发），用量回落到阈值以下时清零 |

流量提醒以前靠 24 小时的 Redis 标记去重，与每日扫描同周期，用量停在 80%–99% 的用户每天都会收到一封；现在按周期记档位：

- 用到 `remind_traffic_percent`（默认 80，可配 50–99）发一封「流量已使用 N%」，正文带已用 / 总量 / 剩余与下次重置日期。
- 用完时再发一封「本周期流量已用完」（`remind_traffic_exhausted_enable`，默认开），正文带重置日期、加购 / 升级入口与套餐推荐。直接跳到 100% 的用户只收这一封。
- 流量重置、升级套餐、后台加流量让用量回落到阈值以下，标记清零，下个周期重新计算。
- 预警那封不和当天的账单 / 到期邮件叠发（顺延到第二天）；「用完」关系到服务可用性，照发。
- 派发到执行之间用量回落（重置、升级）的，执行时再核一遍，不发。
- 两封都走 `SendBillingMailJob`（kind `traffic`，类别 `usage`）与 `resources/views/billing/mail/traffic.blade.php`，不在宿主机挂载的目录里。关掉收据 / 账单邮件（`billing_receipt_enable=0`）时回落老的 `remindTraffic` 模板，只有预警这一档。

## 后台设置（系统设置 → 邮件）

| 键 | 默认 | 含义 |
|---|---|---|
| `notify_optional_categories` | 五类全部 | 允许用户自己关闭的类别，逗号分隔；去掉的类别面板里显示为「始终发送」，用户此前关掉的也照发 |
| `notify_footer_label` | 空（用字典「管理通知偏好」） | 邮件页脚那行链接的文案 |
| `notify_list_unsubscribe_enable` | 1 | 批量类邮件是否带一键退订头 |
| `remind_traffic_percent` | 80 | 流量预警阈值（50–99） |
| `remind_traffic_exhausted_enable` | 1 | 流量用完时再发一封 |

后台群发（用户管理 → 发送邮件）多了类别单选：服务公告（默认）/ 活动与优惠 / 必达通知（不过网关）。

## 模板

六套 `resources/views/mail/editorial/*.blade.php` 与 `resources/views/billing/mail/layout.blade.php` 的页脚改读 `$manage_url` / `$manage_label`，缺省时退回原来的地址与文案。生产环境这两个目录是宿主机挂载进容器的，更新镜像后要手工同步一次模板文件。

## 测试

`tests/Feature/NotificationPreferenceTest.php`：网关跳过、老列镜像、免登录页、一键退订（含 GET 302）、退订头只在批量类出现、锁定类别照发、后台接口、凭据轮换。
