# 佣金提现工作流(USDT)

> 2026-09 起,佣金提现从「用户手填地址 → 生成工单 → 管理员手改余额」升级为一条完整的结算工作流:
> 用户自选金额与链、提交地址(可附收款二维码)→ 申请时**冻结**佣金 → 管理员在「佣金提现」页一键结算 / 驳回
> → 系统自动回复工单、关闭工单、发送专用邮件。旧的 `POST /api/v1/user/ticket/withdraw` 仍可用,内部走同一条流水线。

## 一、管理员配置

后台「系统设置 → 邀请佣金」新增以下项(对应 `admin_setting` 键):

| 键 | 含义 | 默认 |
| --- | --- | --- |
| `withdraw_close_enable` | 关闭提现(沿用旧开关) | 关 |
| `commission_withdraw_limit` | 单次最低提现额(元) | 100 |
| `commission_withdraw_max` | 单次最高提现额(元,0/空 = 不限) | 不限 |
| `commission_withdraw_chains` | 可选交易链列表,见下 | TRC20 / BEP20 / ERC20 |
| `commission_withdraw_rate_source` | 汇率来源:`auto` 自动取实时行情 / `manual` 用下面的固定值 | auto |
| `commission_withdraw_usdt_rate` | 兜底汇率(1 USDT = ? 元)。仅在行情接口全挂或来源设为 manual 时使用;0 表示届时不显示估算 | 0 |
| `commission_withdraw_require_qrcode` | 是否强制用户上传收款二维码 | 关 |
| `commission_withdraw_thanks` | 结算成功邮件 / 工单回复里的感谢语(支持多行) | 内置文案 |
| `commission_withdraw_method` | 旧版前端的提现方式列表(仅供旧主题使用) | — |

### 链与网络目录

后台配一条链时**只需要选网络**,地址格式、区块浏览器链接、通道费会按该网络自动填好,不必逐项手填
(手填才是出错源头:TRC20 配上 EVM 正则,用户的地址就永远过不了校验)。填好之后仍可逐项覆盖。

网络目录 `WithdrawalConfig::NETWORKS` 覆盖 TRC20 / BEP20 / ERC20 / Polygon / Arbitrum / Optimism /
Base / Avalanche / Solana / TON / Aptos,外加 `custom`(全部手填)。每个条目给出:

| 字段 | 含义 |
| --- | --- |
| `label` | 网络展示名,如 `TRC20 (Tron)` |
| `preset` | 地址校验预设:`tron` / `evm` / `solana` / `ton` / `aptos` / `none` |
| `explorer_tx` | 区块浏览器交易模板,`{txid}` 占位。只放行 `http(s)://` |
| `fee` | 默认通道费(USDT),即链上转账成本,会从用户到账金额里扣 |

一条链最终存的字段:`code`、`name`、`network_key`、`network`、`preset`、`explorer_tx`、`fee`。
`code` 由「名称 + 网络 key」生成(`usdt_trc20`),**不跟随展示名**——它写进了每一条提现记录与用户保存的
收款信息,跟着展示名变就会对不上历史数据。老配置只存了 `network` 文本时,会按文本反查网络 key 并补齐
预设 / 通道费 / 浏览器链接,不需要人工迁移。

配置的解析、校验与默认值集中在 `App\Services\Commission\WithdrawalConfig`。

### USDT 汇率:自动获取

汇率由 `App\Services\Commission\UsdtRateService` 从公开行情接口获取,管理员不需要维护:

| 顺序(CNY) | 来源 | 说明 |
| --- | --- | --- |
| 1 | 币安 C2C 场外价 | 取前 10 条卖出广告报价的**中位数**,避开挂在最前面的异常单;这是用户实际能换到的价 |
| 2 | OKX `market/exchange-rate` | USD/CNY 参考汇率,USDT 锚定 1 USD |
| 3 | CoinGecko `simple/price` | 支持任意法币 |
| 4 | Coinbase `exchange-rates` | 兜底 |

非 CNY 站点的顺序是 CoinGecko → Coinbase → 币安 C2C。

