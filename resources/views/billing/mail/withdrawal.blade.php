@extends('billing.mail.layout')

@section('summary')
                    <!-- Payout summary -->
                    <tr>
                        <td style="padding:26px 44px 0 44px;">
                            <table width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color:#fbfaf7;border:1px solid #e5e0d8;border-radius:4px;">
                                <tr>
                                    <td style="padding:20px 22px 0 22px;">
                                        <table width="100%" border="0" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td style="font-family:'Noto Sans SC',sans-serif;font-size:12px;letter-spacing:1px;color:#a09890;line-height:1.6;">{{ __('billing.withdrawal.field.amount') }}</td>
                                                <td align="right" valign="top" style="white-space:nowrap;padding-left:12px;"><span style="display:inline-block;font-family:'Noto Serif SC',Georgia,serif;font-size:11px;letter-spacing:2px;{{ $stamp_soft ? 'color:#716a65;border:1px solid #c5beb5;' : 'color:#c94f2e;border:1px solid #c94f2e;' }}border-radius:2px;padding:3px 8px 3px 10px;">{{ $stamp }}</span></td>
                                            </tr>
                                        </table>
                                        <div style="font-family:'Noto Serif SC',Georgia,'Songti SC',serif;font-size:30px;font-weight:700;color:{{ $outcome === 'completed' ? '#2a2520' : '#716a65' }};line-height:1.3;margin-top:2px;">{{ $total_fmt }}</div>
                                        @if($usdt)
                                        <div style="font-family:'Noto Sans SC',sans-serif;font-size:13px;color:#69635e;line-height:1.7;margin-top:4px;">{{ $usdt_is_actual ? __('billing.withdrawal.field.usdt_actual') : __('billing.withdrawal.field.usdt_estimated') }} {{ $usdt }} USDT{{ $usdt_fee ? ' · ' . __('billing.withdrawal.field.fee', ['fee' => $usdt_fee]) : '' }}{{ $usdt_rate ? ' · ' . __('billing.withdrawal.field.rate', ['rate' => $usdt_rate]) : '' }}</div>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:16px 22px 0 22px;">
                                        <div style="border-top:1px solid #e5e0d8;"></div>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 22px 16px 22px;">
                                        <table width="100%" border="0" cellspacing="0" cellpadding="0" style="font-family:'Noto Sans SC',sans-serif;font-size:13px;line-height:1.7;">
                                            <tr>
                                                <td style="padding:5px 0;color:#a09890;width:96px;vertical-align:top;">{{ __('billing.withdrawal.field.chain') }}</td>
                                                <td style="padding:5px 0;color:#2a2520;">{{ $chain }}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding:5px 0;color:#a09890;vertical-align:top;">{{ __('billing.withdrawal.field.address') }}</td>
                                                <td style="padding:5px 0;color:#2a2520;font-family:Menlo,Consolas,monospace;font-size:12px;word-break:break-all;">{{ $address }}</td>
                                            </tr>
                                            @if($txid)
                                            <tr>
                                                <td style="padding:5px 0;color:#a09890;vertical-align:top;">{{ __('billing.withdrawal.field.txid') }}</td>
                                                <td style="padding:5px 0;color:#2a2520;font-family:Menlo,Consolas,monospace;font-size:12px;word-break:break-all;">{{ $txid }}</td>
                                            </tr>
                                            @endif
                                            @if($reason)
                                            <tr>
                                                <td style="padding:5px 0;color:#a09890;vertical-align:top;">{{ __('billing.withdrawal.field.reason') }}</td>
                                                <td style="padding:5px 0;color:#c94f2e;">{{ $reason }}</td>
                                            </tr>
                                            @endif
                                            <tr>
                                                <td style="padding:5px 0;color:#a09890;vertical-align:top;">{{ __('billing.withdrawal.field.settled_at') }}</td>
                                                <td style="padding:5px 0;color:#2a2520;">{{ $settled_at }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    @if($outcome === 'completed' && $thanks)
                    <tr>
                        <td style="padding:16px 44px 0 44px;">
                            <table width="100%" border="0" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td style="border-left:3px solid #c94f2e;padding:14px 20px;background-color:#f0d9d1;border-radius:0 4px 4px 0;">
                                        <div style="font-family:'Noto Sans SC','PingFang SC','Microsoft YaHei',sans-serif;font-size:14px;color:#2a2520;line-height:1.75;">{!! nl2br(e($thanks)) !!}</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    @endif
@endsection

@section('after_cta')
                    @if($secondary_url)
                    <tr>
                        <td style="padding:14px 44px 0 44px;">
                            <a href="{{ $secondary_url }}" target="_blank" style="font-family:'Noto Sans SC',sans-serif;font-size:13px;color:#716a65;text-decoration:underline;">{{ $secondary_label }}</a>
                        </td>
                    </tr>
                    @endif
                    @if($outcome === 'completed')
                    <tr>
                        <td style="padding:24px 44px 0 44px;">
                            <div style="font-family:'Noto Sans SC',sans-serif;font-size:12px;color:#a09890;line-height:1.7;">{{ __('billing.withdrawal.not_received') }}</div>
                        </td>
                    </tr>
                    @endif
@endsection
