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
                            <td style="font-size:24px;line-height:1.5;color:#000;margin-top:40px">✅ 佣金提现已完成</td>
                        </tr>
                        <tr>
                            <td style="font-size:14px;color:#333;padding:24px 40px 0 40px;line-height:1.8">
                                尊敬的用户您好！
                                <br /><br />
                                您的提现申请 <strong>#{{ $withdrawal_id }}</strong> 已于 {{ $settled_at }} 完成打款，请注意查收。
                                <table width="100%" cellpadding="0" cellspacing="0" style="margin:18px 0;border-collapse:collapse;font-size:14px">
                                    <tr><td style="padding:8px 10px;color:#666;width:96px;border-bottom:1px solid #eee">金额</td><td style="padding:8px 10px;border-bottom:1px solid #eee"><strong>{{ $amount }}</strong>@if(!empty($usdt))&nbsp;<span style="color:#666">（{{ $usdt_is_actual ? '实付' : '约' }} {{ $usdt }} USDT）</span>@endif</td></tr>
                                    <tr><td style="padding:8px 10px;color:#666;border-bottom:1px solid #eee">收款链</td><td style="padding:8px 10px;border-bottom:1px solid #eee">{{ $chain }}</td></tr>
                                    <tr><td style="padding:8px 10px;color:#666;border-bottom:1px solid #eee">收款地址</td><td style="padding:8px 10px;border-bottom:1px solid #eee;font-family:Menlo,Consolas,monospace;font-size:12px;word-break:break-all">{{ $address }}</td></tr>
                                    @if(!empty($txid))
                                    <tr><td style="padding:8px 10px;color:#666;border-bottom:1px solid #eee">交易哈希</td><td style="padding:8px 10px;border-bottom:1px solid #eee;font-family:Menlo,Consolas,monospace;font-size:12px;word-break:break-all">{{ $txid }}@if(!empty($explorer_url))<br /><a href="{{ $explorer_url }}" style="color:#2f855a">在区块浏览器查看 →</a>@endif</td></tr>
                                    @endif
                                </table>
                                {!! nl2br(e($thanks)) !!}
                                <br /><br />
                                <span style="color:#999;font-size:12px">链上到账通常需要几分钟到一小时；若长时间未到账，请回复站内工单联系我们。</span>
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
