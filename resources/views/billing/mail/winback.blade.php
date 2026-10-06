@extends('billing.mail.layout')

@section('summary')
                    @if($coupon)
                    <!-- Coupon -->
                    <tr>
                        <td style="padding:26px 44px 0 44px;">
                            <table width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color:#fbfaf7;border:1px dashed #c94f2e;border-radius:4px;">
                                <tr>
                                    <td style="padding:20px 22px;">
                                        <div style="font-family:'Noto Sans SC',sans-serif;font-size:11px;letter-spacing:2px;color:#a09890;">{{ __('billing.winback.coupon_code') }}</div>
                                        <div style="font-family:'Courier New',Menlo,monospace;font-size:26px;font-weight:700;color:#c94f2e;letter-spacing:3px;line-height:1.4;margin-top:4px;">{{ $coupon['code'] }}</div>
                                        <div style="font-family:'Noto Sans SC',sans-serif;font-size:14px;color:#2a2520;line-height:1.7;margin-top:6px;">{{ $coupon['desc'] }}@if($coupon['until']) · {{ __('billing.winback.coupon_until', ['date' => $coupon['until']]) }}@endif</div>
                                        <div style="font-family:'Noto Sans SC',sans-serif;font-size:12px;color:#a09890;line-height:1.7;margin-top:6px;">{{ __('billing.winback.coupon_hint') }}</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    @endif
@endsection

@section('after_cta')
                    @include('billing.mail.partials.alternatives')
                    <tr>
                        <td style="padding:24px 44px 0 44px;">
                            <div style="font-family:'Noto Sans SC',sans-serif;font-size:12px;color:#a09890;line-height:1.7;">{{ __('billing.winback.ignore') }}</div>
                        </td>
                    </tr>
@endsection
