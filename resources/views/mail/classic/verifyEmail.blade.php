@php
/* 三套模板共用的文案：主题块 / 正文 / 说明 */
$title = $changing ? '确认新邮箱' : ($reminder ? '提醒：请验证您的邮箱' : '请验证您的邮箱');
if ($changing) {
    $intro = '您在 ' . $name . ' 申请将账户邮箱换成 ' . $email . '。点击下方按钮确认，确认后新邮箱立即生效，之前的邮箱不再收到任何通知。';
} elseif ($reminder) {
    $intro = '您在 ' . $name . ' 的账户邮箱还没有验证，宽限期将于 ' . $due_date . ' 结束。点一下下方按钮即可完成，之后收据、续费账单和服务通知都会发到这个邮箱。';
} else {
    $intro = '感谢使用 ' . $name . '。为了确保收据、续费账单和服务通知能送达，请点击下方按钮验证这个邮箱（' . $email . '）。';
}
if ($changing) {
    $consequence = null;
} elseif ($restrict_mode === 'subscribe') {
    $consequence = '如果在 ' . $due_date . ' 之前没有验证，账户将进入限制状态：订阅链接暂停更新，提交工单（支付问题除外）、佣金提现和佣金转余额也需要先验证邮箱。购买和续费不受影响。';
} elseif ($restrict_mode === 'features') {
    $consequence = '如果在 ' . $due_date . ' 之前没有验证，账户将进入限制状态：提交工单（支付问题除外）、佣金提现和佣金转余额前需要先验证邮箱。订阅、购买和续费都不受影响。';
} else {
    $consequence = '验证之后面板里的提示就会消失。';
}
$note = '链接 ' . $token_days . ' 天内有效、只能使用一次；邮件里的按钮打不开时，登录面板后可以重新发送。如果这不是您的操作，忽略这封邮件即可，账户不会有任何变化。';
@endphp
<!DOCTYPE html>
<html lang="zh-CN">

<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style type="text/css">
        img {
            max-width: 100%;
        }

        body {
            -webkit-font-smoothing: antialiased;
            -webkit-text-size-adjust: none;
            width: 100% !important;
            height: 100%;
            line-height: 1.6em;
        }

        body {
            background-color: #f6f6f6;
        }

        @media only screen and (max-width: 640px) {
            body {
                padding: 0 !important;
            }

            h1 {
                font-weight: 800 !important;
                margin: 20px 0 5px !important;
            }

            h2 {
                font-weight: 800 !important;
                margin: 20px 0 5px !important;
            }

            h3 {
                font-weight: 800 !important;
                margin: 20px 0 5px !important;
            }

            h4 {
                font-weight: 800 !important;
                margin: 20px 0 5px !important;
            }

            h1 {
                font-size: 22px !important;
            }

            h2 {
                font-size: 18px !important;
            }

            h3 {
                font-size: 16px !important;
            }

            .container {
                padding: 0 !important;
                width: 100% !important;
            }

            .content {
                padding: 0 !important;
            }

            .content-wrap {
                padding: 10px !important;
            }

            .invoice {
                width: 100% !important;
            }
        }
    </style>
</head>

