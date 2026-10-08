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
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <!--[if !mso]><!-->
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+SC:wght@400;500&family=Noto+Serif+SC:wght@400;600;700&display=swap" rel="stylesheet" />
    <!--<![endif]-->
    <title>{{ $title }} - {{ $name ?? 'XBoard' }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f5f3ef;font-family:'Noto Sans SC','PingFang SC','Hiragino Sans GB','Microsoft YaHei',sans-serif;-webkit-font-smoothing:antialiased;">
    <table width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color:#f5f3ef;">
        <tr>
            <td align="center" style="padding:56px 16px;">
                <table width="560" border="0" cellspacing="0" cellpadding="0" style="width:100%;max-width:560px;background-color:#f8f7f3;border:1px solid #e5e0d8;border-radius:6px;">
                    <!-- Brand -->
                    <tr>
                        <td style="padding:40px 44px 0 44px;">
                            <div style="font-family:'Noto Serif SC',Georgia,'Songti SC',serif;font-size:18px;font-weight:600;color:#716a65;letter-spacing:1.5px;">{{ $name ?? 'XBoard' }}</div>
                        </td>
                    </tr>
                    <!-- Title -->
                    <tr>
                        <td style="padding:32px 44px 0 44px;">
                            <div style="font-family:'Noto Serif SC',Georgia,'Songti SC',serif;font-size:26px;font-weight:700;color:#2a2520;line-height:1.4;letter-spacing:0.5px;">{{ $title }}</div>
                        </td>
                    </tr>
                    <!-- Body -->
                    <tr>
                        <td style="padding:14px 44px 0 44px;">
                            <div style="font-family:'Noto Sans SC','PingFang SC','Microsoft YaHei',sans-serif;font-size:15px;color:#69635e;line-height:1.8;">{{ $intro }}</div>
                        </td>
                    </tr>
                    <!-- CTA -->
                    <tr>
                        <td style="padding:28px 44px 0 44px;">
                            <table border="0" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td style="border-radius:4px;background-color:#c94f2e;">
                                        <a href="{{ $link }}" target="_blank" style="font-family:'Noto Sans SC',sans-serif;display:inline-block;padding:11px 28px;font-size:14px;font-weight:500;color:#f8f7f3;text-decoration:none;letter-spacing:0.5px;">{{ $changing ? '确认新邮箱' : '验证邮箱' }}</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    @if ($consequence)
                    <!-- Consequence -->
                    <tr>
                        <td style="padding:24px 44px 0 44px;">
                            <table width="100%" border="0" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td style="background-color:#f5f3ef;border-left:3px solid #d9b48f;border-radius:0 4px 4px 0;padding:14px 18px;">
                                        <div style="font-family:'Noto Sans SC',sans-serif;font-size:13px;color:#716a65;line-height:1.75;">{{ $consequence }}</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    @endif
                    <!-- Divider -->
                    <tr>
                        <td style="padding:28px 44px 0 44px;">
                            <div style="border-top:1px solid #e5e0d8;"></div>
                        </td>
                    </tr>
                    <!-- Fallback -->
                    <tr>
                        <td style="padding:20px 44px 0 44px;">
                            <div style="font-family:'Noto Sans SC',sans-serif;font-size:13px;color:#a09890;line-height:1.75;margin-bottom:10px;">如果按钮无法点击，请复制以下链接到浏览器：</div>
                            <div style="font-family:'SF Mono',SFMono-Regular,ui-monospace,Menlo,Consolas,monospace;font-size:12px;color:#716a65;word-break:break-all;background-color:#f5f3ef;border-radius:4px;padding:14px;line-height:1.6;">{{ $link }}</div>
                        </td>
                    </tr>
                    <!-- Note -->
                    <tr>
                        <td style="padding:20px 44px 44px 44px;">
                            <div style="font-family:'Noto Sans SC',sans-serif;font-size:13px;color:#a09890;line-height:1.75;">{{ $note }}</div>
                        </td>
                    </tr>
                </table>
                <!-- Footer -->
                <table width="560" border="0" cellspacing="0" cellpadding="0" style="width:100%;max-width:560px;">
                    <tr>
                        <td style="padding:28px 0;text-align:center;">
                            <div style="font-family:'Noto Serif SC',Georgia,serif;font-size:13px;color:#a09890;line-height:2.0;">
                                <span style="display:inline-block;width:0;max-width:0;overflow:hidden;color:#f5f3ef;font-size:1px;line-height:1px;mso-hide:all;">{{ substr(sha1(microtime(true)), 0, 12) }}</span>
                                <a href="{{ $url ?? '#' }}" style="color:#716a65;text-decoration:none;letter-spacing:1px;">{{ $name ?? 'XBoard' }}</a>
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
