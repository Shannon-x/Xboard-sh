<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <!--[if !mso]><!-->
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+SC:wght@400;500&family=Noto+Serif+SC:wght@400;600;700&display=swap" rel="stylesheet" />
    <!--<![endif]-->
    <title>验证码 - {{ $name ?? 'XBoard' }}</title>
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
                            <div style="font-family:'Noto Serif SC',Georgia,'Songti SC',serif;font-size:26px;font-weight:700;color:#2a2520;line-height:1.4;letter-spacing:0.5px;">邮箱验证码</div>
                        </td>
                    </tr>
                    <!-- Body -->
                    <tr>
                        <td style="padding:14px 44px 0 44px;">
                            <div style="font-family:'Noto Sans SC','PingFang SC','Microsoft YaHei',sans-serif;font-size:15px;color:#69635e;line-height:1.8;">请使用以下验证码完成操作，有效期 5 分钟。</div>
                        </td>
                    </tr>
                    <!-- Code -->
                    <tr>
                        <td style="padding:28px 44px;">
                            <table width="100%" border="0" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td style="background-color:#f5f3ef;border-radius:4px;padding:24px;text-align:center;">
                                        <div style="font-family:'SF Mono',SFMono-Regular,ui-monospace,Menlo,Consolas,monospace;font-size:34px;letter-spacing:10px;color:#2a2520;font-weight:600;">{{ $code }}</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <!-- Note -->
                    <tr>
                        <td style="padding:0 44px 44px 44px;">
                            <div style="font-family:'Noto Sans SC','PingFang SC','Microsoft YaHei',sans-serif;font-size:13px;color:#a09890;line-height:1.75;">如果您没有请求此验证码，可以忽略这封邮件，您的账户不会受到影响。</div>
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
                            <div style="font-family:'Noto Sans SC',sans-serif;font-size:12px;color:#c5beb5;margin-top:2px;">
                                <span style="display:inline-block;width:0;max-width:0;overflow:hidden;color:#f5f3ef;font-size:1px;line-height:1px;mso-hide:all;">{{ substr(sha1(uniqid('', true)), 0, 12) }}</span>
                                不希望收到此类邮件？<a href="{{ $url ?? '#' }}#/profile" style="color:#a09890;text-decoration:underline;">管理通知偏好</a>
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
