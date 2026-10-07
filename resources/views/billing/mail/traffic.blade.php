@extends('billing.mail.layout')

@section('summary')
                    <!-- Usage -->
                    <tr>
                        <td style="padding:26px 44px 0 44px;">
                            <table width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color:#fbfaf7;border:1px solid #e5e0d8;border-radius:4px;">
                                <tr>
                                    <td style="padding:20px 22px 18px 22px;">
                                        <table width="100%" border="0" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td style="font-family:'Noto Serif SC',Georgia,'Songti SC',serif;font-size:18px;font-weight:600;color:#2a2520;line-height:1.4;">{{ $plan_name !== '' ? $plan_name : $app_name }}</td>
                                                <td align="right" valign="top" style="white-space:nowrap;padding-left:12px;"><span style="display:inline-block;font-family:'Noto Serif SC',Georgia,serif;font-size:11px;letter-spacing:2px;color:{{ $exhausted ? '#c94f2e' : '#716a65' }};border:1px solid {{ $exhausted ? '#c94f2e' : '#c5beb5' }};border-radius:2px;padding:3px 8px 3px 10px;">{{ $stamp }}</span></td>
                                            </tr>
                                        </table>
                                        <table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin-top:14px;">
                                            <tr>
                                                <td style="font-family:'Noto Sans SC',sans-serif;font-size:13px;color:#716a65;">{{ __('billing.traffic.used') }} {{ $used_fmt }} / {{ $quota_fmt }}</td>
                                                <td align="right" style="font-family:'SF Mono',SFMono-Regular,ui-monospace,Menlo,Consolas,monospace;font-size:13px;font-weight:600;color:{{ $exhausted ? '#c94f2e' : '#2a2520' }};">{{ $percent }}%</td>
                                            </tr>
                                        </table>
                                        <table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin-top:8px;">
                                            <tr>
                                                <td>
                                                    <div style="background-color:#e5e0d8;border-radius:3px;height:5px;overflow:hidden;">
                                                        <div style="background-color:#c94f2e;width:{{ max(2, min(100, $percent)) }}%;height:5px;border-radius:3px;"></div>
                                                    </div>
                                                </td>
                                            </tr>
                                        </table>
                                        <div style="font-family:'Noto Sans SC',sans-serif;font-size:12px;color:#69635e;line-height:1.7;margin-top:12px;">{{ __('billing.traffic.remaining') }} {{ $remaining_fmt }}@if($reset_date) · {{ __('billing.traffic.resets_on') }} {{ $reset_date }}@endif</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
@endsection

@section('after_cta')
                    @include('billing.mail.partials.alternatives')
                    <tr>
                        <td style="padding:24px 44px 0 44px;">
                            <div style="font-family:'Noto Sans SC',sans-serif;font-size:12px;color:#a09890;line-height:1.7;">{{ __('billing.traffic.note') }}</div>
                        </td>
                    </tr>
@endsection
