<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_V2BOARD_REGION', 'us-east-1'),
    ],


    /*
    |--------------------------------------------------------------------------
    | USDT 实时汇率
    |--------------------------------------------------------------------------
    |
    | 大陆机房访问币安 / OKX / CoinGecko / Coinbase 基本不通，配上出站代理
    | （形如 http://127.0.0.1:7890 或 socks5h://127.0.0.1:1080）自动汇率才有意义。
    | 留空则直连。
    |
    */

    /*
    |--------------------------------------------------------------------------
    | 群管理机器人入群门槛
    |--------------------------------------------------------------------------
    |
    | sufegroup_bot 调 /api/v1/guest/telegram/gate/* 时带的共享密钥（请求头 X-Gate-Secret），
    | 与机器人 .env 的 XBOARD_GATE_SECRET 填同一个值，建议 openssl rand -hex 32。留空 = 关闭这两条接口。
    |
    */

    'telegram_group_gate' => [
        'secret' => env('TELEGRAM_GROUP_GATE_SECRET', ''),
    ],

    "usdt_rate" => [
        "proxy" => env("USDT_RATE_PROXY"),
    ],

];
