# 推广增长:首单优惠、阶梯比例、周期返佣申请、入账通知、分码漏斗、月度榜单

> 2026-10 在「付费被邀请人提现门槛」([commission-withdrawal.md](commission-withdrawal.md))之上加的一组推广侧功能。
> 全部 **additive**:老接口字段一个不改、不新增中间件保护路径;下单、续费、付款永远不受任何一项影响。
> 唯一新增的路由是周期返佣申请(用户端 `POST /user/invite/period/apply` + 后台 `commission-period/*`,见第五节)。
> 每项都有独立开关,默认除「入账通知」外全部关闭,部署后在后台逐项打开。

## 一、管理员配置

后台「系统设置 → 邀请佣金」(对应 `admin_setting` 键,`config/fetch?key=invite` 一起返回):

| 键 | 含义 | 默认 |
| --- | --- | --- |
| `invite_first_order_discount` | [B1] 被邀请人**首单**优惠百分比,12 = 88 折;0 关 | 0 |
| `commission_tier_enable` | [B2] 阶梯比例开关 | 关 |
| `commission_tier_2_min` / `commission_tier_2_rate` | [B2] 付费被邀请人 ≥ N 时比例升到 R% | 10 / 15 |
| `commission_tier_3_min` / `commission_tier_3_rate` | [B2] 档位 3 | 30 / 20 |
| `commission_period_auto_enable` | [B3] 开放周期返佣申请(键名沿用最初的「自动开启」版) | 关 |
| `commission_period_auto_min_invitees` | [B3] 有效付费被邀请人达到 N 才能申请;已开通的掉到 N 以下自动取消资格 | 50 |
| `commission_notify_enable` | [C1] 佣金入账汇总通知总开关(邮件 + Telegram) | 开 |
| `commission_leaderboard_enable` | [B5] 邀请页月度榜单 | 关 |

基础比例仍是原来的 `invite_commission`,首单 / 周期的站点默认仍是 `commission_first_time_enable`;
管理员在用户编辑里单设的 `commission_rate` / `commission_type` **永远优先**于阶梯和周期返佣申请。

## 二、口径

**付费被邀请人**(`InviteeStats::paidInviteeCount`,与提现门槛同一份):订单 `invite_user_id` = 邀请人、
`status = 已完成`、`total_amount > 0`,按下单人去重。

**有效付费被邀请人**(`InviteeStats::activePaidInviteeCount`,[B3] 用):上面的人里,当前**有套餐且未到期**的。
到期、被清掉套餐就不算,所以被邀请人流失到门槛以下时,已开通的周期返佣会被自动取消资格。

**生效规则**(`App\Services\Commission\CommissionPolicy`):发佣(`OrderService::setInvite`)与邀请页展示
(`invite/fetch` 的 `commission` 块)都从这里取,只有一份口径。

| | 优先级(高 → 低) |
| --- | --- |
| 比例 `rate` | 用户单设 `commission_rate`(非空非 0,沿用老的 truthy 语义) → 阶梯(开着且付费人数够到的最高档) → `invite_commission` |
| 方式 `mode` | 用户单设 `commission_type` 周期 / 一次性 → 已通过的周期返佣申请(开着且有效付费人数仍 ≥ N) → `commission_first_time_enable` |

每张订单**下单时**实时判定,不缓存:已开通的人掉到门槛以下,下一张订单就当场取消资格、按默认规则走。

**首单优惠**(`InviteDiscount`,[B1]):下单人有邀请人且 ≠ 自己、设置 > 0、尚无「已完成 / 处理中 / 已折抵」订单,
且不是重置流量 / 流量加油包 / 固定价加油包。折扣基数是当前 `total_amount`(升级差价之后),写进
`order.discount_amount` 与新列 `order.invite_discount_amount`。与优惠券**取大者不叠加**:券的优惠 ≥ 邀请折扣用券,
否则退回券的占用(`limit_use` 还原)改用邀请折扣,`coupon_id` 置空。佣金按折后实付算。

