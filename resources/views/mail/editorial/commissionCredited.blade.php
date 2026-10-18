<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <!--[if !mso]><!-->
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+SC:wght@400;500&family=Noto+Serif+SC:wght@400;600;700&display=swap" rel="stylesheet" />
    <!--<![endif]-->
    <title>{{ $heading ?? '佣金入账' }} - {{ $name ?? 'XBoard' }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f5f3ef;font-family:'Noto Sans SC','PingFang SC','Hiragino Sans GB','Microsoft YaHei',sans-serif;-webkit-font-smoothing:antialiased;">
    <table width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color:#f5f3ef;">
        <tr>
            <td align="center" style="padding:56px 16px;">
                <table width="560" border="0" cellspacing="0" cellpadding="0" style="width:100%;max-width:560px;background-color:#f8f7f3;border:1px solid #e5e0d8;border-radius:6px;">
                    <tr>
                        <td style="padding:40px 44px 0 44px;">
                            <div style="font-family:'Noto Serif SC',Georgia,'Songti SC',serif;font-size:18px;font-weight:600;color:#716a65;letter-spacing:1.5px;">{{ $name ?? 'XBoard' }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px 44px 0 44px;">
                            <div style="font-family:'Noto Serif SC',Georgia,'Songti SC',serif;font-size:26px;font-weight:700;color:#2a2520;line-height:1.4;letter-spacing:0.5px;">{{ $heading ?? '佣金入账' }}</div>
                            <div style="margin-top:8px;font-size:14px;color:#716a65;">{{ $subtitle ?? ('本轮共 ' . $count . ' 笔，已计入你的佣金余额') }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 44px 0 44px;">
                            <div style="border-top:1px solid #e5e0d8;"></div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 44px 0 44px;">
                            <div style="font-family:'Noto Serif SC',Georgia,serif;font-size:34px;font-weight:700;color:#2a2520;letter-spacing:0.5px;">{{ $total }}</div>
                            @if(!empty($balance))
                            <div style="margin-top:4px;font-size:14px;color:#716a65;">当前可用佣金 {{ $balance }}</div>
                            @endif
                            <table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin-top:20px;font-size:14px;line-height:1.7;border-collapse:collapse;">
                                <tr>
                                    <td style="padding:6px 0;color:#a09890;border-bottom:1px solid #e5e0d8;">被邀请人</td>
                                    <td style="padding:6px 0;color:#a09890;border-bottom:1px solid #e5e0d8;text-align:right;">金额</td>
                                    <td style="padding:6px 0 6px 16px;color:#a09890;border-bottom:1px solid #e5e0d8;text-align:right;white-space:nowrap;">时间</td>
                                </tr>
                                @foreach($items as $item)
                                <tr>
                                    <td style="padding:8px 0;color:#2a2520;border-bottom:1px solid #efebe5;">{{ $item['email'] }}@if(($item['level'] ?? 1) > 1) <span style="font-size:12px;color:#a09890;">· 第 {{ $item['level'] }} 级</span>@endif</td>
                                    <td style="padding:8px 0;color:#2a2520;border-bottom:1px solid #efebe5;text-align:right;white-space:nowrap;">{{ $item['amount'] }}</td>
                                    <td style="padding:8px 0 8px 16px;color:#716a65;border-bottom:1px solid #efebe5;text-align:right;white-space:nowrap;font-size:13px;">{{ $item['time'] }}</td>
                                </tr>
                                @endforeach
                                @if(!empty($more))
                                <tr>
                                    <td colspan="3" style="padding:10px 0 0 0;color:#a09890;font-size:13px;">{{ $more }}</td>
                                </tr>
                                @endif
                            </table>
                            <div style="margin-top:16px;font-size:12px;color:#a09890;line-height:1.7;">佣金可在邀请页提现或划转到账户余额。</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px 44px 44px 44px;">
                            <table border="0" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td style="border-radius:4px;background-color:#c94f2e;">
                                        <a href="{{ $invite_url ?? ($url ?? '#') }}" target="_blank" style="font-family:'Noto Sans SC',sans-serif;display:inline-block;padding:11px 28px;font-size:14px;font-weight:500;color:#f8f7f3;text-decoration:none;letter-spacing:0.5px;">查看佣金</a>
                                    </td>
                                    <td style="width:12px;"></td>
                                    <td style="border-radius:4px;border:1px solid #c94f2e;">
                                        <a href="{{ $url ?? '#' }}" target="_blank" style="font-family:'Noto Sans SC',sans-serif;display:inline-block;padding:10px 26px;font-size:14px;font-weight:500;color:#c94f2e;text-decoration:none;letter-spacing:0.5px;">返回 {{ $name ?? 'XBoard' }}</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
                <table width="560" border="0" cellspacing="0" cellpadding="0" style="width:100%;max-width:560px;">
                    <tr>
                        <td style="padding:28px 0;text-align:center;">
                            <div style="font-family:'Noto Serif SC',Georgia,serif;font-size:13px;color:#a09890;line-height:2.0;">
                                <a href="{{ $url ?? '#' }}" style="color:#716a65;text-decoration:none;letter-spacing:1px;">{{ $name ?? 'XBoard' }}</a>
                            </div>
                            <div style="font-family:'Noto Sans SC',sans-serif;font-size:12px;color:#c5beb5;margin-top:2px;">
                                <span style="display:inline-block;width:0;max-width:0;overflow:hidden;color:#f5f3ef;font-size:1px;line-height:1px;mso-hide:all;">{{ substr(sha1(uniqid('', true)), 0, 12) }}</span>
                                不希望收到此类邮件？<a href="{{ $manage_url ?? (($url ?? '#') . '#/profile') }}" style="color:#a09890;text-decoration:underline;">{{ $manage_label ?? '管理通知偏好' }}</a>
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
