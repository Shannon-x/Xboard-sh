<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <!--[if !mso]><!-->
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+SC:wght@400;500&family=Noto+Serif+SC:wght@400;600;700&display=swap" rel="stylesheet" />
    <!--<![endif]-->
    <title>快捷登录 - {{ $name ?? 'XBoard' }}</title>
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
                            <div style="font-family:'Noto Serif SC',Georgia,'Songti SC',serif;font-size:26px;font-weight:700;color:#2a2520;line-height:1.4;letter-spacing:0.5px;">快捷登录</div>
                        </td>
                    </tr>
                    <!-- Body -->
                    <tr>
                        <td style="padding:14px 44px 0 44px;">
                            <div style="font-family:'Noto Sans SC','PingFang SC','Microsoft YaHei',sans-serif;font-size:15px;color:#69635e;line-height:1.8;">您正在登录 {{ $name ?? 'XBoard' }}。点击下方按钮完成登录，链接将在 5 分钟后失效。</div>
                        </td>
                    </tr>
                    <!-- CTA -->
                    <tr>
                        <td style="padding:28px 44px 0 44px;">
                            <table border="0" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td style="border-radius:4px;background-color:#c94f2e;">
                                        <a href="{{ $link }}" target="_blank" style="font-family:'Noto Sans SC',sans-serif;display:inline-block;padding:11px 28px;font-size:14px;font-weight:500;color:#f8f7f3;text-decoration:none;letter-spacing:0.5px;">登录到 {{ $name ?? 'XBoard' }}</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
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
                            <div style="font-family:'Noto Sans SC',sans-serif;font-size:13px;color:#a09890;line-height:1.75;">如果您没有发起此登录请求，可以忽略这封邮件。</div>
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
