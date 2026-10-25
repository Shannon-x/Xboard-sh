# 余额充值

用户先往余额里充钱，之后买套餐、续费、加购时余额自动抵扣。原先是 BalanceRecharge 插件，2026-10 并入核心：插件用反射把 13 条核心订单路由降级、自己另写一份订单逻辑，核心后来的收银台加固在生产上一直没生效；充值还有一套独立的回调入口。现在充值单与套餐订单共用收银台、回调、金额守卫和事后核验。

## 单据

`v2_recharge_order`（迁移自 `plugin_recharge_orders`，id 不变），金额单位分：

| 字段 | 含义 |
|---|---|
| `deposit_amount` | 充值金额 |
| `bonus_amount` | 赠送金额，下单时按当时的档位定死，之后改档位不影响已下的单 |
| `total_credit` | 入账总额 = deposit + bonus |
| `pay_amount` | 应付金额 = deposit + 手续费，首次选定支付方式时算出 |
| `status` | 0 待支付 / 2 已取消 / 3 已到账 |

和 `v2_order` 分开存：充值不开通任何东西、不返佣、不参与折抵与续费推算，混进订单表会让「上一张已付订单」「首单」之类的判断失真。

## 生命周期

- **下单**：同金额的待支付单直接复用；金额不同就把旧的待支付单作废再建新单（不用先手动取消）。每人每小时最多 20 张。
- **收银台**：与套餐订单相同的规则（`CheckoutService::mustRebind`）——首次选支付方式时绑定并算手续费；同一商户的别名配置可以切（自己接 webhook 的网关除外，它们只认精确的 payment_id）；跨商户只有新旧两条支付配置都认支付会话号（PaymentAttempt 插件，目前 EPay / BEpusdt）时才放行并改绑，否则旧收款链接付的钱会被回调拒收。
- **能付充值的网关**：回调必须经过核心 `/payment/notify`。自己接 webhook 的 StripeCheckout / StripeSub / PaypalSubscription 只认套餐订单，`getPaymentMethod` 对它们返回 `supports_recharge=false`，收银台也拒绝。
- **入账**（`RechargeService::credit`，回调与后台确认共用）：行锁内重读状态，一张单只入账一次；一笔网关流水只能入账一张单据（与订单共用 `v2_payment_callback`，按 `subject_type` 区分）。余额流水记 `recharge` 类型，`ref_id` 是充值单号。
- **超时取消**：待支付超过 2 小时由 `check:order` 取消，与订单一致。
- **取消后到账**：照样入账（充值没有「开通不了」的情形）并通知管理员。
- **入账时改绑**：单据记到真正收到钱的那条支付配置上（命中会话时应付额取会话冻结值），事后核验与退款回查才查得对。
- **手动确认后回调才到**：认作同一笔（记下流水号、不再加钱），并提醒管理员核对用户是否其实付了两次。
- **已到账的单又来一笔不同流水**：另一条支付会话收的钱按实付额退回余额；同一条会话收到第二笔、或没有会话可核对时转人工（告警），都向网关 ACK，不静默吞钱。

## 回调

网关回调统一走 `/api/v1/guest/payment/notify/{method}/{uuid}`：`PaymentController` 在 `v2_order` 里找不到 trade_no 时按充值单处理。插件时代的 `/api/v1/guest/payment/recharge-notify/{method}/{uuid}` 保留为别名，给发起时用旧地址的在途单。

金额守卫 `PaymentGuard` 原生认充值单（期望值 = `pay_amount`，已含手续费）；支付会话号由 PaymentAttempt 插件经 `payment.expected_amount` 过滤器回报会话冻结的金额。

## 接口

用户端沿用 wyx2685/v2board 前端的约定，与套餐订单共用 `/api/v1/user/order/*`，不需要新的中间件白名单：

| 接口 | 充值单的写法 |
|---|---|
| `POST save` | `plan_id=0`、`period=deposit`、`deposit_amount`（分），返回 trade_no |
| `GET detail` / `fetch` | `plan_id=0`、`period=deposit`、`type=9`、`is_recharge=true`；`total_amount` 是充值金额，`bounus` / `get_amount`（原文拼写）是赠送 / 到账额，另有 `bonus_amount` / `total_credit` / `pay_amount` |
| `POST checkout` / `GET check` / `POST cancel` | 与订单相同；trade_no 可以是支付会话号 |
| `GET getPaymentMethod` | 每个支付方式多一个 `supports_recharge` |

`/api/v1/guest/comm/config` 里：`balance_recharge_enable`、`balance_recharge_min` / `max`（元）、`deposit_bonus_tiers`（`[{amount, bonus}]`，元）、`balance_recharge_quick_amounts`（元）。

后台 `/api/v2/{secure_path}/recharge/*`：`fetch`（筛选 status / trade_no / email / user_id / payment_id / 时间）、`detail`（含入账流水与付款核验结论）、`paid`（手动确认到账，待支付与已取消的单都能确认，流水记操作人）、`cancel`、`stats`。

## 设置（系统设置 → `recharge` 分组，金额单位元）

| 键 | 默认 | 说明 |
|---|---|---|
| `recharge_enable` | 0 | 关掉后不能新下单、不能再拉起支付；已发出的收款链接付了钱照样入账 |
| `recharge_min_amount` / `recharge_max_amount` | 1 / 99999 | 单笔上下限，最多两位小数 |
| `recharge_bonus_tiers` | `[]` | `[{amount, bonus}]`，取满足门槛的档位里赠送最多的一档；赠送不能超过该档门槛 |
| `recharge_quick_amounts` | `[]` | 充值弹窗的快捷金额，空则用前端默认 |

## 统计

收入（后台仪表盘、`v2_stat` 每日统计、旧统计接口）= 已付订单的 `total_amount` + 已到账充值的 `deposit_amount`（不含赠送与手续费），都按 `paid_at` 落日。用余额买套餐的那部分在订单上记 0，钱在充值时已经计过一次。

## 升级

迁移 `2026_10_25_000001_promote_balance_recharge_to_core` 改表名、加索引、给 `v2_payment_callback` 加 `subject_type`，把插件配置搬进系统设置并删掉插件行。改表名期间旧代码读不到表，所以要**停掉 web / horizon 之后**再跑迁移。`PluginManager::ABSORBED_PLUGINS` 拒绝再加载 / 安装 / 启用 `balance_recharge`，plugins/ 下残留的目录不会生效。

一次性历史修正：`php artisan recharge:backfill --ledger --stats [--dry-run]`。`--ledger` 把插件时代记成「后台调整」的充值入账流水改标为「充值」（同一用户、金额相等、时间紧挨到账时间且唯一对上的才改）；`--stats` 给旧代码生成的每日统计补上当天到账的充值（只加充值，订单部分保持当时的数字；只能补一次，系统设置 `recharge_stats_backfilled_before` 记截止日；要在上线当天跑，或用 `--before=YYYY-MM-DD` 指定上线日）。

回滚：`php artisan migrate:rollback --step=1`（表名改回、按系统设置写回插件行），再放回插件目录、换回旧镜像。