**分码漏斗**(`InviteFunnel`,[C2]):注册时把实际用的邀请码写进新列 `v2_user.invite_code`(Google 登录注册同样写);
每个码统计 打开 `pv` → 注册 `registered`(`invite_code = code` 的用户) → 付费 `paid`(这些用户里有已完成且实付 > 0
订单的去重人数) → 佣金 `commission_total`(这些用户订单的 `CommissionLog.get_amount` 求和)。
老用户没记码的归入 `unknown`。`daily` 为最近 30 天按天(`app.timezone`),缺的天补 0。每个用户缓存 120 秒。

**入账通知**(`CommissionNotifier`,[C1]):`check:commission` 每分钟一轮,一轮里每个邀请人收到的每一笔先攒着,
整轮结束**每人一封**汇总邮件(三级分销时上级各收各的);已绑 Telegram 的再推一条纯文本。单笔结算事务回滚的
不会进通知。发送条件:总开关 → 用户通知偏好新类别 `commission`(推广与佣金,可关、非批量、没有老列)→ 邮箱未退信。
通知失败只记日志,佣金早已落账。

**月度榜单**(`Leaderboard`,[B5]):本月(`app.timezone`)`CommissionLog.get_amount` 按邀请人求和取前 10;
`paid_invitees` 是本月给该邀请人带来佣金的不同被邀请人数(周期返佣下续费的老用户也算)。全站缓存 300 秒,
「我」的名次与数字实时算,不在前 10 时 `rank = null`。邮箱一律脱敏(`EmailMask`,`ab****@g****.com`)。

## 三、接口字段(全部 additive)

`GET /user/invite/fetch`:

```jsonc
{
  "first_order_discount": 12,
  "commission": {
    "rate": 15, "rate_source": "tier",              // user | tier | default
    "mode": "period", "mode_source": "applied",     // first_order | period;user | applied | default
    "tiers": { "enable": true, "base_rate": 10,
               "levels": [{"min":10,"rate":15},{"min":30,"rate":20}],
               "next": {"min":30,"rate":20} },      // 已到顶 / 用户单设 / 关着 → null;关着时 levels = []
    "period_auto": { "enable": true, "min_invitees": 50, "active_paid_invitee_count": 52,
                     "application": { "status": "approved",   // none | pending | approved | rejected | revoked
                                      "reason": null,          // 只有 rejected 时是驳回原因
                                      "applied_at": 1760000000, "decided_at": 1760003600, "revoked_at": null,
                                      "can_apply": false } }   // 开着 + 没有单设方式 + 人数够 + 不在审核中 / 已开通
  },
  "funnel": {
    "codes": [{ "code":"SUFE2026", "pv":128, "registered":9, "paid":4, "commission_total":6000 }],
    "unknown": { "registered":3, "paid":2, "commission_total":2400 },
    "daily": [{ "date":"2026-09-19", "registered":1, "paid":0, "commission":0 }]   // 30 条
  },
  "leaderboard": { "enable": false }               // 开着时见下
}
```

`leaderboard` 开着时:`{ "enable": true, "month": "2026-10", "items": [{ "rank", "email", "paid_invitees", "commission_total", "is_me" }], "me": { "rank": 7 | null, "paid_invitees", "commission_total" } }`。
`stat[3]`(佣金比例)改为**当前生效**比例(阶梯 / 单设之后的值);其余老字段不变。金额单位统一为分。

`GET /user/info`:`invite_first_order_discount`(int)、`invite_first_order_discount_eligible`(bool)。
`GET /guest/comm/config`:`invite_first_order_discount`(int)。
`GET /user/notify/prefs`:`categories` 自动多一项 `commission`。
订单上多一列 `invite_discount_amount`(分)。

## 四、部署注意

1. **迁移**:`2026_10_18_000001_add_invite_growth_columns.php` 加 `v2_user.invite_code`(varchar 32,索引)与
   `v2_order.invite_discount_amount`(int,默认 0),都带 `hasColumn` 守卫。走常规 `php artisan migrate --force`,
   不重写现有行,不必停 horizon。
2. **邮件模板要手动同步**:线上 `resources/views/mail` 是宿主机挂载的,镜像里新加的
   `mail/{editorial,classic,default}/commissionCredited.blade.php` 不会自动出现,要从新镜像里拷出来
   (`docker create` + `docker cp`)。没同步之前通知会自动退回通用 `notify` 模板(纯文本正文),不会报错。
