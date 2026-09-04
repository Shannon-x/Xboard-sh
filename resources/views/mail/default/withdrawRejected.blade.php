<div style="background: #eee">
    <table width="600" border="0" align="center" cellpadding="0" cellspacing="0">
        <tbody>
        <tr>
            <td>
                <div style="background:#fff">
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <thead>
                        <tr>
                            <td valign="middle" style="padding-left:30px;background-color:#b7791f;color:#fff;padding:20px 40px;font-size: 21px;">{{$name}}</td>
                        </tr>
                        </thead>
                        <tbody>
                        <tr style="padding:40px 40px 0 40px;display:table-cell">
                            <td style="font-size:24px;line-height:1.5;color:#000;margin-top:40px">提现申请未通过</td>
                        </tr>
                        <tr>
                            <td style="font-size:14px;color:#333;padding:24px 40px 0 40px;line-height:1.8">
                                尊敬的用户您好！
                                <br /><br />
                                很抱歉，您的提现申请 <strong>#{{ $withdrawal_id }}</strong>（{{ $amount }} → {{ $chain }}）未能通过审核：
                                <div style="margin:14px 0;padding:12px 14px;background:#fffaf0;border-left:3px solid #b7791f;color:#7b4f0a">{{ $reason }}</div>
                                冻结的佣金 <strong>{{ $amount }}</strong> 已退回您的账户，您可以修改收款信息后重新申请。
                                <br /><br />
                                <span style="color:#999;font-size:12px">如有疑问，请回复站内工单联系我们。</span>
                            </td>
                        </tr>
                        <tr style="padding:40px;display:table-cell">
                        </tr>
                        </tbody>
                    </table>
                </div>
                <div>
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tbody>
                        <tr>
                            <td style="padding:20px 40px;font-size:12px;color:#999;line-height:20px;background:#f7f7f7"><a href="{{$url}}" style="font-size:14px;color:#929292">返回{{$name}}</a></td>
                        </tr>
                        </tbody>
                    </table>
                </div></td>
        </tr>
        </tbody>
    </table>
</div>
