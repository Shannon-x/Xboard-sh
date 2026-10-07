@extends('billing.mail.layout')

@section('summary')
                    <tr>
                        <td style="padding:24px 44px 0 44px;">
                            <table width="100%" border="0" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td style="border-left:3px solid #c94f2e;padding:14px 20px;background-color:#f0d9d1;border-radius:0 4px 4px 0;">
                                        <div style="font-family:'Noto Sans SC','PingFang SC','Microsoft YaHei',sans-serif;font-size:14px;color:#2a2520;line-height:1.75;">{{ $note }}</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
@endsection