3. **后台要填的值**(默认全关,开哪项填哪项):`invite_first_order_discount = 12`;
   `commission_tier_enable = 1`(档位默认 10 → 15%、30 → 20%,可改);
   `commission_period_auto_enable = 1`(开放申请)、`commission_period_auto_min_invitees = 50`;
   `commission_leaderboard_enable = 1`。`commission_notify_enable` 默认开,不想发就关。
4. **邀请码漏斗只从部署后开始有分码数据**:部署前注册的被邀请人没有 `invite_code`,全部落在 `unknown`。
5. **缓存**:漏斗 120 秒 / 榜单 300 秒走默认 cache store,不需要额外配置;发佣判定不缓存。
6. **周期返佣申请**(第五节)多一个迁移 `2026_10_18_000003_create_commission_period_application_table.php`
   (`hasTable` 守卫),多一个用户端路径 `/api/v1/user/invite/period/apply`,**中间件要先部署**
   (sufe-middleware-rs `pathname.rs` 已登记),否则前台的申请按钮请求不到后端。

## 五、周期返佣申请([B3],2026-10-18 由「达标自动开启」改为申请 + 人工审核)

站长不希望达标就自动给周期返佣,改成**用户申请、站长审核**;已开通的人有效付费被邀请人掉到门槛以下**自动取消资格**,
之后再达标要重新申请。代码在 `App\Services\Commission\PeriodCommission`。

**流程**

1. 开关开着、用户没有被管理员单设返佣方式、有效付费被邀请人 ≥ N、最近一条申请不是「审核中 / 已开通」时,
   邀请页出现「申请周期返佣」。`POST /user/invite/period/apply`(无参数,`throttle:invite-save`)
   在锁住用户行的事务里复核这几条,通过就写一条 `待审核`,返回新的 `commission` 块;
   开了 Telegram 机器人的话推一条给管理员,并触发钩子 `commission.period.apply.after`。
2. 后台「周期返佣申请」审核。**通过**时再数一次人数,已低于门槛的不让通过(只能驳回);**驳回**必须写原因,
   原因会显示在用户的邀请页上,也写进通知。
3. 已开通的资格在三处复核:发佣(下单时 `CommissionPolicy::forInviter`)、打开邀请页(`invite/fetch`)、
   每天 04:10 的 `commission:period-sweep`。任何一处发现低于门槛就把那条改成「已取消」(条件更新,并发下只取消、只通知一次)。
   发佣路径上的查询或取消出错一律按「没开通」处理并记日志,**不会拦下单**。
4. 通过 / 驳回 / 取消资格各发一封通用 `notify` 模板邮件(已退信的邮箱跳过),绑定了 Telegram 的再推一条。
   这三类是对用户自己申请的答复,不受通知偏好限制,也**不带任何编号**。

**表 `v2_commission_period_application`**:`user_id`、`status`(0 待审核 / 1 已开通 / 2 已驳回 / 3 已取消)、
`active_count` / `min_invitees`(申请时的人数与门槛)、`admin_id`、`reason`(驳回原因)、`decided_at`、
`revoked_at` / `revoked_count`(取消时的人数),时间都是 unix 秒。每次申请一行,历史保留;判定只看用户**最新**一行。

**后台接口**(`/api/v2/{secure_path}/commission-period/*`):

| 接口 | 说明 |
| --- | --- |
| `fetch` | 列表,参数 `status` / `email` / `user_id` / `current` / `pageSize`,待审核排最前;每行带申请时人数与**当前**人数;顶层带 `settings` |
| `stats` | `pending_count` / `approved_count` / `settings`,菜单角标用 |
| `approve` | `id` |
| `reject` | `id`、`reason`(必填,≤ 255) |

**边界**:关掉开关时已开通的人也不再按周期返佣(重新打开后仍达标的照常生效,不用重新申请),巡检也停;
管理员在用户编辑里单设了返佣方式的,以单设为准,不能也不用申请。上一版自动开启期间已经按周期算过的佣金不受影响。
