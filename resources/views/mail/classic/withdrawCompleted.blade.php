<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>佣金提现已完成</title>
</head>
<body style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; font-size: 14px; line-height: 1.6em; background-color: #f6f6f6; margin: 0; padding: 20px;" bgcolor="#f6f6f6">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 0 auto; background-color: #fff; border: 1px solid #e9e9e9; border-radius: 3px;">
        <tr>
            <td align="center" style="font-size: 22px; font-weight: bold; color: #fff; background-color: #2f855a; padding: 20px; border-radius: 3px 3px 0 0;">佣金提现已完成</td>
        </tr>
        <tr>
            <td style="padding: 24px 28px; color: #4a4a4a; font-size: 15px;">
                <p style="margin: 0 0 12px; font-size: 22px; color: #111;">Dear Customer</p>
                <p style="margin: 0 0 16px;">您的提现申请 <strong>#{{ $withdrawal_id }}</strong> 已于 {{ $settled_at }} 完成打款，请注意查收。</p>
                <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse: collapse; font-size: 14px; margin-bottom: 16px;">
                    <tr><td style="padding: 8px 10px; color: #757575; width: 96px; border-bottom: 1px solid #eee;">金额</td><td style="padding: 8px 10px; border-bottom: 1px solid #eee;"><strong>{{ $amount }}</strong>@if(!empty($usdt))&nbsp;<span style="color: #757575;">（{{ $usdt_is_actual ? '实付' : '约' }} {{ $usdt }} USDT）</span>@endif</td></tr>
                    <tr><td style="padding: 8px 10px; color: #757575; border-bottom: 1px solid #eee;">收款链</td><td style="padding: 8px 10px; border-bottom: 1px solid #eee;">{{ $chain }}</td></tr>
                    <tr><td style="padding: 8px 10px; color: #757575; border-bottom: 1px solid #eee;">收款地址</td><td style="padding: 8px 10px; border-bottom: 1px solid #eee; font-family: Menlo, Consolas, monospace; font-size: 12px; word-break: break-all;">{{ $address }}</td></tr>
                    @if(!empty($txid))
                    <tr><td style="padding: 8px 10px; color: #757575; border-bottom: 1px solid #eee;">交易哈希</td><td style="padding: 8px 10px; border-bottom: 1px solid #eee; font-family: Menlo, Consolas, monospace; font-size: 12px; word-break: break-all;">{{ $txid }}@if(!empty($explorer_url))<br /><a href="{{ $explorer_url }}" style="color: #2f855a;">在区块浏览器查看 →</a>@endif</td></tr>
                    @endif
                </table>
                <p style="margin: 0 0 16px;">{!! nl2br(e($thanks)) !!}</p>
                <p style="margin: 0 0 20px; font-size: 12px; color: #757575;">链上到账通常需要几分钟到一小时；若长时间未到账，请回复站内工单联系我们。(本邮件由系统自动发出，请勿直接回复)</p>
                <p style="margin: 0; text-align: center;">
                    <a href="{{$url}}" style="color: #fff; text-decoration: none; font-weight: bold; display: inline-block; border-radius: 5px; background-color: #2f855a; padding: 8px 20px;">登录 {{$name}}</a>
                </p>
            </td>
        </tr>
    </table>
    <p style="text-align: center; font-size: 12px; color: #999; margin: 20px 0 0;">&copy; {{$name}}. All Rights Reserved.</p>
</body>
</html>