- 缓存 10 分钟;`commission:refresh-usdt-rate` 定时任务每 10 分钟焐热一次,所以用户请求基本都命中缓存。
- 单个接口连接 2s / 总计 4s 超时,一轮取数总预算 6s,取完即止。
- 拿到的值要过**合理性校验**(CNY 必须落在 5–12 之间等),离谱值直接丢弃换下一家——接口改版返回错字段时,
  这一步能挡住把提现金额算爆。
- 全部失败时继续用最后一次成功值(最长 24 小时,标记为过期),并进入 120 秒冷却不再重试;
  连旧值都没有才回退到后台兜底汇率,兜底也没配就不显示估算。

后台设置页会显示当前实时汇率、来源与获取时间,带「刷新」按钮可现场验证服务器能否取到行情。

**服务器出不了网怎么办**:大陆机房访问上面四家基本不通。在 `.env` 里配
`USDT_RATE_PROXY=http://127.0.0.1:7890`(或 `socks5h://127.0.0.1:1080`)即可让取数走代理;
实在不想配代理,就把「汇率来源」改成手动并填一个兜底值。后台「刷新」按钮在取不到时会把失败原因一并显示
(缺 CA 证书 / 连接超时 / 返回值不在合理区间),不用去翻日志。

冷启动(全新部署、缓存被清)时只会有一个进程去抓行情,其余请求直接回落到兜底值——
不能让一次冷启动变成所有人排队等行情接口。

### 通道费

每条链有一个以 USDT 计的通道费(链上转账成本),默认值随网络带出,可改。用户看到的是三段式:

```
折算      14.9925 USDT      (1 USDT ≈ ¥6.67)
通道费    - 1.0000 USDT
实际到账  13.9925 USDT
```

并附一句「最终到账以打款时的实时汇率与通道费为准」。工单回复与邮件里也会写明「已扣通道费 x USDT」。

## 二、用户侧流程(sufe-my-theme)

1. 邀请页点「提现」→ 弹窗显示可提现余额、金额输入(默认填满,可点「全部」)、链选择(管理员配置的列表)、地址输入
   (按链的 preset 做正则校验并给出示例提示)、可选的收款二维码(粘贴 / 拖拽 / 选择图片,复用工单附件上传通道),
   以及按实时汇率折算的「折算 / 通道费 / 实际到账」三段报价。
2. **收款信息记忆**:提交成功后,链 + 地址(+ 二维码)保存到 `v2_user_payout_profile`。下次打开弹窗自动预填;
   二维码默认「沿用上次上传」(只有地址未变时才允许沿用,换了地址就必须重新上传),可点「换一张」;弹窗顶部可
   「清除已保存的收款信息」。
3. 同一用户同一时间只允许 **1 笔待处理** 申请;申请成功即从 `commission_balance` 扣减(冻结),驳回 / 取消原额退回。
4. 每笔申请自动生成一张系统工单(标题 `[提现申请] #id ¥x → 链`,二维码作为工单附件),用户可以在工单里补充说明。
5. 邀请页下方新增「提现记录」卡片:状态、金额、链、地址(可复制)、TxID(带区块浏览器链接)、对应工单入口,
   待处理的可自行取消。

## 三、管理员结算流程(XBoard-admin)

侧栏新增「佣金提现」页面(`/withdrawals`):

- 默认只看「待处理」,可按状态 / 邮箱 / 编号筛选;顶部显示待处理笔数与总额。
- 详情弹窗:用户、余额、金额、预估 USDT、链、地址(可复制)、二维码大图预览、风控参考
  (该地址历史打款次数、该用户历史打款次数)、对应工单快捷入口。
- **结算**:弹窗会按**打款那一刻**的实时汇率把这笔申请重算一遍(申请可能是几小时前提的,行情早变了),
  展示「折算 / 通道费 / 应打款」并预填实付 USDT,可点「刷新行情」。管理员在链上打款后填入 TxID(可选)、
  实付 USDT(留空则按实时折算自动记录)、备注 → 一键完成:记录
  `txid / paid_usdt / settle_rate / settled_at / admin_id`,在工单里以管理员身份回复(含 TxID 与浏览器链接)
  并关闭工单,发送「提现已完成」专用邮件。佣金在申请时已冻结,结算**不再改余额**。
