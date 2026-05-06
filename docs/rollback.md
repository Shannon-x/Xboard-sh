# 回滚剧本（Rollback Playbook）

> 适用于 P0 支付链路修复阶段。任何线上异常先按本文 30 秒回滚，再排查。

## 触发回滚的指标阈值

| 信号 | 阈值 | 检查命令 |
|---|---|---|
| 重复 webhook 处理 | 1h 内 `> 10` | `redis-cli HGET payment_metrics:order.paid.duplicate:$(date +%Y%m%d%H) _total` |
| webhook 金额不匹配（warn 模式） | 出现且无法解释 | `redis-cli HGET payment_metrics:webhook.amount_mismatch:$(date +%Y%m%d%H) _total` |
| 订单卡在 PROCESSING | 任意一笔 `> 5min` 未完成 | `SELECT count(*) FROM v2_order WHERE status=2 AND paid_at < UNIX_TIMESTAMP(NOW())-300` |
| `payment` 队列堆积 | size `> 1000` | `php artisan horizon:status`（看 payment queue size） |
| 客服工单出现"付款后未开通" | `>= 1` | 工单系统 |

任意一项触发 → 立即按对应章节回滚。

---

## 阶段 1 修复的回滚

### Fix #5  EPay 金额/状态校验（FEATURE_PAYMENT_AMOUNT_CHECK）

**默认值**：`warn`（仅记录日志，不拒收）。升级镜像后即生效，无需任何 .env 配置。

**症状**：（仅 enforce 模式）合法订单 webhook 被拒，用户付款后订单仍是 PENDING。

**回滚**：
```bash
# 把 enforce 改回 warn 或 off
FEATURE_PAYMENT_AMOUNT_CHECK=warn
php artisan config:cache
```
**无需重启 PHP-FPM/Octane**（Laravel config cache 自动 reload）。

**人工恢复被错误拒收的订单**：
```sql
-- 找出最近 1h 状态仍为 PENDING 但理应已支付的订单
SELECT id, trade_no, total_amount, callback_no, created_at
FROM v2_order
WHERE status = 0
  AND created_at > UNIX_TIMESTAMP(NOW()) - 3600;
```
对每一条核对 EPay 后台真实交易记录，确认已收款后人工调用：
```bash
php artisan tinker
>>> $order = App\Models\Order::where('trade_no', 'XXX')->first();
>>> app(App\Services\OrderService::class, ['order' => $order])->paid('manual_recovery_' . time());
```

---

### Fix #1 + #2  paid() 行锁 + OrderHandleJob 事务（无 flag，永远开启）

**说明**：行锁与事务包裹是纯 bug 修复（旧 `lockForUpdate` 在 autocommit 下立即释放），
对合法用户行为完全等价，因此不设 flag——升级镜像即生效。`OrderHandleJob` 仍走
`dispatchSync`（同步派发），不依赖队列 worker，**不需要 Horizon 已启动**。

**症状**：用户付款后订单状态卡在 PROCESSING 不变 COMPLETED（极少见，需 DB 异常）。

**回滚**：纯 bug 修复无 flag 可回滚，需 `git revert`。回滚前先：
```sql
-- 找出受影响订单
SELECT id, trade_no FROM v2_order
WHERE status = 2 AND paid_at < UNIX_TIMESTAMP(NOW()) - 300;
```
对每条 trade_no 手工触发再处理：
```bash
php artisan tinker
>>> App\Jobs\OrderHandleJob::dispatchSync('XXX');
```

---

### Fix #8  Payment config 隐藏（FEATURE_PAYMENT_SECRET_HIDE）

**默认值**：`false`（旧行为，列表返回完整 config）。前端未配套改造前**不要打开**。

**症状**：后台支付管理页面编辑表单空白（前端依赖 config 字段渲染）。

**回滚**：
```bash
FEATURE_PAYMENT_SECRET_HIDE=false
php artisan config:cache
```
**无任何数据风险**，仅 API 响应结构差异。

---

## 阶段 2 修复的回滚

阶段 2 的 Coupon / GiftCard / 密码 hash_equals / Passport 限流均是**直接代码修改、无 flag**：

### Coupon / GiftCard 并发修复
**症状**：合法用券/兑卡报错"已耗尽"。
**回滚**：`git revert <commit>` → 重新部署。回滚前先：
```sql
-- 备份相关表，便于事后核对
mysqldump xboard v2_coupon v2_gift_card_code v2_gift_card_usage > /tmp/coupon_backup_$(date +%s).sql
```

### 密码 hash_equals + 渐进升级
**症状**：用户登录失败比例突增。
**回滚**：`git revert`。**注意**：已经升级到 bcrypt 的用户不会回退（兼容向后），无需数据修复。

### Passport 限流
**症状**：注册/登录在某些 IP 被批量拒绝。
**回滚**：临时把 `RouteServiceProvider` 中 `RateLimiter::for(...)` 的 `Limit::perMinute(N)` 调高到 100；或 `git revert` 拆掉 `throttle:` 中间件。
**根因常见**：`TrustProxies` 没识别 CDN，所有请求识别为 CDN 节点 IP → 全站限流。先排查这条。

---

## 通用应急

### 30 秒全量回滚（最坏情况）
```bash
# 假设蓝绿部署，旧版本仍在 server-blue 运行
# 切流量回 server-blue
sudo nginx -s reload                        # 或 ELB / k8s service 切换
# 此时 server-green（新版本）只接受健康检查不收流量
```

### 数据快照恢复（极端情况，慎用）
```bash
# 1. 停止 webhook 入口
nginx -s stop   # 或在 ingress 加临时 deny rule
# 2. 恢复指定表
mysql xboard < /backup/v2_order_$(date +%Y%m%d).sql
# 3. 重启 worker
php artisan horizon:terminate
# 4. 恢复入口
nginx -s start
```

⚠️ **支付表恢复必须由 DBA 执行**，恢复期间到达的真实 webhook 会被网关重投，但用户体验上"付款不成功"会持续到恢复完成。

---

## 演练

每一条 P0 修复**部署到 prod 之前**，必须在 staging 走完一次"灰度→人工触发回滚"演练，并把演练时长记录在 PR 描述中。

| Fix | 已演练？ | 演练耗时 | 备注 |
|---|---|---|---|
| #5 EPay | ☐ | — | warn（默认）→ enforce → off 全路径 |
| #1+#2 paid 锁 | ☐ | — | 无 flag；并发 webhook 集成测试，无 Horizon 也应正常 |
| #8 Payment hide | ☐ | — | 仅在前端 PaymentPage 改造完成后开启 |
