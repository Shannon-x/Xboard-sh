@extends('billing.mail.layout')

@section('summary')
                    <!-- Paused service -->
                    <tr>
                        <td style="padding:26px 44px 0 44px;">
                            <table width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color:#fbfaf7;border:1px solid #e5e0d8;border-radius:4px;">
                                <tr>
                                    <td style="padding:20px 22px 18px 22px;">
                                        <table width="100%" border="0" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td style="font-family:'Noto Serif SC',Georgia,'Songti SC',serif;font-size:18px;font-weight:600;color:#2a2520;line-height:1.4;">{{ $plan_name }}</td>
                                                <td align="right" valign="top" style="white-space:nowrap;padding-left:12px;"><span style="display:inline-block;font-family:'Noto Serif SC',Georgia,serif;font-size:11px;letter-spacing:2px;color:#c94f2e;border:1px solid #c94f2e;border-radius:2px;padding:3px 8px 3px 10px;">{{ $stamp }}</span></td>
                                            </tr>
                                        </table>
                                        <div style="font-family:'Noto Sans SC',sans-serif;font-size:12px;color:#69635e;line-height:1.7;margin-top:6px;">{{ __('billing.field.expires_at') }} {{ $expired_date }}@if($details) · {{ implode(' · ', $details) }}@endif</div>
                                        @if($available)
                                        <div style="border-top:1px solid #e5e0d8;margin-top:16px;"></div>
                                        <table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin-top:12px;">
                                            <tr>
                                                <td style="font-family:'Noto Serif SC',Georgia,serif;font-size:14px;color:#2a2520;line-height:2.2;">{{ __('billing.expired.renew_amount') }}<span style="font-family:'Noto Sans SC',sans-serif;font-size:12px;color:#a09890;"> · {{ $period }}</span></td>
                                                <td align="right" style="font-family:'Noto Serif SC',Georgia,serif;font-size:24px;font-weight:700;color:#c94f2e;line-height:1.3;">{{ $total_fmt }}</td>
                                            </tr>
                                            <tr>
                                                <td colspan="2" style="font-family:'Noto Sans SC',sans-serif;font-size:12px;color:#a09890;line-height:1.8;padding-top:6px;">{{ __('billing.expired.restore_note') }}</td>
                                            </tr>
                                        </table>
                                        @endif
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
@endsection

@section('after_cta')
                    @if($invoice_url)
                    <tr>
                        <td style="padding:16px 44px 0 44px;">
                            <a href="{{ $invoice_url }}" target="_blank" style="font-family:'Noto Sans SC',sans-serif;font-size:13px;color:#716a65;text-decoration:underline;">{{ __('billing.expired.invoice_link') }}</a>
                        </td>
                    </tr>
                    @endif
                    @include('billing.mail.partials.alternatives')
                    <tr>
                        <td style="padding:24px 44px 0 44px;">
                            <div style="font-family:'Noto Sans SC',sans-serif;font-size:12px;color:#a09890;line-height:1.7;">{{ __('billing.invoice.ignore') }}</div>
                        </td>
                    </tr>
@endsection
