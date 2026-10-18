<div style="background: #eee">
    <table width="600" border="0" align="center" cellpadding="0" cellspacing="0">
        <tbody>
        <tr>
            <td>
                <div style="background:#fff">
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <thead>
                        <tr>
                            <td valign="middle" style="padding-left:30px;background-color:#2f855a;color:#fff;padding:20px 40px;font-size: 21px;">{{$name}}</td>
                        </tr>
                        </thead>
                        <tbody>
                        <tr style="padding:40px 40px 0 40px;display:table-cell">
                            <td style="font-size:24px;line-height:1.5;color:#000;margin-top:40px">💰 佣金入账</td>
                        </tr>
                        <tr>
                            <td style="font-size:14px;color:#333;padding:24px 40px 0 40px;line-height:1.8">
                                尊敬的用户您好！
                                <br /><br />
                                本轮共 <strong>{{ $count }}</strong> 笔佣金入账，合计 <strong>{{ $total }}</strong>，已计入你的佣金余额。@if(!empty($balance))当前可用佣金 {{ $balance }}。@endif
                                <table width="100%" cellpadding="0" cellspacing="0" style="margin:18px 0;border-collapse:collapse;font-size:14px">
                                    <tr>
                                        <td style="padding:8px 10px;color:#666;border-bottom:1px solid #eee">被邀请人</td>
                                        <td style="padding:8px 10px;color:#666;border-bottom:1px solid #eee;text-align:right">金额</td>
                                        <td style="padding:8px 10px;color:#666;border-bottom:1px solid #eee;text-align:right">时间</td>
                                    </tr>
                                    @foreach($items as $item)
                                    <tr>
                                        <td style="padding:8px 10px;border-bottom:1px solid #eee">{{ $item['email'] }}@if(($item['level'] ?? 1) > 1) <span style="color:#666;font-size:12px">· 第 {{ $item['level'] }} 级</span>@endif</td>
                                        <td style="padding:8px 10px;border-bottom:1px solid #eee;text-align:right;white-space:nowrap"><strong>{{ $item['amount'] }}</strong></td>
                                        <td style="padding:8px 10px;border-bottom:1px solid #eee;text-align:right;white-space:nowrap;color:#666;font-size:13px">{{ $item['time'] }}</td>
                                    </tr>
                                    @endforeach
                                </table>
                                <a href="{{ $invite_url ?? $url }}" style="color:#fff;text-decoration:none;font-weight:bold;display:inline-block;border-radius:5px;background-color:#2f855a;padding:8px 20px">查看佣金</a>
                                <br /><br />
                                <span style="color:#999;font-size:12px">佣金可在邀请页提现或划转到账户余额。</span>
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
                            <td style="padding:20px 40px;font-size:12px;color:#999;line-height:20px;background:#f7f7f7"><a href="{{$url}}" style="font-size:14px;color:#929292">返回{{$name}}</a><br />不希望收到此类邮件？<a href="{{ $manage_url ?? ($url . '#/profile') }}" style="color:#999;text-decoration:underline">{{ $manage_label ?? '管理通知偏好' }}</a></td>
                        </tr>
                        </tbody>
                    </table>
                </div></td>
        </tr>
        </tbody>
    </table>
</div>
