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
    | warn   : 仅记录异常但不拒收，只用于发现升级兼容问题后的临时回滚
    | enforce: 默认。校验失败直接 return 'fail'，拒收 webhook
    | off    : 完全跳过 trade_status / 金额校验，仅保留签名校验（不推荐）
    |
    | 安全校验不能默认处于观察模式，否则低额支付仍会开通高价订单。
    */
    'payment_amount_check' => env('FEATURE_PAYMENT_AMOUNT_CHECK', 'enforce'),

    /*
    | 支付回调「网关绑定」校验模式。
    |
    | 回调按 URL 里的 uuid 选网关密钥验签，验签后只凭 trade_no 找单，不校验该订单
    | checkout 时绑定的 payment_id 是否就是本次回调网关。开启后会比对二者，防止用 A 网关
    | 的合法回调翻转一个走 B 网关创建的订单（trade_no 可枚举）。
    |
    | warn   : 不一致仅记 PaymentMetrics，仍照常开通；仅用于临时兼容。
    | enforce: 默认。不一致直接拒收回调。
    | off    : 完全跳过该校验。
    |
    | checkout 会锁定订单并禁止切换已经绑定的支付配置；需要换通道时取消后重建订单。
    */
    'payment_gateway_bind' => env('FEATURE_PAYMENT_GATEWAY_BIND', 'enforce'),

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
