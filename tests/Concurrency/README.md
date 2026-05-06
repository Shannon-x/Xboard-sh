# Concurrency Tests

针对**并发竞态修复**的回归测试，区别于普通 Feature 测试：必须用真实的 MySQL（或同支持
`SELECT ... FOR UPDATE` 的数据库），不能用 SQLite，因此独立成 testsuite。

## 运行

```bash
DB_CONNECTION=mysql DB_DATABASE=xboard_test \
    vendor/bin/phpunit --testsuite=Concurrency
```

## 计划中的测试

按 Phase 1 修复推进顺序排列：

1. **`PaidConcurrencyTest`** — 100 并发 webhook 打同一 trade_no，断言：
   - `Order::where('trade_no')` 只有一条 `STATUS_PROCESSING`
   - 用户 `transfer_enable` / `expired_at` 只增长一次
   - `OrderHandleJob` 在 `payment_lock_enforce=true` 时只触发一次 `open()`

2. **`CouponLimitUseTest`** — 多用户并发用最后一张 `limit_use=1` 优惠券，断言：
   - 只有一个事务成功
   - `coupon.limit_use` 最终为 0
   - 失败方收到 `coupon_exhausted` 异常

3. **`GiftCardRedeemTest`** — 并发兑换同一 code，断言：
   - 只有一次 `markAsUsed`
   - `usage_count` 不超过 `max_usage`

4. **`EpayWebhookValidationTest`** — 构造异常 webhook 验证：
   - `trade_status=WAIT_BUYER_PAY` → enforce 模式拒收
   - 金额不匹配 → enforce 模式拒收
   - 篡改签名 → 任何模式都拒收

## 实现约束

- 用 `Process::run` 起子进程并发，**不要** 用 `Promise::all` 单进程模拟（行锁竞争场景需要真实并发请求）
- 每个 case 在 `setUp` 重置 metrics：`Redis::del(Redis::keys('payment_metrics:*'))`
- `tearDown` 必须清理测试订单/优惠券，避免 case 间污染

## 运行频率

- CI：每次 PR 必跑（要求测试 DB 已配）
- 本地：仅在改 OrderService / CouponService / GiftCardService / Epay 时跑
