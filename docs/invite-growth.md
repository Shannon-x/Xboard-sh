# 推广增长:首单优惠、阶梯比例、自动周期返佣、入账通知、分码漏斗、月度榜单

> 2026-10 在「付费被邀请人提现门槛」([commission-withdrawal.md](commission-withdrawal.md))之上加的一组推广侧功能。
> 全部 **additive**:老接口字段一个不改、不新增路由、不新增中间件保护路径;下单、续费、付款永远不受任何一项影响。
> 每项都有独立开关,默认除「入账通知」外全部关闭,部署后在后台逐项打开。

## 一、管理员配置

后台「系统设置 → 邀请佣金」(对应 `admin_setting` 键,`config/fetch?key=invite` 一起返回):

| 键 | 含义 | 默认 |
| --- | --- | --- |
| `invite_first_order_discount` | [B1] 被邀请人**首单**优惠百分比,12 = 88 折;0 关 | 0 |
| `commission_tier_enable` | [B2] 阶梯比例开关 | 关 |
| `commission_tier_2_min` / `commission_tier_2_rate` | [B2] 付费被邀请人 ≥ N 时比例升到 R% | 10 / 15 |
| `commission_tier_3_min` / `commission_tier_3_rate` | [B2] 档位 3 | 30 / 20 |
| `commission_period_auto_enable` | [B3] 自动周期返佣开关 | 关 |
| `commission_period_auto_min_invitees` | [B3] 有效付费被邀请人达到 N 自动周期返佣;掉到 N 以下自动恢复首单返佣 | 50 |
| `commission_notify_enable` | [C1] 佣金入账汇总通知总开关(邮件 + Telegram) | 开 |
| `commission_notify_frequency` | [C1] 入账通知多久一封:`instant` 每轮结算 / `daily` 每天 12:00 / `weekly` 每周一 12:00 | `weekly` |
| `commission_leaderboard_enable` | [B5] 邀请页月度榜单 | 关 |

基础比例仍是原来的 `invite_commission`,首单 / 周期的站点默认仍是 `commission_first_time_enable`;
管理员在用户编辑里单设的 `commission_rate` / `commission_type` **永远优先**于阶梯和自动周期。

## 二、口径

**付费被邀请人**(`InviteeStats::paidInviteeCount`,与提现门槛同一份):订单 `invite_user_id` = 邀请人、
`status = 已完成`、`total_amount > 0`,按下单人去重。

**有效付费被邀请人**(`InviteeStats::activePaidInviteeCount`,[B3] 用):上面的人里,当前**有套餐且未到期**的。
到期、被清掉套餐就不算,所以周期返佣会随着被邀请人流失自动掉回首单返佣。

**生效规则**(`App\Services\Commission\CommissionPolicy`):发佣(`OrderService::setInvite`)与邀请页展示
(`invite/fetch` 的 `commission` 块)都从这里取,只有一份口径。

| | 优先级(高 → 低) |
| --- | --- |
| 比例 `rate` | 用户单设 `commission_rate`(非空非 0,沿用老的 truthy 语义) → 阶梯(开着且付费人数够到的最高档) → `invite_commission` |
| 方式 `mode` | 用户单设 `commission_type` 周期 / 一次性 → 自动周期(开着且有效付费人数 ≥ N) → `commission_first_time_enable` |

每张订单**下单时**实时判定,不缓存:掉到门槛以下的下一张订单就按首单规则走。

**首单优惠**(`InviteDiscount`,[B1]):下单人有邀请人且 ≠ 自己、设置 > 0、尚无「已完成 / 处理中 / 已折抵」订单,
且不是重置流量 / 流量加油包 / 固定价加油包。折扣基数是当前 `total_amount`(升级差价之后),写进
`order.discount_amount` 与新列 `order.invite_discount_amount`。与优惠券**取大者不叠加**:券的优惠 ≥ 邀请折扣用券,
否则退回券的占用(`limit_use` 还原)改用邀请折扣,`coupon_id` 置空。佣金按折后实付算。

**分码漏斗**(`InviteFunnel`,[C2]):注册时把实际用的邀请码写进新列 `v2_user.invite_code`(Google 登录注册同样写);
每个码统计 打开 `pv` → 注册 `registered`(`invite_code = code` 的用户) → 付费 `paid`(这些用户里有已完成且实付 > 0
订单的去重人数) → 佣金 `commission_total`(这些用户订单的 `CommissionLog.get_amount` 求和)。
老用户没记码的归入 `unknown`。`daily` 为最近 30 天按天(`app.timezone`),缺的天补 0。每个用户缓存 120 秒。

**入账通知**(`CommissionNotifier`,[C1]):每个邀请人**每次一封**汇总邮件(三级分销时上级各收各的),
已绑 Telegram 的再推一条纯文本。多久一次看 `commission_notify_frequency`:

- `weekly`(默认):`commission:notify-digest` 每天 12:00 跑,只在周一发,汇总上次以来落库的 `CommissionLog`
  (`CommissionDigest`)。标题「每周佣金汇总 ¥x」,正文「过去一周共 n 笔」。
- `daily`:同一个命令每天都发,标题「每日佣金汇总 ¥x」。
- `instant`:老行为。`check:commission` 每分钟一轮,一轮里的入账攒着、整轮结束每人一封;因为每笔订单满 3 天的
  时间点各不相同,实际接近一笔一封。单笔结算事务回滚的不会进通知。

汇总的水位是上次发到的最大 `CommissionLog` id(`v2_settings.commission_notify_digest_last_id`),发完才推进;
另有「周期 + 1 天」的回看下限,第一次跑或从 `instant` 切回来时最多回看一个周期,不会翻出几个月前的历史佣金。
总开关关着时水位照样推进,再打开不补发。`CommissionLog` 不存层级,开了三级分销时顺着被邀请人的邀请链反推。
一封最多列最近 30 笔,多出来的写「另有 n 笔未列出」。

发送条件:总开关 → 用户通知偏好新类别 `commission`(推广与佣金,可关、非批量、没有老列)→ 邮箱未退信。
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
    "mode": "period", "mode_source": "auto",        // first_order | period;user | auto | default
    "tiers": { "enable": true, "base_rate": 10,
               "levels": [{"min":10,"rate":15},{"min":30,"rate":20}],
               "next": {"min":30,"rate":20} },      // 已到顶 / 用户单设 / 关着 → null;关着时 levels = []
    "period_auto": { "enable": true, "min_invitees": 50, "active_paid_invitee_count": 12 }
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
   每周 / 每日汇总(`commission_notify_frequency`)又改了这三个模板(标题、说明、「另有 n 笔未列出」),部署后要**再同步一次**;
   没同步时旧模板照样能渲染,只是大标题仍是「佣金入账」、说明仍是「本轮共 n 笔」。汇总不需要迁移,水位存 `v2_settings`。
3. **后台要填的值**(默认全关,开哪项填哪项):`invite_first_order_discount = 12`;
   `commission_tier_enable = 1`(档位默认 10 → 15%、30 → 20%,可改);
   `commission_period_auto_enable = 1`、`commission_period_auto_min_invitees = 50`;
   `commission_leaderboard_enable = 1`。`commission_notify_enable` 默认开,不想发就关。
4. **邀请码漏斗只从部署后开始有分码数据**:部署前注册的被邀请人没有 `invite_code`,全部落在 `unknown`。
5. **缓存**:漏斗 120 秒 / 榜单 300 秒走默认 cache store,不需要额外配置;发佣判定不缓存。
