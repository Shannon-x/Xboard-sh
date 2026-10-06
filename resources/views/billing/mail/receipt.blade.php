@extends('billing.mail.layout')

@section('summary')
                    <!-- Receipt summary -->
                    <tr>
                        <td style="padding:26px 44px 0 44px;">
                            <table width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color:#fbfaf7;border:1px solid #e5e0d8;border-radius:4px;">
                                <tr>
                                    <td style="padding:20px 22px 0 22px;">
                                        <table width="100%" border="0" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td style="font-family:'Noto Serif SC',Georgia,'Songti SC',serif;font-size:18px;font-weight:600;color:#2a2520;line-height:1.4;">{{ $items[0]['name'] }}<span style="font-family:'Noto Sans SC',sans-serif;font-size:13px;font-weight:400;color:#a09890;"> · {{ $items[0]['period'] }}</span></td>
                                                <td align="right" valign="top" style="white-space:nowrap;padding-left:12px;"><span style="display:inline-block;font-family:'Noto Serif SC',Georgia,serif;font-size:11px;letter-spacing:2px;color:#c94f2e;border:1px solid #c94f2e;border-radius:2px;padding:3px 8px 3px 10px;">{{ $stamp }}</span></td>
                                            </tr>
                                        </table>
                                        @if($items[0]['details'])
                                        <div style="font-family:'Noto Sans SC',sans-serif;font-size:12px;color:#69635e;line-height:1.7;margin-top:6px;">{{ implode(' · ', $items[0]['details']) }}</div>
                                        @endif
                                        @if($items[0]['until'])
                                        <div style="font-family:'Noto Sans SC',sans-serif;font-size:12px;color:#69635e;line-height:1.7;">{{ __('billing.field.service_until') }} <span style="color:#2a2520;">{{ $items[0]['until'] }}</span></div>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:16px 22px 0 22px;">
                                        <div style="border-top:1px solid #e5e0d8;"></div>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:14px 22px 18px 22px;">
                                        <table width="100%" border="0" cellspacing="0" cellpadding="0">
                                            @foreach($totals as $row)
                                            @if(!$loop->first)
                                            <tr>
                                                <td style="font-family:'Noto Sans SC',sans-serif;font-size:13px;color:#69635e;line-height:1.9;">{{ $row['label'] }}</td>
                                                <td align="right" style="font-family:'Noto Sans SC',sans-serif;font-size:13px;color:#69635e;line-height:1.9;">{{ $row['amount_fmt'] }}</td>
                                            </tr>
                                            @endif
                                            @endforeach
                                            <tr>
                                                <td style="font-family:'Noto Serif SC',Georgia,serif;font-size:14px;color:#2a2520;line-height:2.2;padding-top:4px;">{{ __('billing.field.amount_paid') }}</td>
                                                <td align="right" style="font-family:'Noto Serif SC',Georgia,serif;font-size:24px;font-weight:700;color:#2a2520;line-height:1.3;padding-top:4px;">{{ $total_fmt }}</td>
                                            </tr>
                                            <tr>
                                                <td colspan="2" style="font-family:'Noto Sans SC',sans-serif;font-size:12px;color:#a09890;line-height:1.8;padding-top:6px;">{{ $payment_method }} · {{ $paid_at }}<br />{{ __('billing.field.trade_no') }} {{ $trade_no }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    @foreach($notes as $note)
                    <tr>
                        <td style="padding:16px 44px 0 44px;">
                            <table width="100%" border="0" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td style="border-left:3px solid #c94f2e;padding:14px 20px;background-color:#f0d9d1;border-radius:0 4px 4px 0;">
                                        <div style="font-family:'Noto Sans SC','PingFang SC','Microsoft YaHei',sans-serif;font-size:14px;color:#2a2520;line-height:1.75;">{{ $note }}</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    @endforeach
@endsection