<body itemscope itemtype="http://schema.org/EmailMessage"
    style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; box-sizing: border-box; font-size: 14px; -webkit-font-smoothing: antialiased; -webkit-text-size-adjust: none; width: 100% !important; height: 100%; line-height: 1.6em; background-color: #f6f6f6; margin: 0;"
    bgcolor="#f6f6f6">
    <table class="body-wrap"
        style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; box-sizing: border-box; font-size: 14px; width: 100%; background-color: #f6f6f6; margin: 0;"
        bgcolor="#f6f6f6">
        <tr
            style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; box-sizing: border-box; font-size: 14px; margin: 0;">
            <td style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; box-sizing: border-box; font-size: 14px; vertical-align: top; margin: 0;"
                valign="top">
            </td>
            <td class="container" width="600"
                style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; box-sizing: border-box; font-size: 14px; vertical-align: top; display: block !important; max-width: 600px !important; clear: both !important; margin: 0 auto;"
                valign="top">
                <div class="content"
                    style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; box-sizing: border-box; font-size: 14px; max-width: 600px; display: block; margin: 0 auto; padding: 20px;">
                    <table class="main" width="100%" cellpadding="0" cellspacing="0"
                        style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; box-sizing: border-box; font-size: 14px; border-radius: 3px; background-color: #fff; margin: 0; border: 1px solid #e9e9e9;"
                        bgcolor="#fff">
                        <tr
                            style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; box-sizing: border-box; font-size: 14px; margin: 0;">
                            <td class="alert alert-warning"
                                style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; box-sizing: border-box; font-size: 22px; font-weight: bold; vertical-align: top; color: #fff; font-weight: 500; text-align: center; border-radius: 3px 3px 0 0; background-color: #0073ba; margin: 0; padding: 20px;"
                                align="center" bgcolor="#0073ba" valign="top">
                                {{$name}}
                            </td>
                        </tr>
                        <tr
                            style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; box-sizing: border-box; font-size: 14px; margin: 0;">
                            <td class="content-wrap"
                                style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; box-sizing: border-box; font-size: 14px; vertical-align: top; margin: 0; padding: 20px;"
                                valign="top">
                                <table width="100%" cellpadding="0" cellspacing="0"
                                    style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; box-sizing: border-box; font-size: 14px; margin: 0;">
                                    <tr
                                        style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; box-sizing: border-box; font-size: 14px; margin: 0;">
                                        <td class="content-block"
                                            style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; box-sizing: border-box; font-size: 34px; vertical-align: top; line-height: 1em; margin: 0; padding: 20px 0 30px;"
                                            valign="top">
                                            Dear Customer
                                        </td>
                                    </tr>
                                    <tr
                                        style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; box-sizing: border-box; font-size: 14px; margin: 0;">
                                        <td class="content-block"
                                            style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; box-sizing: border-box; font-size: 16px; color: #4a4a4a; vertical-align: top; margin: 0; padding: 0 0 20px;"
                                            valign="top">
                                            {{ $intro }}@if ($consequence)<br><br>{{ $consequence }}@endif<br><br><span style="color:#999">{{ $note }}</span>
                                        </td>
                                    </tr>
                                    <tr
                                        style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; box-sizing: border-box; font-size: 14px; margin: 0;">
                                        <td class="content-block"
                                            style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; box-sizing: border-box; font-size: 36px; font-weight: bold; text-align: center; color: #4a4a4a; vertical-align: top; line-height: 1.6em; margin: 0; padding: 0 0 20px;"
                                            valign="top">
                                            <a href="{{$link}}">{{$link}}</a>
                                        </td>
                                    </tr>
                                    <tr
                                        style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; box-sizing: border-box; font-size: 14px; margin: 0;">
                                        <td class="content-block"
                                            style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; box-sizing: border-box; font-size: 12px; color: #757575; vertical-align: top; margin: 0; padding: 0 0 20px;"
                                            valign="top">
                                            (本邮件由系统自动发出，请勿直接回复)
                                        </td>
                                    </tr>
                                    <tr
                                        style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; box-sizing: border-box; font-size: 14px; margin: 0;">
                                        <td class="content-block"
                                            style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; box-sizing: border-box; font-size: 14px; text-align: center; vertical-align: top; margin: 0; padding: 0 0 20px;"
                                            valign="top">
                                            <a href="{{$url}}"
                                                class="btn-primary"
                                                style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; box-sizing: border-box; font-size: 14px; color: #fff; text-decoration: none; line-height: 2em; font-weight: bold; text-align: center; cursor: pointer; display: inline-block; border-radius: 5px; text-transform: capitalize; background-color: #0073ba; margin: 0; border-color: #0073ba; border-style: solid; border-width: 8px 20px;">{{ $changing ? '确认新邮箱' : '验证邮箱' }}</a>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    </table>
                    <div class="footer"
                        style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; box-sizing: border-box; font-size: 14px; width: 100%; clear: both; color: #999; margin: 0; padding: 20px;">
                        <table width="100%"
                            style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; box-sizing: border-box; font-size: 14px; margin: 0;">
                            <tr
                                style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; box-sizing: border-box; font-size: 14px; margin: 0;">
                                <td class="aligncenter content-block"
                                    style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; box-sizing: border-box; font-size: 12px; vertical-align: top; color: #999; text-align: center; margin: 0; padding: 0;"
                                    align="center" valign="top">
                                    &copy; {{$name}}. All Rights Reserved.
                                </td>
                            </tr>
                            <tr
                                style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; box-sizing: border-box; font-size: 14px; margin: 0;">
                                <td class="aligncenter content-block"
                                    style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; box-sizing: border-box; font-size: 12px; vertical-align: top; color: #999; text-align: center; margin: 0; padding: 0 0 20px;"
                                    align="center" valign="top">
                                    <a href="{{$url}}/#/subscribe"
                                        style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; box-sizing: border-box; font-size: 12px; color: #999; text-decoration: none; margin: 0;">我的订阅</a> |
                                    <a href="{{$url}}/#/knowledge"
                                        style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; box-sizing: border-box; font-size: 12px; color: #999; text-decoration: none; margin: 0;">使用教程</a>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </td>
            <td style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; box-sizing: border-box; font-size: 14px; vertical-align: top; margin: 0;"
                valign="top">
            </td>
        </tr>
    </table>
</body>

</html>
