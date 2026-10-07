# 续费账单的免登录付款

账单邮件和 PDF 里的「立即付款」按钮打开 `app_url/pay/<账单 id>-<签名>`，不用密码就能把这一张账单付掉。对标 WHMCS / Stripe 的 hosted invoice 链接：收到账单的人点一下就付，不必先想起密码。

## 凭据

- `BillingDocument::payToken()` = `<id>-<HMAC-SHA256(access_key, "pay.<id>") 前 32 位>`。`access_key` 是账单的随机密钥（下载链接也用它签），本身不出现在链接里。一张账单一个凭据，整个有效期内不变：7 天档和 24 小时档的邮件、PDF 上印的地址都是同一个。
- 凭据只证明「拿到链接的人收到过这封邮件」。能不能付、付到哪一步，每次请求都由 `BillingPayService::state()` 按账号现状重算，链接本身不记状态。
- 签名不对、不是续费账单一律 404，和「不存在」分不开，没有签名的人连「这个 id 存在」都问不出来。

## 状态

| state | 含义 | 页面 |
|---|---|---|
| `payable` | 还没下单，或上一单已取消 | 现价明细（套餐价 → 专属折扣 → 余额抵扣 → 还需支付）、支付方式、「立即支付」 |
| `pending` | 这张账单的订单已建、还没付（`pay_order_id`） | 接着付同一单（支付方式已绑定就锁住）、取消、刷新；4 秒一拍轮询 |
| `paid` | 订单已付款（开通中 / 已完成） | 付款成功，登录看收据 |
| `settled` | 用别的方式续过费了（`expired_at` 已经晚于账单的到期日） | 无需再付 |
| `expired` | 到期后超过 `billing_pay_link_days` 天，或账单已作废 | 链接失效，去套餐页续费（会先登录） |
| `unavailable` | 开关关了 / 账号封禁 / 套餐现状不能续（`reason` = `disabled` / `account` / `spec` / `plan_changed`） | 说明原因 |
| `blocked` | 账号另有一笔待付订单 | 登录先处理那一笔 |

## 下单与收款

- 下单走和仪表盘一键续费完全相同的 `OrderService::createFromRequest($user, $plan, $spec['period'], null, $spec['options'])`：按上次规格、余额自动抵扣、不叠加优惠券。页面上看到的金额随 `expected_amount` 带上，报价变了就拒绝，不悄悄按新价下单。
- 收款共用 `App\Services\CheckoutService`（从登录后收银台 `OrderController::checkout` 抽出来的那段：绑支付方式、算手续费、0 元直接开通、否则向网关要地址）。网关的 `return_url` 改回付款页本身，付完回来页面自己检测结果。
- 订阅式网关（Stripe / PayPal Subscription）不在可选支付方式里：一次性账单不该在网关那边开周期扣款。
- 账单开出后换了套餐（后台改的，到期日没变）不能照付：快照里记了 `plan_id`，和 `RenewService::resolveSpec()` 现在给的对不上就 `unavailable`。

## 接口（`/api/v1/guest/billing/pay/*`，都是 POST，凭据在 JSON 体里）

| 路径 | 用途 | 限流（按 IP） |
|---|---|---|
| `fetch` | 状态 + 账单摘要 + 现价 + 已建订单 + 支付方式 | 60 / 分钟 |
| `check` | 状态 + 订单状态（轮询用） | 60 / 分钟 |
| `checkout` | 下单（首次）/ 接着付，返回与 `/user/order/checkout` 同形的 `{type, data}` 加 `trade_no` | 10 / 分钟 |
| `cancel` | 只能取消这张账单自己的待付单，退回余额抵扣 | 10 / 分钟 |

四条路径都登记在 sufe-middleware-rs 的 `XB_EXTRA_PATHS`，走加密通道。

## 安全边界

拿到链接的人（收件人，或偷看了邮箱的人）能做的只有：看这张账单的套餐 / 周期 / 金额 / 打码邮箱，替这张账单下一笔续费单并付款（余额会被自动抵扣进这笔续费里），取消这笔单。看不到订单列表、改不了资料、动不了别的订单。付清或续过费链接就没用了；到期后超过宽限天数也失效。

## 后台设置

| 键 | 默认 | 说明 |
|---|---|---|
| `billing_pay_link_enable` | 1 | 关掉后邮件按钮改回 `/plans?mode=renew`，已发出的链接打开显示「站点已关闭邮件直接付款」 |
| `billing_pay_link_days` | 7 | 到期后链接仍可用的天数，0–30，0 = 到期即失效 |

## 文件

- `app/Services/Billing/BillingPayService.php`、`app/Http/Controllers/V1/Guest/BillingPayController.php`、`app/Services/CheckoutService.php`
- `BillingArchive::storeInvoice()` 存下记录后把 `pay_url` / `cta_url` / `pay_note` 补进快照；`SendBillingMailJob::sendInvoice()` 先存再渲染
- 列 `v2_billing_document.pay_order_id`（收据的 `order_id` 有唯一约束，不能复用）
- 主题：`sufe-my-theme` 的 `app/pay/[token]/`，接口在 `lib/api/client.ts` 的 `billPay*`
- 测试：`tests/Feature/BillingPayLinkTest.php`
