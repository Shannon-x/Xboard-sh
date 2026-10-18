<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>{{ $heading ?? '佣金入账' }}</title>
</head>
<body style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; font-size: 14px; line-height: 1.6em; background-color: #f6f6f6; margin: 0; padding: 20px;" bgcolor="#f6f6f6">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 0 auto; background-color: #fff; border: 1px solid #e9e9e9; border-radius: 3px;">
        <tr>
            <td align="center" style="font-size: 22px; font-weight: bold; color: #fff; background-color: #2f855a; padding: 20px; border-radius: 3px 3px 0 0;">{{ $heading ?? '佣金入账' }}</td>
        </tr>
        <tr>
            <td style="padding: 24px 28px; color: #4a4a4a; font-size: 15px;">
                <p style="margin: 0 0 12px; font-size: 22px; color: #111;">Dear Customer</p>
                <p style="margin: 0 0 16px;">{{ $summary ?? ('本轮共 ' . $count . ' 笔佣金入账，合计 ' . $total . '，已计入你的佣金余额') }}。@if(!empty($balance))当前可用佣金 {{ $balance }}。@endif</p>
                <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse: collapse; font-size: 14px; margin-bottom: 16px;">
                    <tr>
                        <td style="padding: 8px 10px; color: #757575; border-bottom: 1px solid #eee;">被邀请人</td>
                        <td style="padding: 8px 10px; color: #757575; border-bottom: 1px solid #eee; text-align: right;">金额</td>
                        <td style="padding: 8px 10px; color: #757575; border-bottom: 1px solid #eee; text-align: right;">时间</td>
                    </tr>
                    @foreach($items as $item)
                    <tr>
                        <td style="padding: 8px 10px; border-bottom: 1px solid #eee;">{{ $item['email'] }}@if(($item['level'] ?? 1) > 1) <span style="color: #757575; font-size: 12px;">· 第 {{ $item['level'] }} 级</span>@endif</td>
                        <td style="padding: 8px 10px; border-bottom: 1px solid #eee; text-align: right; white-space: nowrap;"><strong>{{ $item['amount'] }}</strong></td>
                        <td style="padding: 8px 10px; border-bottom: 1px solid #eee; text-align: right; white-space: nowrap; color: #757575; font-size: 13px;">{{ $item['time'] }}</td>
                    </tr>
                    @endforeach
                    @if(!empty($more))
                    <tr>
                        <td colspan="3" style="padding: 8px 10px; color: #757575; font-size: 13px;">{{ $more }}</td>
                    </tr>
                    @endif
                </table>
                <p style="margin: 0 0 20px; font-size: 12px; color: #757575;">佣金可在邀请页提现或划转到账户余额。(本邮件由系统自动发出，请勿直接回复)</p>
                <p style="margin: 0; text-align: center;">
                    <a href="{{ $invite_url ?? $url }}" style="color: #fff; text-decoration: none; font-weight: bold; display: inline-block; border-radius: 5px; background-color: #2f855a; padding: 8px 20px;">查看佣金</a>
                </p>
            </td>
        </tr>
    </table>
    <p style="text-align: center; font-size: 12px; color: #999; margin: 20px 0 0;">&copy; {{$name}}. All Rights Reserved.</p>
    <p style="text-align: center; font-size: 12px; color: #999; margin: 6px 0 0;">不希望收到此类邮件？<a href="{{ $manage_url ?? ($url . '#/profile') }}" style="color: #999; text-decoration: underline;">{{ $manage_label ?? '管理通知偏好' }}</a></p>
</body>
</html>
