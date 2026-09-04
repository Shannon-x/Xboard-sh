<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <!--[if !mso]><!-->
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+SC:wght@400;500&family=Noto+Serif+SC:wght@400;600;700&display=swap" rel="stylesheet" />
    <!--<![endif]-->
    <title>提现已完成 - {{ $name ?? 'XBoard' }}</title>
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
                            <div style="font-family:'Noto Serif SC',Georgia,'Songti SC',serif;font-size:26px;font-weight:700;color:#2a2520;line-height:1.4;letter-spacing:0.5px;">佣金已打款</div>
                            <div style="margin-top:8px;font-size:14px;color:#716a65;">提现申请 #{{ $withdrawal_id }} · {{ $settled_at }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 44px 0 44px;">
                            <div style="border-top:1px solid #e5e0d8;"></div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 44px 0 44px;">
                            <div style="font-family:'Noto Serif SC',Georgia,serif;font-size:34px;font-weight:700;color:#2a2520;letter-spacing:0.5px;">{{ $amount }}</div>
                            @if(!empty($usdt))
                            <div style="margin-top:4px;font-size:14px;color:#716a65;">{{ $usdt_is_actual ? '实付' : '约' }} {{ $usdt }} USDT</div>
                            @endif
                            <table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin-top:20px;font-size:14px;line-height:1.7;">
                                <tr><td style="padding:6px 0;color:#a09890;width:88px;">收款链</td><td style="padding:6px 0;color:#2a2520;">{{ $chain }}</td></tr>
                                <tr><td style="padding:6px 0;color:#a09890;vertical-align:top;">收款地址</td><td style="padding:6px 0;color:#2a2520;font-family:Menlo,Consolas,monospace;font-size:12px;word-break:break-all;">{{ $address }}</td></tr>
                                @if(!empty($txid))
                                <tr><td style="padding:6px 0;color:#a09890;vertical-align:top;">交易哈希</td><td style="padding:6px 0;color:#2a2520;font-family:Menlo,Consolas,monospace;font-size:12px;word-break:break-all;">{{ $txid }}</td></tr>
                                @endif
                            </table>
                            <div style="margin-top:20px;font-size:15px;color:#2a2520;line-height:1.8;">{!! nl2br(e($thanks)) !!}</div>
                            <div style="margin-top:12px;font-size:12px;color:#a09890;line-height:1.7;">链上到账通常需要几分钟到一小时；若长时间未到账，请回复站内工单联系我们。</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px 44px 44px 44px;">
                            <table border="0" cellspacing="0" cellpadding="0">
                                <tr>
                                    @if(!empty($explorer_url))
                                    <td style="border-radius:4px;background-color:#c94f2e;">
                                        <a href="{{ $explorer_url }}" target="_blank" style="font-family:'Noto Sans SC',sans-serif;display:inline-block;padding:11px 28px;font-size:14px;font-weight:500;color:#f8f7f3;text-decoration:none;letter-spacing:0.5px;">在区块浏览器查看</a>
                                    </td>
                                    <td style="width:12px;"></td>
                                    @endif
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
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
