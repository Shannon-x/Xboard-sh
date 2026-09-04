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
| `commission_withdraw_usdt_rate` | 参考汇率(1 USDT = ? 元),用于给用户与管理员显示预估 USDT | 7.2 |
| `commission_withdraw_require_qrcode` | 是否强制用户上传收款二维码 | 关 |
| `commission_withdraw_thanks` | 结算成功邮件 / 工单回复里的感谢语(支持多行) | 内置文案 |
| `commission_withdraw_method` | 旧版前端的提现方式列表(仅供旧主题使用) | — |

每条链包含:`code`(slug,自动生成)、`name`(如 `USDT`)、`network`(如 `TRC20`)、`preset`(地址校验规则:
`tron` / `evm` / `solana` / `ton` / `none`)、`explorer_tx`(区块浏览器交易模板,`{txid}` 占位,如
`https://tronscan.org/#/transaction/{txid}`)。管理端编辑器提供「一键加入 TRC20 / BEP20 / ERC20」与上下移动。

配置的解析、校验与默认值集中在 `App\Services\Commission\WithdrawalConfig`。

## 二、用户侧流程(sufe-my-theme)

1. 邀请页点「提现」→ 弹窗显示可提现余额、金额输入(默认填满,可点「全部」)、链选择(管理员配置的列表)、地址输入
   (按链的 preset 做正则校验并给出示例提示)、可选的收款二维码(粘贴 / 拖拽 / 选择图片,复用工单附件上传通道)。
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
- **结算**:管理员在链上打款后,填入 TxID(可选)、实付 USDT(默认按汇率预填)、备注 → 一键完成:
  记录 `txid / paid_usdt / settled_at / admin_id`,在工单里以管理员身份回复(含 TxID 与浏览器链接)并关闭工单,
  发送「提现已完成」专用邮件。佣金在申请时已冻结,结算**不再改余额**。
- **驳回**:填写原因 → 原额退回佣金余额、工单回复原因并关闭、发送「提现被驳回」邮件。

## 四、邮件模板

三套主题(`default` / `classic` / `editorial`)各新增两套专用模板:

- `resources/views/mail/<theme>/withdrawCompleted.blade.php`
- `resources/views/mail/<theme>/withdrawRejected.blade.php`

可用变量:`name`、`url`、`withdrawal_id`、`amount`(带币种符号)、`usdt`、`usdt_is_actual`(实付还是预估)、
`chain`、`address`、`txid`、`explorer_url`、`reason`、`thanks`、`settled_at`。自定义主题目录若没有这两个文件,
自动回退到通用的 `notify` 模板。

## 五、接口

用户端(`/api/v1/user/withdraw/*`,均需登录):

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | `config` | 公共配置 + 当前佣金余额 + 是否有待处理申请 + 已保存的收款信息 |
| GET | `fetch` | 我的提现记录(倒序) |
| POST | `apply` | `amount`(分)、`chain`、`address`、`attachment_ids[]`(可选)、`reuse_qr`(沿用已保存二维码);限流 5 次/分钟 |
| POST | `cancel` | 取消自己的待处理申请 |
| POST | `saved/clear` | 清除已保存的收款信息 |

管理端(`/api/v2/<secure_path>/withdraw/*`):`fetch`、`stats`、`detail`、`settle`、`reject`。

数据表:`v2_commission_withdrawal`(状态 0 待处理 / 1 已完成 / 2 已驳回 / 3 已取消)、`v2_user_payout_profile`。

插件钩子:`commission.withdraw.apply.after`、`commission.withdraw.settle.after`、`commission.withdraw.close.after`(驳回 / 取消)
(参数均为 `CommissionWithdrawal` 模型);申请创建的工单照常触发 `ticket.create.after`,因此 Telegram 等插件会
收到带二维码的新工单提醒。

## 六、隐身中间件(sufe-middleware-rs)

上述五个用户端路径已登记进 `src/pathname.rs` 的 `XB_EXTRA_PATHS`;二维码图片沿用工单附件的前缀直通通道,
无需额外配置。升级中间件后请重新构建部署,否则新接口会被当作未知路径拒绝。

## 七、升级步骤

1. 部署 Xboard 后执行 `php artisan migrate`。
2. 后台「系统设置 → 邀请佣金」检查链列表、汇率、最低 / 最高额度、感谢语。
3. 重新构建并部署 sufe-middleware-rs、sufe-my-theme、XBoard-admin(管理端资源由 CI 自动同步到 Xboard 的 `public/assets/admin`)。
