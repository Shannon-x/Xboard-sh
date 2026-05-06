<?php

/*
|--------------------------------------------------------------------------
| Feature Flags — 仅用于"会改变外部行为/可见输出的修复"
|--------------------------------------------------------------------------
|
| 这里只放真正需要灰度或破坏 UX 的开关。纯粹的 bug 修复（行为对合法用户
| 完全等价的安全加固）已直接写死在代码里，不在此文件中。
|
| ⚠️  升级镜像后无需 .env 任何额外配置即可获得本批所有修复的安全收益。
|     仅当你想进一步加固到「拒收」级别时，才需要打开下面的 enforce flag。
|
*/

return [

    /*
    | EPay webhook 金额/状态校验模式。
    |
    | warn   : 默认。校验签名 + trade_status + 金额；任意校验失败仅记录 Log::warning
    |          与 PaymentMetrics（不拒收，便于观察真实流量与诡异回调）
    | enforce: 校验失败直接 return 'fail'，拒收 webhook
    | off    : 完全跳过 trade_status / 金额校验，仅保留签名校验（不推荐）
    |
    | 推荐路径：保留默认 warn 至少 24-48h，确认 PaymentMetrics
    | webhook.amount_mismatch 与 webhook.trade_status_invalid 计数为 0 或全部可解释
    | 后再切 enforce。回滚直接改回 warn 即可。
    */
    'payment_amount_check' => env('FEATURE_PAYMENT_AMOUNT_CHECK', 'warn'),

    /*
    | Admin 端 Payment 列表是否隐藏 config 中的 secret 字段。
    |
    | false: PaymentController::fetch 返回完整 config（旧行为，前端依赖）
    | true : 列表端点不返回 config，详情端点 /payment/detail 对 secret 字段做掩码
    |
    | ⚠️ 切换前必须同步 admin 前端：列表展示改用 is_configured 布尔字段，
    |    编辑时走单独的 detail 端点。否则编辑页面会一片空白。
    */
    'payment_secret_hide' => env('FEATURE_PAYMENT_SECRET_HIDE', false),

];
