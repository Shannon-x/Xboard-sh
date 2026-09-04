<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>提现申请未通过</title>
</head>
<body style="font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; font-size: 14px; line-height: 1.6em; background-color: #f6f6f6; margin: 0; padding: 20px;" bgcolor="#f6f6f6">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 0 auto; background-color: #fff; border: 1px solid #e9e9e9; border-radius: 3px;">
        <tr>
            <td align="center" style="font-size: 22px; font-weight: bold; color: #fff; background-color: #b7791f; padding: 20px; border-radius: 3px 3px 0 0;">提现申请未通过</td>
        </tr>
        <tr>
            <td style="padding: 24px 28px; color: #4a4a4a; font-size: 15px;">
                <p style="margin: 0 0 12px; font-size: 22px; color: #111;">Dear Customer</p>
                <p style="margin: 0 0 12px;">很抱歉，您的提现申请 <strong>#{{ $withdrawal_id }}</strong>（{{ $amount }} → {{ $chain }}）未能通过审核：</p>
                <div style="margin: 0 0 16px; padding: 12px 14px; background: #fffaf0; border-left: 3px solid #b7791f; color: #7b4f0a;">{{ $reason }}</div>
                <p style="margin: 0 0 16px;">冻结的佣金 <strong>{{ $amount }}</strong> 已退回您的账户，您可以修改收款信息后重新申请。</p>
                <p style="margin: 0 0 20px; font-size: 12px; color: #757575;">如有疑问，请回复站内工单联系我们。(本邮件由系统自动发出，请勿直接回复)</p>
                <p style="margin: 0; text-align: center;">
                    <a href="{{$url}}" style="color: #fff; text-decoration: none; font-weight: bold; display: inline-block; border-radius: 5px; background-color: #b7791f; padding: 8px 20px;">登录 {{$name}}</a>
                </p>
            </td>
        </tr>
    </table>
    <p style="text-align: center; font-size: 12px; color: #999; margin: 20px 0 0;">&copy; {{$name}}. All Rights Reserved.</p>
</body>
</html>