- **驳回**:填写原因 → 原额退回佣金余额、工单回复原因并关闭、发送「提现被驳回」邮件。

## 四、邮件模板

三套主题(`default` / `classic` / `editorial`)各新增两套专用模板:

- `resources/views/mail/<theme>/withdrawCompleted.blade.php`
- `resources/views/mail/<theme>/withdrawRejected.blade.php`

可用变量:`name`、`url`、`withdrawal_id`、`amount`(带币种符号)、`usdt`、`usdt_is_actual`(实付还是预估)、
`usdt_fee`(通道费,无则为 null)、`usdt_rate`、`chain`、`address`、`txid`、`explorer_url`、`reason`、
`thanks`、`settled_at`。自定义主题目录若没有这两个文件,自动回退到通用的 `notify` 模板。

## 五、接口

用户端(`/api/v1/user/withdraw/*`,均需登录):

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | `config` | 公共配置 + 当前佣金余额 + 是否有待处理申请 + 已保存的收款信息 |
| GET | `fetch` | 我的提现记录(倒序) |
| POST | `apply` | `amount`(分)、`chain`、`address`、`attachment_ids[]`(可选)、`reuse_qr`(沿用已保存二维码);限流 5 次/分钟 |
| POST | `cancel` | 取消自己的待处理申请 |
| POST | `saved/clear` | 清除已保存的收款信息 |

`config` 的响应里带 `usdt_rate` / `usdt_rate_source` / `usdt_rate_source_label` / `usdt_rate_updated_at` /
`usdt_rate_is_live`,每条链带 `fee` 与 `fee_currency`。

管理端(`/api/v2/<secure_path>/withdraw/*`):`fetch`、`stats`、`detail`、`settle`、`reject`、
`rate`(当前实时汇率;带 `id` 时顺便按最新行情重算该笔申请,带 `force=1` 时跳过缓存)。

数据表:`v2_commission_withdrawal`(状态 0 待处理 / 1 已完成 / 2 已驳回 / 3 已取消)、`v2_user_payout_profile`。
金额相关列:`usdt_rate`(申请时汇率)、`usdt_fee`(申请时锁定的通道费)、`usdt_amount`(**已扣通道费**的预计到账)、
`paid_usdt`(实付)、`settle_rate`(打款时汇率)、`rate_source`(汇率来源)。
2026-09 之前的记录没有 `usdt_fee`,其 `usdt_amount` 即毛额,语义上等价于「通道费为 0」。

插件钩子:`commission.withdraw.apply.after`、`commission.withdraw.settle.after`、`commission.withdraw.close.after`(驳回 / 取消)
(参数均为 `CommissionWithdrawal` 模型);申请创建的工单照常触发 `ticket.create.after`,因此 Telegram 等插件会
收到带二维码的新工单提醒。

## 六、隐身中间件(sufe-middleware-rs)

上述五个用户端路径已登记进 `src/pathname.rs` 的 `XB_EXTRA_PATHS`;二维码图片沿用工单附件的前缀直通通道,
无需额外配置。升级中间件后请重新构建部署,否则新接口会被当作未知路径拒绝。

汇率相关改动**不需要动中间件**:用户端没有新增路径(汇率随 `/withdraw/config` 一起下发),
管理端 `/api/v2/<secure_path>/...` 本来就不走混淆路径表。

## 七、升级步骤

1. 部署 Xboard 后执行 `php artisan migrate`(会补 `usdt_fee` / `settle_rate` / `rate_source` 三列)。
2. 后台「系统设置 → 邀请佣金」检查链列表与通道费、最低 / 最高额度、感谢语;
   汇率默认自动获取,点一下「刷新」确认服务器能连到行情接口即可。若服务器出不了网,把「汇率来源」改成
   手动并填一个兜底值。
3. 确认计划任务在跑(`php artisan schedule:run` 的 crontab),`commission:refresh-usdt-rate` 每 10 分钟
   刷新一次汇率缓存;手动验证可执行 `php artisan commission:refresh-usdt-rate --force`。
4. 重新构建并部署 sufe-my-theme、XBoard-admin(管理端资源由 CI 自动同步到 Xboard 的 `public/assets/admin`);
   中间件只有在新增用户端接口时才需要重新构建。
