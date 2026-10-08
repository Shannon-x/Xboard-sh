@php
/* 三套模板共用的文案：主题块 / 正文 / 说明 */
$title = $changing ? '确认新邮箱' : ($reminder ? '提醒：请验证您的邮箱' : '请验证您的邮箱');
if ($changing) {
    $intro = '您在 ' . $name . ' 申请将账户邮箱换成 ' . $email . '。点击下方按钮确认，确认后新邮箱立即生效，之前的邮箱不再收到任何通知。';
} elseif ($reminder) {
    $intro = '您在 ' . $name . ' 的账户邮箱还没有验证，宽限期将于 ' . $due_date . ' 结束。点一下下方按钮即可完成，之后收据、续费账单和服务通知都会发到这个邮箱。';
} else {
    $intro = '感谢使用 ' . $name . '。为了确保收据、续费账单和服务通知能送达，请点击下方按钮验证这个邮箱（' . $email . '）。';
}
if ($changing) {
    $consequence = null;
} elseif ($restrict_mode === 'subscribe') {
    $consequence = '如果在 ' . $due_date . ' 之前没有验证，账户将进入限制状态：订阅链接暂停更新，提交工单（支付问题除外）、佣金提现和佣金转余额也需要先验证邮箱。购买和续费不受影响。';
} elseif ($restrict_mode === 'features') {
    $consequence = '如果在 ' . $due_date . ' 之前没有验证，账户将进入限制状态：提交工单（支付问题除外）、佣金提现和佣金转余额前需要先验证邮箱。订阅、购买和续费都不受影响。';
} else {
    $consequence = '验证之后面板里的提示就会消失。';
}
$note = '链接 ' . $token_days . ' 天内有效、只能使用一次；邮件里的按钮打不开时，登录面板后可以重新发送。如果这不是您的操作，忽略这封邮件即可，账户不会有任何变化。';
@endphp
<div style="background: #eee">
    <table width="600" border="0" align="center" cellpadding="0" cellspacing="0">
        <tbody>
        <tr>
            <td>
                <div style="background:#fff">
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <thead>
                        <tr>
                            <td valign="middle" style="padding-left:30px;background-color:#415A94;color:#fff;padding:20px 40px;font-size: 21px;">{{$name}}</td>
                        </tr>
                        </thead>
                        <tbody>
                        <tr style="padding:40px 40px 0 40px;display:table-cell">
                            <td style="font-size:24px;line-height:1.5;color:#000;margin-top:40px">{{ $title }}</td>
                        </tr>
                        <tr>
                            <td style="font-size:14px;color:#333;padding:24px 40px 0 40px">
                                尊敬的用户您好！
                                <br />
                                <br />
                                {{ $intro }}
                                <br />
                                <br />
                                <a href="{{$link}}">{{$link}}</a>
                                @if ($consequence)
                                <br />
                                <br />
                                {{ $consequence }}
                                @endif
                                <br />
                                <br />
                                <span style="color:#999">{{ $note }}</span>
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
